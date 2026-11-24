<?php

declare(strict_types=1);

namespace App\Middleware;

final class AdminOnly extends RoleGuard
{
    protected function roles(): array
    {
        return ['admin'];
    }
}
