<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\PaymentService;
use DomainException;

/** ADM-04, ADM-05: customer payments to verify, helper balances, payouts, settlements, refunds, adjustments. */
final class MoneyController extends AdminController
{
    public function payments(Request $request): void
    {
        $status = in_array($request->query('status'), ['pending', 'verified', 'rejected'], true) ? $request->query('status') : 'pending';
        $this->page('money/payments', ['title' => 'Payments', 'status' => $status, 'payments' => PaymentService::listForAdmin($status), 'accounts' => PaymentService::accounts()]);
    }

    public function verify(Request $request, string $id): void
    {
        $this->attempt('/admin/payments', function () use ($id): string {
            PaymentService::verify($this->adminId(), (int) $id);
            return 'Payment verified.';
        });
    }

    public function reject(Request $request, string $id): void
    {
        $this->attempt('/admin/payments', function () use ($request, $id): string {
            PaymentService::reject($this->adminId(), (int) $id, $request->str('reason'));
            return 'Payment rejected. The customer was told why.';
        });
    }

    public function helpers(Request $request): void
    {
        $this->page('money/helpers', [
            'title'    => 'Helper money',
            'balances' => Database::all(
                "SELECT u.id, u.name, u.phone, p.payout_method, p.payout_number, SUM(l.amount) AS balance
                 FROM worker_ledger l JOIN users u ON u.id = l.worker_id JOIN worker_profiles p ON p.user_id = u.id
                 GROUP BY u.id, u.name, u.phone, p.payout_method, p.payout_number HAVING balance <> 0 ORDER BY balance DESC"
            ),
            'requests' => Database::all("SELECT r.*, u.name FROM payout_requests r JOIN users u ON u.id = r.worker_id WHERE r.status = 'pending' ORDER BY r.id"),
            'minPayout'=> Money::fromDecimal((string) setting('min_payout')),
        ]);
    }

    public function payout(Request $request, string $id): void
    {
        $this->attempt('/admin/money/helpers', function () use ($request, $id): string {
            LedgerService::payout((int) $id, $this->amount($request), $this->trx($request), $this->adminId());
            return 'Payout recorded and the helper was notified.';
        });
    }

    public function settlement(Request $request, string $id): void
    {
        $this->attempt('/admin/money/helpers', function () use ($request, $id): string {
            LedgerService::settlement((int) $id, $this->amount($request), $this->trx($request), $this->adminId());
            return 'Payment from the helper recorded.';
        });
    }

    public function adjust(Request $request, string $who, string $id): void
    {
        $back = $who === 'worker' ? '/admin/helpers/' . (int) $id : '/admin/customers/' . (int) $id;
        $this->attempt($back, function () use ($request, $who, $id): string {
            $amount = Money::tryFromDecimal(str_replace(',', '', $request->str('amount'))) ?? throw new DomainException('Enter an amount like -50 or 120.50.');
            LedgerService::adjust($who === 'worker' ? 'worker' : 'customer', (int) $id, $amount, $request->str('reason'), $this->adminId());
            return 'Adjustment saved.';
        });
    }

    public function refund(Request $request, string $id): void
    {
        $this->attempt('/admin/customers/' . (int) $id, function () use ($request, $id): string {
            LedgerService::refundPaid((int) $id, $this->amount($request), $this->trx($request), $this->adminId());
            return 'Refund recorded and the customer was notified.';
        });
    }

    private function amount(Request $request): int
    {
        $amount = Money::tryFromDecimal(str_replace(',', '', $request->str('amount')));
        if ($amount === null || $amount <= 0) {
            throw new DomainException('Enter an amount.');
        }
        return $amount;
    }

    private function trx(Request $request): string
    {
        $trx = strtoupper(trim($request->str('trx_id')));
        if (!preg_match('/^[A-Z0-9]{6,30}$/', $trx)) {
            throw new DomainException('Enter the transaction ID.');
        }
        return $trx;
    }
}
