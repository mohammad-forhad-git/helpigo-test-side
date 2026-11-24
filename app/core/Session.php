<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session handling with secure cookie flags.
 * Sessions are stored in storage/sessions so we control their lifetime on shared hosting
 * (the server default often deletes them after 24 minutes, which would log people out).
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) config('app.session_days', 30) * 86400;
        $path = STORAGE_PATH . '/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_name((string) config('app.session_name', 'hg_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => (config('app.base_path') ?: '') . '/',
            'secure'   => (bool) config('app.session_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Flash messages: what was set during the previous request becomes readable now.
        $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
        $_SESSION['_flash_next'] = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session ID, same data. Call after login to stop session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }
}
