<?php

namespace App\Services;

final class MailDocumentContent
{
    public static function name(string $name): string
    {
        $name = basename(str_replace('\\', '/', preg_replace('/[\x00-\x1F\x7F]/u', '', mb_convert_encoding($name, 'UTF-8', 'UTF-8'))));
        return mb_substr(trim($name), 0, 180) ?: 'archivo';
    }

    public function capabilities(string $name, string $mime, int $bytes): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = match (true) {
            $mime === 'application/pdf' => 'pdf',
            in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) => 'image',
            $ext === 'xlsx' && in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'], true) => 'table',
            $ext === 'csv' && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true) => 'table',
            $ext === 'txt' && $mime === 'text/plain' => 'text',
            default => null,
        };
        return ['preview_type' => $kind, 'preview_available' => $kind !== null && $bytes <= config('mail_documents.preview_bytes')];
    }

    public function describe(string $path, string $name): array
    {
        abort_unless(is_file($path), 404, 'El archivo ya no está disponible.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        return ['nombre' => self::name($name), 'mime' => $mime, 'bytes' => filesize($path)] + $this->capabilities($name, $mime, filesize($path));
    }

    public function response(string $path, string $name, bool $inline = false, bool $temporary = false)
    {
        $meta = $this->describe($path, $name);
        abort_if($meta['bytes'] > config('mail_documents.download_bytes'), 413, 'El archivo supera el límite de descarga.');
        if ($inline) abort_unless($meta['preview_available'] && in_array($meta['preview_type'], ['pdf', 'image'], true), 422, 'Utiliza la descarga para este formato.');
        $headers = ['Content-Type' => $meta['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Length' => $meta['bytes']];
        $disposition = $inline ? 'inline' : 'attachment';
        $headers['Content-Disposition'] = \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition($disposition, $meta['nombre'], 'archivo.'.(preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($meta['nombre'], PATHINFO_EXTENSION))) ?: 'bin'));
        return response()->stream(function () use ($path, $temporary) {
            try { $stream = fopen($path, 'rb'); try { fpassthru($stream); } finally { fclose($stream); } }
            finally { if ($temporary) @unlink($path); }
        }, 200, $headers);
    }

    public function preview(string $path, string $name, int $page = 1, int $sheet = 0): array
    {
        $meta = $this->describe($path, $name);
        abort_unless($meta['preview_available'], 422, 'Este archivo no admite vista previa. Puedes descargar el original.');
        if ($meta['preview_type'] === 'text') {
            $text = file_get_contents($path, false, null, 0, 200001);
            return $meta + ['text' => $this->text(substr($text, 0, 200000)), 'truncated' => strlen($text) > 200000];
        }
        abort_unless($meta['preview_type'] === 'table', 422, 'Abre el visor del documento.');
        $rows = (int)config('mail_documents.rows');
        $offset = ($page - 1) * $rows;
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') return $meta + $this->xlsx($path, $offset, $rows, $sheet);
        $fp = fopen($path, 'rb');
        try {
            $first = fgets($fp) ?: ''; rewind($fp);
            $separators = [',', ';', "\t"];
            usort($separators, fn ($a, $b) => count(str_getcsv($first, $b, '"', '')) <=> count(str_getcsv($first, $a, '"', '')));
            $result = []; $index = 0; $more = false; $outputBytes = 0;
            while (($row = fgetcsv($fp, 0, $separators[0], '"', '')) !== false) {
                if ($index++ < $offset) continue;
                if (count($result) >= $rows) { $more = true; break; }
                $result[] = array_map(function ($cell) use (&$outputBytes) {
                    $value = mb_substr($this->text((string)$cell), 0, 2000); $outputBytes += strlen($value);
                    return $outputBytes > config('mail_documents.output_bytes') ? '[Contenido reducido]' : $value;
                }, array_slice($row, 0, config('mail_documents.columns')));
            }
            return $meta + ['rows' => $result, 'has_more' => $more, 'sheets' => ['Datos'], 'sheet' => 0];
        } finally { fclose($fp); }
    }

    private function text(string $text): string
    {
        return ltrim(mb_convert_encoding($text, 'UTF-8', mb_check_encoding($text, 'UTF-8') ? 'UTF-8' : 'Windows-1252'), "\xEF\xBB\xBF");
    }

    private function xml(string $content): \XMLReader
    {
        abort_if(str_contains($content, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $content), 422, 'No se admite el contenido de esta hoja.');
        $reader = new \XMLReader;
        $previous = libxml_use_internal_errors(true);
        try { $ok = $reader->XML($content, null, LIBXML_NONET | LIBXML_COMPACT); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        abort_unless($ok, 422, 'No se pudo abrir la hoja.');
        $reader->setParserProperty(\XMLReader::LOADDTD, false);
        $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
        return $reader;
    }

    private function xlsx(string $path, int $offset, int $limit, int $sheet): array
    {
        $zip = new \ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'La hoja está protegida o no es válida. Descarga el original.');
        $xmlErrors = libxml_use_internal_errors(true);
        try {
            abort_if($zip->numFiles > config('mail_documents.zip_entries'), 422, 'La hoja supera el límite de vista previa.');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $total += $stat['size'];
                abort_if($total > config('mail_documents.zip_bytes') || $stat['size'] > config('mail_documents.preview_bytes'), 422, 'La hoja supera el límite de vista previa.');
            }
            $read = function (string $entry) use ($zip): string {
                $value = $zip->getFromName($entry);
                abort_if($value === false, 422, 'La estructura de la hoja no es válida.');
                return $value;
            };
            $relations = []; $r = $this->xml($read('xl/_rels/workbook.xml.rels'));
            while ($r->read()) if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'Relationship') {
                if ($r->getAttribute('TargetMode') === 'External') continue;
                $target = $r->getAttribute('Target') ?? '';
                if (preg_match('~^(?:/xl/)?worksheets/[a-zA-Z0-9_.-]+\.xml$~D', $target)) $relations[$r->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
            $r->close(); $sheets = []; $paths = []; $r = $this->xml($read('xl/workbook.xml'));
            while ($r->read()) if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'sheet') {
                $id = $r->getAttribute('r:id');
                if (isset($relations[$id])) { $sheets[] = mb_substr($r->getAttribute('name') ?? 'Hoja', 0, 100); $paths[] = $relations[$id]; }
                abort_if(count($sheets) > 50, 422, 'El libro tiene demasiadas hojas para la vista previa.');
            }
            $r->close(); abort_unless(isset($paths[$sheet]), 422, 'Hoja no disponible.');
            $strings = []; $stringBytes = 0;
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $r = $this->xml($read('xl/sharedStrings.xml'));
                while ($r->read()) if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                    $value = mb_substr($r->readString(), 0, 6000); $stringBytes += strlen($value); $strings[] = $value;
                    abort_if(count($strings) > 20000 || $stringBytes > 6 * 1024 * 1024, 422, 'La hoja supera el límite de vista previa.');
                }
                $r->close();
            }
            $result = []; $row = 0; $more = false; $outputBytes = 0; $r = $this->xml($read($paths[$sheet]));
            while ($r->read()) {
                if ($r->nodeType !== \XMLReader::ELEMENT) continue;
                if ($r->localName === 'row') {
                    $row = (int)($r->getAttribute('r') ?: $row + 1);
                    if ($row > $offset + $limit) { $more = true; break; }
                }
                if ($r->localName !== 'c' || $row <= $offset) continue;
                $reference = $r->getAttribute('r') ?? '';
                if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/D', $reference, $matches)) continue;
                $column = 0; foreach (str_split($matches[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                if ($column > config('mail_documents.columns')) continue;
                $type = $r->getAttribute('t'); $cell = $this->xml($r->readOuterXml()); $value = ''; $formula = '';
                while ($cell->read()) if ($cell->nodeType === \XMLReader::ELEMENT) {
                    if ($cell->localName === 'v') $value = $cell->readString();
                    elseif ($cell->localName === 't') $value .= $cell->readString();
                    elseif ($cell->localName === 'f') $formula = $cell->readString();
                }
                $cell->close();
                if ($type === 's') $value = $strings[(int)$value] ?? '[Valor fuera del límite de vista previa]';
                elseif ($value === '' && $formula !== '') $value = '='.$formula;
                $value = mb_substr($value, 0, 2000); $outputBytes += strlen($value);
                $result[$row - $offset - 1][$column - 1] = $outputBytes > config('mail_documents.output_bytes') ? '[Contenido reducido]' : $value;
            }
            $r->close();
            $width = $result ? max(array_map(fn ($line) => max(array_keys($line)) + 1, $result)) : 0;
            $lines = [];
            for ($i = 0; $i < ($result ? max(array_keys($result)) + 1 : 0); $i++) {
                $line = []; for ($c = 0; $c < $width; $c++) $line[] = $result[$i][$c] ?? '';
                $lines[] = $line;
            }
            return ['rows' => $lines, 'has_more' => $more, 'sheets' => $sheets, 'sheet' => $sheet,
                'notice' => 'Vista de valores guardados. Las fórmulas no se calculan; descarga el original para conservar su formato.'];
        } finally { $zip->close(); libxml_clear_errors(); libxml_use_internal_errors($xmlErrors); }
    }
}
