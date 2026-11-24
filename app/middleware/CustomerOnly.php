<?php

declare(strict_types=1);

namespace App\Middleware;

final class CustomerOnly extends RoleGuard
{
    protected function roles(): array
    {
        return ['customer'];
    }
}
