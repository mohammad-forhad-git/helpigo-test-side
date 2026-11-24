<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpClient;
use App\Core\Logger;
use Throwable;

/**
 * Sends SMS (requirement SHR-03). Drivers (SMS_DRIVER):
 * - log:  writes to storage/logs/sms.log (development, tests)
 * - http: calls the provider URL in SMS_API_URL. Placeholders {to}, {message}, {key}, {sender}
 *         are replaced (URL-encoded). Works with the GET/POST APIs of common Bangladeshi
 *         SMS gateways; SMS_API_METHOD=GET|POST (POST sends the query part as a form body).
 * Sending never throws: a failed SMS must not break the action that triggered it.
 */
final class SmsService
{
    public static function send(string $phone, string $message): bool
    {
        $driver = (string) config('app.sms_driver', 'log');
        $to = '88' . $phone;   // providers expect 8801XXXXXXXXX
        try {
            if ($driver === 'http') {
                return self::sendHttp($to, $message);
            }
            $line = date('Y-m-d H:i:s') . " to=$to " . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
            file_put_contents(STORAGE_PATH . '/logs/sms.log', $line, FILE_APPEND | LOCK_EX);
            return true;
        } catch (Throwable $e) {
            Logger::error('SMS failed', ['to' => mask_phone($phone), 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function sendHttp(string $to, string $message): bool
    {
        $template = (string) config('app.sms_api_url', '');
        if ($template === '') {
            Logger::error('SMS_API_URL is not set');
            return false;
        }
        $url = strtr($template, [
            '{to}'      => rawurlencode($to),
            '{message}' => rawurlencode($message),
            '{key}'     => rawurlencode((string) config('app.sms_api_key', '')),
            '{sender}'  => rawurlencode((string) config('app.sms_sender_id', '')),
        ]);
        if (strtoupper((string) config('app.sms_api_method', 'GET')) === 'POST') {
            [$endpoint, $query] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($query, $fields);
            $response = HttpClient::request('POST', $endpoint, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
        } else {
            $response = HttpClient::request('GET', $url);
        }
        $ok = $response['status'] >= 200 && $response['status'] < 300;
        if (!$ok) {
            Logger::error('SMS provider error', ['status' => $response['status'], 'body' => mb_substr((string) $response['body'], 0, 200)]);
        }
        return $ok;
    }
}
