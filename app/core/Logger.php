<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Writes one JSON line per event to storage/logs/app-YYYY-MM-DD.log.
 * Context keys that look like secrets are redacted before writing.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const SECRET_KEYS = ['password', 'pass', 'pwd', 'token', '_token', 'secret', 'db_pass', 'password_hash'];

    public static function debug(string $message, array $context = []): void { self::log('debug', $message, $context); }
    public static function info(string $message, array $context = []): void { self::log('info', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::log('warning', $message, $context); }
    public static function error(string $message, array $context = []): void { self::log('error', $message, $context); }

    public static function log(string $level, string $message, array $context = []): void
    {
        $min = self::LEVELS[(string) config('app.log_level', 'info')] ?? 1;
        if ((self::LEVELS[$level] ?? 3) < $min) {
            return;
        }
        $line = json_encode([
            'time'    => date('Y-m-d H:i:s'),
            'level'   => $level,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
