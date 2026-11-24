<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Minimal cURL wrapper for SMS and payment providers. Always uses TLS verification and timeouts. */
final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string, json:?array}
     */
    public static function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'HelpigoClient/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string) $response, true);
        return ['status' => $status, 'body' => (string) $response, 'json' => is_array($json) ? $json : null];
    }

    public static function postJson(string $url, array $data, array $headers = [], int $timeout = 30): array
    {
        return self::request('POST', $url, $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES), $timeout);
    }
}
