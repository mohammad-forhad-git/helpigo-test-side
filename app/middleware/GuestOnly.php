<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Services\AuthService;

/** Login and register pages: a logged-in user is sent to their own home screen. */
final class GuestOnly
{
    public function handle(Request $request): void
    {
        $user = AuthService::user();
        if ($user !== null) {
            redirect(AuthService::homeFor($user['role']));
        }
    }
}
