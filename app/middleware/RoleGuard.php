<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;

/**
 * Base for the role checks. Logged-out visitors go to /login (and come back afterwards);
 * a logged-in user with the wrong role gets 403.
 * Admins are logged out after SESSION_IDLE_MINUTES of inactivity; customers and workers
 * stay logged in on their phone, like other mobile apps.
 */
abstract class RoleGuard
{
    /** @return list<string> roles allowed through */
    abstract protected function roles(): array;

    public function handle(Request $request): void
    {
        $user = AuthService::user();
        $stale = $user !== null && AuthService::sessionIsStale($user);
        $idle = $user !== null && $user['role'] === 'admin'
            && time() - (int) Session::get('last_activity', 0) > (int) config('app.session_idle_minutes', 60) * 60;

        if ($user === null || $stale || $idle) {
            if ($user !== null) {
                AuthService::logout();
                Session::flash('error', $stale
                    ? 'Your password was changed. Please log in again.'
                    : 'You were logged out after a period of inactivity.');
            }
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Please log in again.');
            }
            if ($request->method === 'GET') {
                Session::set('intended', $request->path);
            }
            redirect('/login');
        }

        if (!in_array($user['role'], $this->roles(), true)) {
            throw new HttpException(403);
        }
        Session::set('last_activity', time());
        header('Cache-Control: no-store, private');
    }
}
