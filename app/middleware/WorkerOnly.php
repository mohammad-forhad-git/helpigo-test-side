<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Services\AuthService;
use App\Services\WorkerService;

/** Helper area. Also records activity so idle helpers can be set offline (SYS-03). */
final class WorkerOnly extends RoleGuard
{
    protected function roles(): array
    {
        return ['worker'];
    }

    public function handle(Request $request): void
    {
        parent::handle($request);
        WorkerService::touch((int) AuthService::id());
    }
}
