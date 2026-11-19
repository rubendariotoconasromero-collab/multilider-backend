<?php

namespace App\Services;

use App\Auth\RemotePrincipal;
use App\Models\CampanaCorreo;
use App\Models\MailDispatch;
use App\Models\MailDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MailArchive
{
    public function owner(Request $request): array
    {
        $user = $request->user();
        return $user instanceof RemotePrincipal
            ? ['actor_kind' => 'remote', 'actor_issuer' => $user->claims['iss'], 'actor_subject' => (string)$user->claims['sub']]
            : ['actor_kind' => 'local', 'actor_issuer' => '', 'actor_subject' => (string)$user->id];
    }

    public function visible(Request $request)
    {
        $reports = collect(\App\Architecture\AggregateRoutes::REPORTS)->keys()->filter(fn ($report) => \App\Architecture\AggregateRoutes::canReport($request, $report))->all();
        $exact = app(MailCampaigns::class)->scope() + $this->owner($request);
        $query = MailDispatch::where($exact);
        // MySQL's default collation folds case and trailing spaces; identity does not.
        foreach (['origen', 'dataset', 'actor_kind', 'actor_issuer', 'actor_subject'] as $field) {
            $query->whereRaw('CAST(`'.$field.'` AS BINARY) = CAST(? AS BINARY)', [$exact[$field]]);
        }
        return $query
            ->where(function ($query) use ($request, $reports) {
                $query->where(fn ($q) => $q->where('modulo', 'reporte')->whereIn('recurso', $reports));
                if ($request->user()->hasPermission('acceso_correo_masivo')) $query->orWhere('modulo', 'campana');
            });
    }

    public function report(Request $request, string $report, array $users, callable $generate, ?string $title = null): MailDispatch
    {
        $key = $request->input('request_key') ?: (string) Str::uuid();
        $scope = app(MailCampaigns::class)->scope() + ['modulo' => 'reporte', 'request_key' => $key];
        $recipients = collect($users)->map(fn ($user) => ['correo' => MailAddresses::resolve($user->correo), 'nombre' => $user->nombre, 'estado' => 'pendiente', 'message_id' => null])->unique('correo')->sortBy('correo')->values()->all();
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($normalize, $value);
        };
        $hash = hash('sha256', json_encode([app(MailCampaigns::class)->scope(), $this->owner($request), $report, $normalize($request->input('params', [])), $recipients], JSON_THROW_ON_ERROR));
        $path = null;
        try {
            return DB::transaction(function () use ($request, $report, $title, $scope, $recipients, $hash, $generate, &$path) {
                $dispatch = MailDispatch::query()->createOrFirst($scope, $this->owner($request) + [
                    'id' => (string)Str::uuid(), 'actor_name' => $request->user()->nombre, 'recurso' => $report,
                    'request_hash' => $hash, 'remitente' => config('mail.from.address'), 'asunto' => 'Informe: '.($title ?? $report), 'destinatarios' => $recipients,
                ]);
                abort_unless(hash_equals($dispatch->request_hash, $hash), 409, 'La solicitud de envío ya fue utilizada con otros datos.');
                if (!$dispatch->wasRecentlyCreated) return $dispatch;
                [$pdf, $filename] = $generate();
                abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF'), 422, 'No se pudo preparar el documento.');
                $id = (string)Str::uuid();
                $path = $dispatch->id.'/'.$id.'.bin';
                if (!Storage::disk('mail_private')->put($path, $pdf)) throw new \RuntimeException('No se pudo conservar el documento privado.');
                $document = MailDocument::create(['id' => $id, 'nombre' => MailDocumentContent::name($filename), 'mime' => 'application/pdf',
                    'bytes' => strlen($pdf), 'sha256' => hash('sha256', $pdf), 'ruta' => $path]);
                $dispatch->documents()->attach($document->id);
                return $dispatch;
            }, 1);
        } catch (\Throwable $error) {
            if ($path) { try { Storage::disk('mail_private')->delete($path); } catch (\Throwable) {} }
            throw $error;
        }
    }

    public function campaign(CampanaCorreo $campaign): MailDispatch
    {
        return MailDispatch::query()->firstOrCreate(['origen' => $campaign->origen, 'dataset' => $campaign->dataset,
            'modulo' => 'campana', 'request_key' => $campaign->request_key], [
            'id' => (string)Str::uuid(), 'actor_kind' => $campaign->actor_subject !== null ? 'remote' : 'local',
            'actor_issuer' => $campaign->actor_issuer ?? '', 'actor_subject' => (string)($campaign->actor_subject ?? $campaign->user_id),
            'actor_name' => $campaign->actor_name ?? $campaign->usuario?->nombre ?? 'Usuario',
            'campana_id' => $campaign->id, 'request_hash' => $campaign->request_hash, 'remitente' => config('mail.from.address'),
            'asunto' => $campaign->asunto, 'mensaje' => $campaign->mensaje, 'destinatarios' => [],
        ]);
    }

    public function transition(string $id, string $email, string $from, string $to, ?string $messageId = null): bool
    {
        return DB::transaction(function () use ($id, $email, $from, $to, $messageId) {
            $dispatch = MailDispatch::lockForUpdate()->findOrFail($id);
            $rows = $dispatch->destinatarios;
            foreach ($rows as &$row) {
                if ($row['correo'] === $email && $row['estado'] === $from) {
                    $row['estado'] = $to; $row['message_id'] = $messageId;
                    $dispatch->update(['destinatarios' => $rows, 'estado' => self::deliveryState(array_count_values(array_column($rows, 'estado')))]);
                    return true;
                }
            }
            return false;
        });
    }

    public static function deliveryState(array $states): string
    {
        $total = array_sum($states);
        $sent = ($states['aceptado_smtp'] ?? 0) + ($states['enviado'] ?? 0);
        return ($states['incierto'] ?? 0) + ($states['enviando'] ?? 0) > 0 ? 'incierto'
            : (($states['pendiente'] ?? 0) > 0 ? 'pendiente' : ($sent === $total && $total > 0 ? 'aceptado_smtp' : 'fallido'));
    }

    public function filterState($query, string $state): void
    {
        $query->where(function ($q) use ($state) {
            $q->where(fn ($q) => $q->where('modulo', 'reporte')->where('estado', $state))
                ->orWhere(function ($q) use ($state) {
                    $q->where('modulo', 'campana');
                    $exists = function ($query, ?array $states, bool $negate = false) {
                        $method = $negate ? 'whereNotExists' : 'whereExists';
                        $query->$method(function ($recipient) use ($states) {
                            $recipient->selectRaw('1')->from('campana_destinatarios')->whereColumn('campana_id', 'correo_envios.campana_id');
                            $recipient->whereExists(fn ($c) => $c->selectRaw('1')->from('campanas_correo')
                                ->whereColumn('campanas_correo.id', 'correo_envios.campana_id')->whereColumn('campanas_correo.origen', 'correo_envios.origen')
                                ->whereColumn('campanas_correo.dataset', 'correo_envios.dataset')->whereColumn('campanas_correo.request_hash', 'correo_envios.request_hash')
                                ->whereRaw('CAST(campanas_correo.origen AS BINARY) = CAST(correo_envios.origen AS BINARY)')
                                ->whereRaw('CAST(campanas_correo.dataset AS BINARY) = CAST(correo_envios.dataset AS BINARY)'));
                            if ($states !== null) $recipient->whereIn('estado', $states);
                        });
                    };
                    if ($state === 'incierto') $exists($q, ['incierto', 'enviando']);
                    else {
                        $exists($q, ['incierto', 'enviando'], true);
                        if ($state === 'pendiente') $exists($q, ['pendiente']);
                        else {
                            $exists($q, ['pendiente'], true);
                            if ($state === 'aceptado_smtp') { $exists($q, null); $exists($q, ['fallido'], true); }
                            else $q->where(function ($q) use ($exists) { $q->where(fn ($q) => $exists($q, ['fallido']))->orWhere(fn ($q) => $exists($q, null, true)); });
                        }
                    }
                });
        });
    }

    public function describe(MailDispatch $dispatch, bool $detail = false, int $recipientPage = 1): array
    {
        $campaign = $dispatch->modulo === 'campana';
        $recipientQuery = DB::table('campana_destinatarios')->where('campana_id', $dispatch->campana_id)->whereExists(fn ($q) => $q->selectRaw('1')->from('campanas_correo')
            ->where('campanas_correo.id', $dispatch->campana_id)->where('campanas_correo.origen', $dispatch->origen)
            ->where('campanas_correo.dataset', $dispatch->dataset)->where('campanas_correo.request_hash', $dispatch->request_hash)
            ->whereRaw('CAST(origen AS BINARY) = CAST(? AS BINARY)', [$dispatch->origen])->whereRaw('CAST(dataset AS BINARY) = CAST(? AS BINARY)', [$dispatch->dataset]));
        $states = $campaign ? (clone $recipientQuery)->select('estado', DB::raw('COUNT(*) AS total'))->groupBy('estado')->pluck('total', 'estado')->map(fn ($n) => (int)$n)->all()
            : array_count_values(array_column($dispatch->destinatarios, 'estado'));
        $total = array_sum($states);
        $sent = ($states['aceptado_smtp'] ?? 0) + ($states['enviado'] ?? 0);
        $state = self::deliveryState($states);
        $result = ['id' => $dispatch->id, 'modulo' => $dispatch->modulo, 'recurso' => $dispatch->recurso,
            'asunto' => $dispatch->asunto, 'remitente' => $dispatch->remitente, 'fecha' => $dispatch->created_at->toIso8601String(),
            'estado' => $state, 'total' => $total, 'aceptados' => $sent, 'inciertos' => ($states['incierto'] ?? 0) + ($states['enviando'] ?? 0),
            'adjuntos' => $dispatch->documents->map(fn ($file) => ['id' => $file->id, 'nombre' => $file->nombre, 'mime' => $file->mime,
                'bytes' => $file->bytes, 'sha256' => $file->sha256] + app(MailDocumentContent::class)->capabilities($file->nombre, $file->mime, $file->bytes))->all()];
        if ($detail) {
            $recipientPage = min($recipientPage, max(1, (int)ceil($total / 100)));
            $recipients = $campaign ? (clone $recipientQuery)->orderBy('id')->offset(($recipientPage - 1) * 100)->limit(100)->get(['nombre', 'correo', 'estado'])->map(fn ($r) => (array)$r)->all()
                : array_slice($dispatch->destinatarios, ($recipientPage - 1) * 100, 100);
            $result += ['destinatarios' => $recipients, 'mensaje' => $dispatch->mensaje, 'campana_id' => $dispatch->campana_id,
                'destinatarios_page' => $recipientPage, 'destinatarios_last_page' => max(1, (int)ceil($total / 100))];
        }
        return $result;
    }

    public function file(MailDocument $document): string
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}\/[a-f0-9-]{36}\.bin$/D', $document->ruta), 404);
        $path = Storage::disk('mail_private')->path($document->ruta);
        clearstatcache(true, $path);
        abort_unless(is_file($path), 404, 'El archivo ya no está disponible.');
        abort_unless(filesize($path) === $document->bytes && hash_equals($document->sha256, hash_file('sha256', $path)), 409, 'La copia del documento no coincide con su registro.');
        return $path;
    }
}
