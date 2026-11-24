<?php

declare(strict_types=1);

namespace App\Controllers\Worker;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\WorkerService;

/** HLP-09: balance, ledger history, payout requests, how to pay dues. */
final class EarningsController extends Controller
{
    public function index(Request $request): void
    {
        $id = (int) AuthService::id();
        $this->render('worker/earnings', [
            'title'    => t('Earnings'),
            'balance'  => LedgerService::workerBalance($id),
            'rows'     => LedgerService::workerRows($id, 100),
            'profile'  => WorkerService::profile($id),
            'request'  => Database::one("SELECT * FROM payout_requests WHERE worker_id = ? AND status = 'pending'", [$id]),
            'week'     => Money::fromDecimal((string) (Database::value(
                "SELECT COALESCE(SUM(amount), 0) FROM worker_ledger WHERE worker_id = ? AND type IN ('earning','commission','cancellation_fee') AND created_at >= ?",
                [$id, date('Y-m-d 00:00:00', strtotime('monday this week'))]
            ) ?? '0')),
            'accounts' => PaymentService::accounts(),
        ]);
    }

    public function requestPayout(Request $request): void
    {
        $id = (int) AuthService::id();
        $balance = LedgerService::workerBalance($id);
        $profile = WorkerService::profile($id);
        $min = Money::fromDecimal((string) setting('min_payout'));
        if ($balance < $min) {
            Session::flash('error', t('You can request a payout from :min.', ['min' => Money::format($min, false)]));
        } elseif ($profile['payout_number'] === null) {
            Session::flash('error', t('Add your payout wallet in your profile first.'));
        } elseif (Database::value("SELECT 1 FROM payout_requests WHERE worker_id = ? AND status = 'pending'", [$id])) {
            Session::flash('info', t('Your payout request is already waiting.'));
        } else {
            Database::insert('payout_requests', [
                'worker_id' => $id, 'amount' => Money::toDecimal($balance), 'method' => $profile['payout_method'], 'account_number' => $profile['payout_number'],
            ]);
            \App\Services\NotificationService::staff('Payout requested', $profile['name'] . ' requested ' . Money::format($balance));
            Session::flash('success', t('Payout requested. Helpigo pays every :day.', ['day' => t('Sunday')]));
        }
        $this->redirect('/worker/earnings');
    }
}
