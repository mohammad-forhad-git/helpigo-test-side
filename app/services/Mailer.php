<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Plain-text email.
 *   MAIL_DRIVER=smtp  send through an SMTP account (recommended: Gmail with an App Password)
 *   MAIL_DRIVER=mail  PHP mail() via the server's sendmail (Gmail rejects this unless the domain has SPF/DKIM)
 *   MAIL_DRIVER=log   write to storage/logs/mail.log (local development and tests)
 */
final class Mailer
{
    /** @var array<string, mixed>|null test override for SMTP settings */
    private static ?array $smtpOverride = null;

    public static function useSmtp(?array $settings): void
    {
        self::$smtpOverride = $settings;
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $from = (string) config('app.mail_from');
        $shop = (string) setting('site_name', 'Shop');
        $driver = self::$smtpOverride !== null ? 'smtp' : (string) config('app.mail_driver');

        if ($driver === 'log') {
            $entry = sprintf("==== %s\nTo: %s\nFrom: %s <%s>\nSubject: %s\n\n%s\n\n", date('Y-m-d H:i:s'), $to, $shop, $from, $subject, $body);
            @file_put_contents(STORAGE_PATH . '/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
            return true;
        }

        try {
            if ($driver === 'smtp') {
                self::smtp($to, $subject, $body, $from, $shop);
                return true;
            }
            $headers = implode("\r\n", self::headers($to, $subject, $from, $shop, false));
            if (!@mail(self::clean($to), self::encodeHeader($subject), $body, $headers, '-f' . self::clean($from))) {
                throw new RuntimeException('mail() returned false');
            }
            return true;
        } catch (Throwable $e) {
            Logger::error('Email could not be sent', ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function clean(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value); // header injection protection
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode(self::clean($value)) . '?=';
    }

    /** @return list<string> */
    private static function headers(string $to, string $subject, string $from, string $shop, bool $full): array
    {
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [];
        if ($full) {
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'To: <' . self::clean($to) . '>';
            $headers[] = 'Subject: ' . self::encodeHeader($subject);
            $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        }
        $headers[] = 'From: ' . self::encodeHeader($shop) . ' <' . self::clean($from) . '>';
        $headers[] = 'Reply-To: ' . self::clean((string) setting('contact_email', $from));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        return $headers;
    }

    /** Minimal SMTP client: SSL (465) or STARTTLS (587), AUTH LOGIN. */
    private static function smtp(string $to, string $subject, string $body, string $from, string $shop): void
    {
        $cfg = self::$smtpOverride ?? [
            'host'       => (string) config('app.mail_host'),
            'port'       => (int) config('app.mail_port'),
            'encryption' => (string) config('app.mail_encryption'),
            'username'   => (string) config('app.mail_username'),
            'password'   => (string) config('app.mail_password'),
        ];
        if ($cfg['host'] === '') {
            throw new RuntimeException('MAIL_HOST is not set');
        }
        $ssl = $cfg['encryption'] === 'ssl';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $cfg['host']]]);
        $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 20);

        $read = static function (array $codes) use ($fp): string {
            $response = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $response .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            if (!in_array((int) substr($response, 0, 3), $codes, true)) {
                throw new RuntimeException('SMTP error: ' . trim($response));
            }
            return $response;
        };
        $send = static function (string $command, array $codes) use ($fp, $read): string {
            fwrite($fp, $command . "\r\n");
            return $read($codes);
        };

        try {
            $hostname = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
            $read([220]);
            $send('EHLO ' . $hostname, [250]);
            if ($cfg['encryption'] === 'tls') {
                $send('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS failed');
                }
                $send('EHLO ' . $hostname, [250]);
            }
            if ($cfg['username'] !== '') {
                $send('AUTH LOGIN', [334]);
                $send(base64_encode($cfg['username']), [334]);
                $send(base64_encode($cfg['password']), [235]);
            }
            $send('MAIL FROM:<' . self::clean($from) . '>', [250]);
            $send('RCPT TO:<' . self::clean($to) . '>', [250, 251]);
            $send('DATA', [354]);
            // Base64 body: safe for Bangla/৳ and never has a line starting with "." (no dot-stuffing needed).
            $message = implode("\r\n", self::headers($to, $subject, $from, $shop, true)) . "\r\n\r\n"
                . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.";
            $send($message, [250]);
            try {
                $send('QUIT', [221]);
            } catch (Throwable) {
                // the message is already accepted
            }
        } finally {
            fclose($fp);
        }
    }
}
