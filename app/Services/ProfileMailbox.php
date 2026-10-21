<?php

namespace App\Services;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ProfileMailbox
{
    public const FOLDERS = ['INBOX', 'Sent', 'Trash'];
    private const SERVER = '{mail.tecnoweb.space:993/imap/ssl/validate-cert/norsh}';

    public function open(string $email, #[\SensitiveParameter] string $password)
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('El acceso al correo no está disponible temporalmente.');
        }
        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 15);
        $connection = @imap_open(self::SERVER.'INBOX', $email, $password, 0, 1);
        imap_errors();
        imap_alerts();
        if (!$connection) throw new \RuntimeException('No se pudo acceder al buzón. Comprueba la contraseña y que el buzón exista.');
        return $connection;
    }

    public function close($connection): void
    {
        @imap_close($connection);
        imap_errors();
        imap_alerts();
    }

    private function folder($connection, string $folder, bool $create = false): bool
    {
        if (!in_array($folder, self::FOLDERS, true)) throw new \InvalidArgumentException('Carpeta inválida.');
        $path = self::SERVER.$folder;
        // c-client may keep the previous selection after a failed REOPEN.
        // Check existence before selecting or creating a folder.
        if (!@imap_status($connection, $path, SA_MESSAGES)) {
            imap_errors();
            if (!$create || !@imap_createmailbox($connection, $path)) return false;
        }
        return @imap_reopen($connection, $path);
    }

    private function decode(string $text): string
    {
        $result = '';
        foreach (imap_mime_header_decode($text) ?: [] as $part) {
            $charset = $part->charset === 'default' ? 'UTF-8' : $part->charset;
            $result .= @iconv($charset, 'UTF-8//IGNORE', $part->text) ?: mb_convert_encoding($part->text, 'UTF-8', 'UTF-8');
        }
        return $result;
    }

    private function summary(object $row): array
    {
        return ['uid' => (int)$row->uid, 'subject' => $this->decode($row->subject ?? '(Sin asunto)'),
            'from' => $this->decode($row->from ?? ''), 'to' => $this->decode($row->to ?? ''),
            'date' => $row->date ?? '', 'seen' => (bool)($row->seen ?? false), 'size' => (int)($row->size ?? 0)];
    }

    public function messages($connection, string $folder, int $page): array
    {
        if (!$this->folder($connection, $folder)) return ['data' => [], 'total' => 0, 'page' => $page, 'last_page' => 1];
        $uids = imap_search($connection, 'UNDELETED', SE_UID) ?: [];
        rsort($uids, SORT_NUMERIC);
        $total = count($uids);
        $last = max(1, (int)ceil($total / 20));
        $page = min($page, $last);
        $selected = array_slice($uids, ($page - 1) * 20, 20);
        $rows = $selected ? (imap_fetch_overview($connection, implode(',', $selected), FT_UID) ?: []) : [];
        usort($rows, fn($a, $b) => $b->uid <=> $a->uid);
        return ['data' => array_map(fn($row) => $this->summary($row), $rows),
            'total' => $total, 'page' => $page, 'last_page' => $last];
    }

    private function bodyParts($connection, int $uid, object $part, string $section = ''): array
    {
        $result = ['plain' => '', 'html' => ''];
        if (isset($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $childSection = $section === '' ? (string)($index + 1) : $section.'.'.($index + 1);
                $body = $this->bodyParts($connection, $uid, $child, $childSection);
                foreach (['plain', 'html'] as $kind) $result[$kind] .= $body[$kind];
            }
            return $result;
        }
        if (($part->type ?? -1) !== 0 || strtoupper($part->disposition ?? '') === 'ATTACHMENT') return $result;
        if (($part->bytes ?? 0) > 1024 * 1024) return $result;
        $raw = $section === '' ? imap_body($connection, $uid, FT_UID | FT_PEEK)
            : imap_fetchbody($connection, $uid, $section, FT_UID | FT_PEEK);
        $raw = match ($part->encoding ?? 0) { 3 => base64_decode($raw ?: '', true) ?: '', 4 => quoted_printable_decode($raw ?: ''), default => $raw ?: '' };
        foreach ($part->parameters ?? [] as $parameter) {
            if (strtolower($parameter->attribute) === 'charset') {
                $raw = @iconv($parameter->value, 'UTF-8//IGNORE', $raw) ?: $raw;
            }
        }
        $kind = strtoupper($part->subtype ?? '') === 'HTML' ? 'html' : 'plain';
        $result[$kind] = mb_convert_encoding($raw, 'UTF-8', 'UTF-8')."\n";
        return $result;
    }

    public function read($connection, string $folder, int $uid): array
    {
        if (!$this->folder($connection, $folder) || !imap_msgno($connection, $uid)) abort(404, 'El mensaje ya no está en esta carpeta.');
        $overview = imap_fetch_overview($connection, (string)$uid, FT_UID)[0] ?? null;
        $structure = imap_fetchstructure($connection, $uid, FT_UID);
        if (!$overview || !$structure) throw new \RuntimeException('No se pudo leer el mensaje.');
        $parts = $this->bodyParts($connection, $uid, $structure);
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $parts['html']);
        $html = preg_replace('~<br\s*/?>|</(?:p|div|tr)>~i', "\n", $html);
        $text = $parts['plain'] ?: html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $header = imap_rfc822_parse_headers(imap_fetchheader($connection, $uid, FT_UID) ?: '');
        $from = $header->reply_to[0] ?? $header->from[0] ?? null;
        $reply = $from ? ($from->mailbox ?? '').'@'.($from->host ?? '') : '';
        imap_setflag_full($connection, (string)$uid, '\\Seen', ST_UID);
        return $this->summary($overview) + ['body' => mb_substr(trim($text), 0, 100000),
            'reply_to' => filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : ''];
    }

    public function move($connection, string $folder, int $uid): void
    {
        $target = $folder === 'Trash' ? 'INBOX' : 'Trash';
        if (!$this->folder($connection, $target, true) || !$this->folder($connection, $folder)
            || !imap_msgno($connection, $uid)) abort(404, 'No se encontró el mensaje o la carpeta.');
        if (!imap_mail_move($connection, (string)$uid, $target, CP_UID)) throw new \RuntimeException('No se pudo mover el mensaje.');
        // Do not expunge unrelated messages flagged by another client.
        // The source is flagged deleted and excluded by UNDELETED; the copy is recoverable.
    }

    public function transport(string $email, #[\SensitiveParameter] string $password): EsmtpTransport
    {
        $transport = new class('mail.tecnoweb.space', 587, false, null, null, null, [new LoginAuthenticator()]) extends EsmtpTransport {
            public bool $startTlsAccepted = false;

            public function executeCommand(string $command, array $codes): string
            {
                // Symfony 6 isTLS() describes implicit TLS, not an upgraded socket.
                // Its EHLO flow completes the TLS handshake before reaching AUTH.
                if (str_starts_with(strtoupper($command), 'AUTH ') && !$this->startTlsAccepted) {
                    throw new TransportException('STARTTLS is required before authentication.');
                }
                if (strtoupper(trim($command)) === 'QUIT') $this->startTlsAccepted = false;
                $response = parent::executeCommand($command, $codes);
                if (strtoupper(trim($command)) === 'STARTTLS') $this->startTlsAccepted = true;
                return $response;
            }
        };
        $transport->getStream()->setTimeout(15);
        $transport->setUsername($email)->setPassword($password);
        return $transport;
    }

    public function send($connection, string $email, string $name, #[\SensitiveParameter] string $password, array $data): array
    {
        $message = (new Email())->from(new Address($email, $name))->to($data['to'])->subject($data['subject'])->text($data['body']);
        $transport = $this->transport($email, $password);
        try { $transport->send($message); } finally {
            // A failed QUIT must not report an accepted message as unsent.
            try { $transport->stop(); } catch (\Throwable $e) {}
        }
        $saved = $this->folder($connection, 'Sent', true) && @imap_append($connection, self::SERVER.'Sent', $message->toString(), '\\Seen');
        return ['message' => $saved ? 'Mensaje enviado.' : 'Mensaje enviado, pero no se pudo guardar una copia en Enviados.'];
    }
}
