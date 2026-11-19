<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MailArchiveBackup
{
    public function create(): string
    {
        $directory = storage_path('app/private/correo-respaldos/'.date('Ymd-His').'-'.Str::uuid());
        if (!mkdir($directory.'/files', 0700, true)) throw new \RuntimeException('No se pudo crear el respaldo privado.');
        try {
            $data = DB::transaction(function () {
                $dispatches = DB::table('correo_envios')->where(app(MailCampaigns::class)->scope())->get()->map(fn ($r) => (array)$r)->all();
                $ids = array_column($dispatches, 'id');
                $links = DB::table('correo_envio_adjuntos')->whereIn('envio_id', $ids)->get()->map(fn ($r) => (array)$r)->all();
                $documents = DB::table('correo_adjuntos')->whereIn('id', array_column($links, 'adjunto_id'))->get()->map(fn ($r) => (array)$r)->all();
                return ['version' => 1, 'scope' => app(MailCampaigns::class)->scope(), 'dispatches' => $dispatches, 'links' => $links, 'documents' => $documents];
            });
            foreach ($data['documents'] as $document) {
                $source = app(MailArchive::class)->file(new \App\Models\MailDocument($document));
                $target = $directory.'/files/'.$document['id'].'.bin';
                if (!copy($source, $target)) throw new \RuntimeException('No se pudo respaldar un documento.');
                chmod($target, 0600);
            }
            file_put_contents($directory.'/archive.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($directory.'/archive.json', 0600);
            $this->protect($directory);
            $this->verify($directory);
            return $directory;
        } catch (\Throwable $error) { throw new \RuntimeException('Respaldo incompleto. Se conservó para revisión privada.', 0, $error); }
    }

    public function verify(string $directory): array
    {
        $directory = realpath($directory);
        if (!$directory || !is_file($directory.'/archive.json') || filesize($directory.'/archive.json') > 32 * 1024 * 1024) throw new \RuntimeException('Respaldo no válido.');
        $data = json_decode(file_get_contents($directory.'/archive.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null) !== 1 || ($data['scope'] ?? null) !== app(MailCampaigns::class)->scope()) throw new \RuntimeException('El respaldo pertenece a otro modo o conjunto de datos.');
        foreach (['dispatches', 'links', 'documents'] as $key) if (!is_array($data[$key] ?? null)) throw new \RuntimeException('Manifiesto incompleto.');
        $ids = array_fill_keys(array_column($data['dispatches'], 'id'), true);
        $docs = array_fill_keys(array_column($data['documents'], 'id'), true);
        foreach ($data['dispatches'] as $row) {
            if (!Str::isUuid($row['id']) || $row['origen'] !== $data['scope']['origen'] || $row['dataset'] !== $data['scope']['dataset']) throw new \RuntimeException('Envío ajeno al respaldo.');
        }
        foreach ($data['links'] as $link) if (!isset($ids[$link['envio_id']], $docs[$link['adjunto_id']])) throw new \RuntimeException('Referencia documental incompleta.');
        foreach ($data['documents'] as $document) {
            if (!Str::isUuid($document['id']) || !preg_match('/^[a-f0-9-]{36}\/[a-f0-9-]{36}\.bin$/D', $document['ruta'])) throw new \RuntimeException('Ruta documental inválida.');
            $file = realpath($directory.'/files/'.$document['id'].'.bin');
            if (!$file || !str_starts_with($file, $directory.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR)
                || filesize($file) !== (int)$document['bytes'] || !hash_equals($document['sha256'], hash_file('sha256', $file))) throw new \RuntimeException('El documento no coincide con su respaldo.');
        }
        return $data;
    }

    public function restore(string $directory): void
    {
        $data = $this->verify($directory); $created = [];
        try {
            DB::transaction(function () use ($directory, $data, &$created) {
                foreach ($data['dispatches'] as $row) {
                    if ($row['modulo'] === 'campana' && !DB::table('campanas_correo')->where('id', $row['campana_id'])
                        ->where('origen', $row['origen'])->where('dataset', $row['dataset'])->where('request_hash', $row['request_hash'])->exists()) {
                        throw new \RuntimeException('Recupera primero la base de campañas correspondiente al respaldo.');
                    }
                    $existing = DB::table('correo_envios')->where('id', $row['id'])->lockForUpdate()->first();
                    if ($existing) {
                        foreach (['origen', 'dataset', 'actor_kind', 'actor_issuer', 'actor_subject', 'request_key', 'request_hash'] as $key) {
                            if ($existing->$key !== $row[$key]) throw new \RuntimeException('Hay un envío diferente con el mismo identificador.');
                        }
                    } else DB::table('correo_envios')->insert($row);
                }
                foreach ($data['documents'] as $row) {
                    $existing = DB::table('correo_adjuntos')->where('id', $row['id'])->first();
                    if ($existing && ($existing->sha256 !== $row['sha256'] || $existing->ruta !== $row['ruta'])) throw new \RuntimeException('Hay otro documento con el mismo identificador.');
                    $disk = Storage::disk('mail_private');
                    if ($disk->exists($row['ruta'])) {
                        if (!hash_equals($row['sha256'], hash_file('sha256', $disk->path($row['ruta'])))) throw new \RuntimeException('Se conservó un archivo local diferente.');
                    } else {
                        $stream = fopen($directory.'/files/'.$row['id'].'.bin', 'rb');
                        try { if (!$disk->put($row['ruta'], $stream)) throw new \RuntimeException('No se pudo recuperar el archivo.'); }
                        finally { fclose($stream); }
                        $created[] = $row['ruta'];
                    }
                    if (!$existing) DB::table('correo_adjuntos')->insert($row);
                }
                foreach ($data['links'] as $row) DB::table('correo_envio_adjuntos')->insertOrIgnore($row);
            });
        } catch (\Throwable $error) {
            foreach ($created as $path) { try { Storage::disk('mail_private')->delete($path); } catch (\Throwable) {} }
            throw $error;
        }
    }

    private function protect(string $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') return;
        $identity = new \Symfony\Component\Process\Process(['whoami', '/user', '/fo', 'csv', '/nh']);
        $identity->mustRun(); $sid = str_getcsv(trim($identity->getOutput()))[1] ?? '';
        if (!preg_match('/^S-1-[0-9-]+$/D', $sid)) throw new \RuntimeException('No se pudo proteger el respaldo.');
        (new \Symfony\Component\Process\Process(['icacls', $directory, '/inheritance:r', '/grant:r', '*'.$sid.':(OI)(CI)(F)', '*S-1-5-18:(OI)(CI)(F)']))->mustRun();
    }

    public function cleanup(): int
    {
        $directory = realpath(storage_path('framework/cache/mail-attachments'));
        if (!$directory) return 0;
        if (dirname($directory) !== realpath(storage_path('framework/cache'))) throw new \RuntimeException('Ruta temporal inesperada.');
        $count = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && !$file->isLink() && $file->getMTime() < time() - 86400 && unlink($file->getPathname())) $count++;
        }
        return $count;
    }
}
