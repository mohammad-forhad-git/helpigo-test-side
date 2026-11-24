<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;

/** Rejects any POST without a valid CSRF token (status 419). */
final class VerifyCsrf
{
    public function handle(Request $request): void
    {
        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');
        if (!Csrf::verify($token)) {
            Logger::warning('CSRF check failed', ['path' => $request->path, 'ip' => $request->ip()]);
            throw new HttpException(419);
        }
    }
}
