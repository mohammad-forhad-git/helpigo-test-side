<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Central error handling.
 * - Every PHP warning/notice becomes an exception, so bugs are not silently ignored.
 * - Customers only ever see a friendly page. Details go to the log.
 * - With APP_DEBUG=true (local only) the developer sees the real error.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Deprecations are logged, not fatal: a newer PHP on the server must not take the shop down.
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning('Deprecated: ' . $message, ['file' => $file . ':' . $line]);
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal error', $error);
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->status : 500;

        if ($status >= 500) {
            Logger::error($e->getMessage(), [
                'type' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'url'  => $_SERVER['REQUEST_URI'] ?? 'cli',
            ]);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
        }

        $message = $e instanceof HttpException && $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage($status);

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $message]);
            return;
        }

        try {
            echo View::render('errors/error', [
                'title'   => self::title($status),
                'status'  => $status,
                'message' => $message,
                'debug'   => self::$debug && $status >= 500 ? $e : null,
            ], 'layouts/minimal');
        } catch (Throwable) {
            // The error page itself failed (e.g. database down while loading settings).
            echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
                . '<p style="font-family:sans-serif;padding:2rem">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    private static function title(int $status): string
    {
        return match ($status) {
            403 => 'Access denied',
            404 => 'Page not found',
            405 => 'Not allowed',
            419 => 'Page expired',
            429 => 'Too many requests',
            default => 'Something went wrong',
        };
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            403 => 'You do not have permission to view this page.',
            404 => 'We could not find the page you were looking for.',
            405 => 'This action is not allowed here.',
            419 => 'Your session expired. Please go back, refresh the page and try again.',
            429 => 'Too many attempts. Please wait a few minutes and try again.',
            default => 'Sorry, something went wrong on our side. Please try again in a moment.',
        };
    }
}
