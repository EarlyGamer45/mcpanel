<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Transactional e-mail. Drivers: "log" (development, writes to storage/logs) and
 * "smtp" (STARTTLS/SSL with AUTH LOGIN). Recipients are hashed in mail_log so
 * the log itself does not become a second customer database.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $html, string $template = ''): bool
    {
        $db = App::db();
        $status = 'sent';
        $error = null;
        $driver = (string) Config::get('mail.driver', 'log');
        try {
            if ($driver === 'smtp') {
                self::smtp($to, $subject, $html);
            } else {
                $directory = App::path('storage/logs');
                if (!is_dir($directory)) {
                    @mkdir($directory, 0775, true);
                }
                @file_put_contents(
                    $directory . '/mail-' . gmdate('Y-m-d') . '.log',
                    sprintf("[%s] to=%s subject=%s\n%s\n\n", gmdate('c'), $to, $subject, $html),
                    FILE_APPEND | LOCK_EX
                );
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = Str::limit($e->getMessage(), 250);
            Logger::error('mail.failed', ['template' => $template, 'error' => $e->getMessage()]);
        }
        try {
            $db->insert('mail_log', [
                'to_hash' => hash('sha256', mb_strtolower(trim($to))),
                'subject' => Str::limit($subject, 190),
                'template' => $template !== '' ? $template : null,
                'status' => $status,
                'error' => $error,
                'created_at' => Database::now(),
            ]);
        } catch (\Throwable) {
            // logging must never break a request
        }

        return $status === 'sent';
    }

    /** @param array<string,mixed> $data */
    public static function template(string $heading, string $bodyHtml, array $data = []): string
    {
        $brand = e((string) App::setting('brand_name', config('app.name')));
        $color = e((string) App::setting('brand_color', '#0f9d58'));
        $footer = e((string) App::setting('mail_footer', __('mail_footer_default')));
        $cta = '';
        if (!empty($data['cta_url']) && !empty($data['cta_label'])) {
            $cta = '<p style="margin:24px 0"><a href="' . e((string) $data['cta_url']) . '" style="background:' . $color . ';color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600">' . e((string) $data['cta_label']) . '</a></p>';
        }

        return '<!doctype html><html><body style="margin:0;background:#f5f6f8;padding:24px;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2933">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:28px">'
            . '<div style="font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:' . $color . ';font-weight:700">' . $brand . '</div>'
            . '<h1 style="font-size:21px;margin:10px 0 16px">' . e($heading) . '</h1>'
            . '<div style="font-size:15px;line-height:1.6">' . $bodyHtml . '</div>'
            . $cta
            . '<hr style="border:none;border-top:1px solid #e4e7eb;margin:24px 0">'
            . '<p style="font-size:12px;color:#7b8794;margin:0">' . $footer . '</p>'
            . '</div></body></html>';
    }

    private static function smtp(string $to, string $subject, string $html): void
    {
        $host = (string) Config::get('mail.host', '127.0.0.1');
        $port = (int) Config::get('mail.port', 587);
        $username = (string) Config::get('mail.username', '');
        $password = (string) Config::get('mail.password', '');
        $encryption = strtolower((string) Config::get('mail.encryption', 'tls'));
        $from = (string) Config::get('mail.from_address', 'noreply@example.com');
        $fromName = (string) Config::get('mail.from_name', config('app.name'));

        $transport = $encryption === 'ssl' || $port === 465 ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new \RuntimeException('SMTP connection failed: ' . $errstr);
        }
        stream_set_timeout($socket, 15);
        self::expect($socket, 220);

        $hostname = (string) (parse_url((string) Config::get('app.url', 'localhost'), PHP_URL_HOST) ?: 'localhost');
        self::command($socket, 'EHLO ' . $hostname, 250);

        if ($encryption === 'tls' && $transport === 'tcp://') {
            self::command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP STARTTLS negotiation failed');
            }
            self::command($socket, 'EHLO ' . $hostname, 250);
        }
        if ($username !== '') {
            self::command($socket, 'AUTH LOGIN', 334);
            self::command($socket, base64_encode($username), 334);
            self::command($socket, base64_encode($password), 235);
        }
        self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
        self::command($socket, 'RCPT TO:<' . $to . '>', 250, [251]);
        self::command($socket, 'DATA', 354);

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'Date: ' . gmdate('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hostname . '>',
        ];
        $body = quoted_printable_encode($html);
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        fwrite($socket, $data . "\r\n.\r\n");
        self::expect($socket, 250);
        self::command($socket, 'QUIT', 221);
        fclose($socket);
    }

    /** @param list<int> $extra */
    private static function command($socket, string $command, int $expected, array $extra = []): string
    {
        fwrite($socket, $command . "\r\n");

        return self::expect($socket, $expected, $extra);
    }

    /** @param list<int> $extra */
    private static function expect($socket, int $expected, array $extra = []): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expected && !in_array($code, $extra, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($response));
        }

        return $response;
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
