<?php

declare(strict_types=1);

namespace App\Middleware;

/** Any logged-in user; the controller or service decides what they may see (e.g. private files). */
final class AnyUser extends RoleGuard
{
    protected function roles(): array
    {
        return ['customer', 'worker', 'admin'];
    }
}
