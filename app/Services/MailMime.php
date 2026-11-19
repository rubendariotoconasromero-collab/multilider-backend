<?php

namespace App\Services;

final class MailMime
{
    public static function filename(object $part): ?string
    {
        $params = [];
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $parameter) $params[strtolower($parameter->attribute)] = $parameter->value;
        foreach (['filename', 'name'] as $key) {
            $raw = $params[$key.'*'] ?? null;
            if ($raw === null && (isset($params[$key.'*0*']) || isset($params[$key.'*0']))) {
                $raw = ''; $charset = null;
                for ($i = 0; $i < 30; $i++) {
                    $encoded = isset($params[$key.'*'.$i.'*']);
                    $segment = $params[$key.'*'.$i.($encoded ? '*' : '')] ?? null;
                    if ($segment === null) break;
                    if ($i === 0 && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $segment, $matches)) {
                        $charset = $matches[1] ?: 'UTF-8'; $segment = $matches[2];
                    }
                    $raw .= $encoded ? rawurldecode($segment) : $segment;
                }
                if ($charset !== null) {
                    try { $raw = mb_convert_encoding($raw, 'UTF-8', $charset); }
                    catch (\ValueError) { $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8'); }
                }
                return MailDocumentContent::name($raw);
            }
            if ($raw !== null) {
                if (preg_match("/^([^']*)'[^']*'(.*)$/s", $raw, $matches)) {
                    try { $raw = mb_convert_encoding(rawurldecode($matches[2]), 'UTF-8', $matches[1] ?: 'UTF-8'); }
                    catch (\ValueError) { $raw = mb_convert_encoding(rawurldecode($matches[2]), 'UTF-8', 'UTF-8'); }
                } else $raw = rawurldecode($raw);
            } else $raw = $params[$key] ?? null;
            if ($raw !== null) {
                $decoded = preg_match('/=\?[^?]+\?[bq]\?/i', $raw) ? @iconv_mime_decode($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $raw;
                return MailDocumentContent::name($decoded === false ? $raw : $decoded);
            }
        }
        return null;
    }

    public static function isAttachment(object $part): bool
    {
        return self::filename($part) !== null || strtoupper($part->disposition ?? '') === 'ATTACHMENT'
            || in_array((int)($part->type ?? 0), [2, 3, 4, 5, 6, 7], true);
    }

    public static function attachments(object $structure): array
    {
        $result = []; $count = 0;
        $walk = function (object $part, string $section, int $depth) use (&$walk, &$result, &$count) {
            abort_if(++$count > 1000 || $depth > 16, 422, 'El mensaje tiene demasiadas partes para el gestor.');
            if (self::isAttachment($part)) {
                $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'application'];
                $mime = ($types[(int)($part->type ?? 7)] ?? 'application').'/'.strtolower($part->subtype ?? 'octet-stream');
                $filename = self::filename($part) ?? ('adjunto-'.str_replace('.', '-', $section).match ($mime) {
                    'application/pdf' => '.pdf', 'image/png' => '.png', 'image/jpeg' => '.jpg', 'message/rfc822' => '.eml', default => '.bin',
                });
                $encodedBytes = max(0, (int)($part->bytes ?? 0));
                $bytes = (int)($part->encoding ?? 0) === 3 ? (int)floor($encodedBytes * 3 / 4) : $encodedBytes;
                $result[] = ['id' => $section, 'nombre' => $filename, 'mime' => $mime, 'bytes' => $bytes,
                    'encoded_bytes' => $encodedBytes, 'size_estimated' => true,
                    'encoding' => (int)($part->encoding ?? 0)] + app(MailDocumentContent::class)->capabilities($filename, $mime, $bytes);
                return;
            }
            foreach ($part->parts ?? [] as $index => $child) $walk($child, $depth === 0 ? (string)($index + 1) : $section.'.'.($index + 1), $depth + 1);
        };
        $walk($structure, '1', 0);
        return $result;
    }

    public static function decodeFile(string $encoded, int $encoding, string $decoded): void
    {
        $input = fopen($encoded, 'rb'); $output = fopen($decoded, 'wb');
        try {
            if (in_array($encoding, [3, 4], true)) stream_filter_append($input, $encoding === 3 ? 'convert.base64-decode' : 'convert.quoted-printable-decode', STREAM_FILTER_READ);
            $bytes = stream_copy_to_stream($input, $output, config('mail_documents.download_bytes') + 1);
            abort_if($bytes === false || $bytes > config('mail_documents.download_bytes'), 413, 'El adjunto supera el límite de descarga.');
        } finally { fclose($input); fclose($output); }
    }
}
