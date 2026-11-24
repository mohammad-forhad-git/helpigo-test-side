<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\AuthController;
use App\Controllers\Customer;
use App\Controllers\FileController;
use App\Controllers\HomeController;
use App\Controllers\PageController;
use App\Controllers\SharedController;
use App\Controllers\Worker;
use App\Core\Router;
use App\Middleware\AdminOnly;
use App\Middleware\AnyUser;
use App\Middleware\CustomerOnly;
use App\Middleware\GuestOnly;
use App\Middleware\WorkerOnly;

$r = new Router();

// ---------------------------------------------------------------- Public
$r->get('/', [HomeController::class, 'index']);
$r->get('/offline', [PageController::class, 'offline']);
foreach (['terms', 'privacy', 'refund-policy', 'helper-agreement', 'safety'] as $page) {
    $r->get('/' . $page, [PageController::class, 'show']);
}
$r->post('/language', [SharedController::class, 'language']);

$r->group(['middleware' => [GuestOnly::class]], static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->get('/register', [AuthController::class, 'showRegister']);
    $r->post('/register', [AuthController::class, 'register']);
    $r->get('/forgot-password', [AuthController::class, 'showForgot']);
    $r->post('/forgot-password', [AuthController::class, 'forgot']);
    $r->get('/reset-password', [AuthController::class, 'showReset']);
    $r->post('/reset-password', [AuthController::class, 'reset']);
});
$r->post('/logout', [AuthController::class, 'logout']);

// ---------------------------------------------------------------- Any logged-in user
$r->group(['middleware' => [AnyUser::class]], static function (Router $r): void {
    $r->get('/verify-phone', [AuthController::class, 'showVerify']);
    $r->post('/verify-phone', [AuthController::class, 'verify']);
    $r->post('/verify-phone/resend', [AuthController::class, 'resendVerify']);
    $r->get('/notifications', [SharedController::class, 'notifications']);
    $r->get('/notifications/unread', [SharedController::class, 'unread']);
    $r->get('/settings', [SharedController::class, 'settings']);
    $r->post('/settings', [SharedController::class, 'saveSettings']);
    $r->get('/chat/{number}', [SharedController::class, 'chat']);
    $r->post('/chat/{number}', [SharedController::class, 'sendChat']);
    $r->post('/sos/{number}', [SharedController::class, 'sos']);
    $r->get('/files/task/{id}', [FileController::class, 'task']);
    $r->get('/files/helper/{id}', [SharedController::class, 'helperPhoto']);
});

// ---------------------------------------------------------------- Customer
$r->group(['prefix' => '/app', 'middleware' => [CustomerOnly::class]], static function (Router $r): void {
    $r->get('', [Customer\HomeController::class, 'index']);
    $r->get('/account', [Customer\HomeController::class, 'account']);
    $r->post('/account/pay', [Customer\HomeController::class, 'payDue']);

    $r->get('/addresses', [Customer\AddressController::class, 'index']);
    $r->get('/addresses/new', [Customer\AddressController::class, 'create']);
    $r->post('/addresses', [Customer\AddressController::class, 'store']);
    $r->get('/addresses/{id}/edit', [Customer\AddressController::class, 'edit']);
    $r->post('/addresses/{id}', [Customer\AddressController::class, 'update']);
    $r->post('/addresses/{id}/delete', [Customer\AddressController::class, 'delete']);

    $r->get('/tasks', [Customer\TaskController::class, 'index']);
    $r->get('/tasks/new', [Customer\TaskController::class, 'choose']);
    $r->get('/tasks/new/{code}', [Customer\TaskController::class, 'create']);
    $r->post('/tasks/new/{code}', [Customer\TaskController::class, 'store']);
    $r->get('/tasks/{number}', [Customer\TaskController::class, 'show']);
    $r->get('/tasks/{number}/status', [Customer\TaskController::class, 'status']);
    $r->post('/tasks/{number}/cancel', [Customer\TaskController::class, 'cancel']);
    $r->post('/tasks/{number}/offer', [Customer\TaskController::class, 'acceptOffer']);
    $r->post('/tasks/{number}/approval', [Customer\TaskController::class, 'approval']);
    $r->post('/tasks/{number}/confirm', [Customer\TaskController::class, 'confirm']);
    $r->post('/tasks/{number}/report', [Customer\TaskController::class, 'report']);
    $r->post('/tasks/{number}/rate', [Customer\TaskController::class, 'rate']);
    $r->post('/tasks/{number}/pay', [Customer\TaskController::class, 'pay']);
});

// ---------------------------------------------------------------- Helper
$r->group(['prefix' => '/worker', 'middleware' => [WorkerOnly::class]], static function (Router $r): void {
    $r->get('', [Worker\HomeController::class, 'index']);
    $r->get('/feed', [Worker\HomeController::class, 'feed']);
    $r->post('/online', [Worker\HomeController::class, 'online']);
    $r->get('/profile', [Worker\ProfileController::class, 'edit']);
    $r->post('/profile', [Worker\ProfileController::class, 'update']);
    $r->get('/earnings', [Worker\EarningsController::class, 'index']);
    $r->post('/earnings/payout', [Worker\EarningsController::class, 'requestPayout']);

    $r->get('/tasks', [Worker\TaskController::class, 'index']);
    $r->get('/tasks/{number}', [Worker\TaskController::class, 'show']);
    $r->get('/tasks/{number}/status', [Worker\TaskController::class, 'status']);
    foreach (['accept', 'offer', 'start', 'approval', 'receipt', 'deliver', 'complete', 'no-code', 'drop', 'rate'] as $action) {
        $method = $action === 'no-code' ? 'noCode' : $action;
        $r->post('/tasks/{number}/' . $action, [Worker\TaskController::class, $method]);
    }
});

// ---------------------------------------------------------------- Admin
$r->group(['prefix' => '/admin', 'middleware' => [AdminOnly::class]], static function (Router $r): void {
    $r->get('', [Admin\DashboardController::class, 'index']);

    $r->get('/helpers', [Admin\HelperController::class, 'index']);
    $r->get('/helpers/{id}', [Admin\HelperController::class, 'show']);
    $r->get('/helpers/{id}/doc/{kind}', [Admin\HelperController::class, 'document']);
    $r->post('/helpers/{id}/review', [Admin\HelperController::class, 'review']);
    $r->post('/helpers/{id}/float', [Admin\HelperController::class, 'float']);

    $r->get('/tasks', [Admin\TaskController::class, 'index']);
    $r->get('/tasks/{number}', [Admin\TaskController::class, 'show']);
    $r->post('/tasks/{number}/cancel', [Admin\TaskController::class, 'cancel']);
    $r->post('/tasks/{number}/advance', [Admin\TaskController::class, 'advance']);
    $r->post('/tasks/{number}/resolve', [Admin\TaskController::class, 'resolve']);
    $r->post('/tasks/{number}/clear-review', [Admin\TaskController::class, 'clearReview']);

    $r->get('/payments', [Admin\MoneyController::class, 'payments']);
    $r->post('/payments/{id}/verify', [Admin\MoneyController::class, 'verify']);
    $r->post('/payments/{id}/reject', [Admin\MoneyController::class, 'reject']);
    $r->get('/money/helpers', [Admin\MoneyController::class, 'helpers']);
    $r->post('/money/helpers/{id}/payout', [Admin\MoneyController::class, 'payout']);
    $r->post('/money/helpers/{id}/settlement', [Admin\MoneyController::class, 'settlement']);
    $r->post('/money/{who}/{id}/adjust', [Admin\MoneyController::class, 'adjust']);
    $r->post('/money/customers/{id}/refund', [Admin\MoneyController::class, 'refund']);

    $r->get('/customers', [Admin\PeopleController::class, 'customers']);
    $r->get('/customers/{id}', [Admin\PeopleController::class, 'customer']);
    $r->post('/customers/{id}/status', [Admin\PeopleController::class, 'setStatus']);
    $r->get('/complaints', [Admin\PeopleController::class, 'complaints']);
    $r->post('/complaints/{id}', [Admin\PeopleController::class, 'closeComplaint']);

    $r->get('/settings', [Admin\SettingsController::class, 'index']);
    $r->post('/settings', [Admin\SettingsController::class, 'save']);
    $r->post('/zones', [Admin\SettingsController::class, 'saveZone']);
    $r->post('/categories/{id}', [Admin\SettingsController::class, 'saveCategory']);
    $r->get('/reports', [Admin\SettingsController::class, 'reports']);
    $r->get('/reports/daily.csv', [Admin\SettingsController::class, 'csv']);
});

return $r;
