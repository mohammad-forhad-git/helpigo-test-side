<?php

declare(strict_types=1);

namespace App\Core;

/** Web request entry: session, security headers, routing. */
final class App
{
    public static function run(): void
    {
        $request = Request::capture();
        self::sendSecurityHeaders();
        Session::start();

        /** @var Router $router */
        $router = require APP_PATH . '/routes.php';
        $router->dispatch($request);
    }

    private static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Location: optional "use my location" on addresses. Camera: photo of a list / receipt.
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data: blob:; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'; "
            . "manifest-src 'self'; worker-src 'self'");
        if (config('app.session_secure')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        // Staging / demo sites must not appear in Google.
        if (config('app.env') !== 'production') {
            header('X-Robots-Tag: noindex, nofollow');
        }
        header_remove('X-Powered-By');
    }
}
