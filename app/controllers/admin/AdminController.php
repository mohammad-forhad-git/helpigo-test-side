<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Session;
use App\Services\AuthService;
use DomainException;

/** Base for admin controllers: admin layout and a flash-and-redirect helper. */
abstract class AdminController extends Controller
{
    protected function page(string $template, array $data = []): void
    {
        $this->render('admin/' . $template, $data, 'layouts/admin');
    }

    protected function adminId(): int
    {
        return (int) AuthService::id();
    }

    /** Runs $action; flashes its message (or the error) and redirects to $back. */
    protected function attempt(string $back, callable $action): never
    {
        try {
            Session::flash('success', (string) $action());
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect($back);
    }
}
