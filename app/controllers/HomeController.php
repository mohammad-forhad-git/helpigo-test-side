<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\AuthService;

/** Public landing page. Logged-in users go straight to their own home screen. */
final class HomeController extends Controller
{
    public function index(Request $request): void
    {
        $user = AuthService::user();
        if ($user !== null) {
            $this->redirect(AuthService::homeFor($user['role']));
        }
        $this->render('home', [
            'categories' => Database::all('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, id'),
            'zones'      => Database::all('SELECT name FROM zones WHERE is_active = 1 ORDER BY sort_order, id'),
        ]);
    }
}
