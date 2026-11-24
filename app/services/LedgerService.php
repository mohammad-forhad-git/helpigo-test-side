<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

/**
 * Helper and customer ledgers (docs/business-rules.md §4, §5, §9). Append-only: balance = SUM(amount).
 * Helper ledger: positive = Helpigo owes the helper. Customer ledger: positive = credit, negative = due.
 * Callers run these inside the same transaction as the status change that causes them.
 */
final class LedgerService
{
    public static function workerBalance(int $workerId): int
    {
        return Money::fromDecimal((string) (Database::value('SELECT COALESCE(SUM(amount), 0) FROM worker_ledger WHERE worker_id = ?', [$workerId]) ?? '0'));
    }

    public static function customerBalance(int $customerId): int
    {
        return Money::fromDecimal((string) (Database::value('SELECT COALESCE(SUM(amount), 0) FROM customer_ledger WHERE customer_id = ?', [$customerId]) ?? '0'));
    }

    /**
     * Credit the customer can spend on fees: credit rows (promo, referral, refunds, minus what was used
     * or paid out), but never more than the whole balance, so open dues are settled first and early
     * fee payments for running tasks are not mistaken for credit.
     */
    public static function customerCredit(int $customerId): int
    {
        $credit = Money::fromDecimal((string) (Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM customer_ledger WHERE customer_id = ?
             AND type IN ('promo_credit','referral_credit','refund','refund_paid','credit_used')",
            [$customerId]
        ) ?? '0'));
        return max(0, min($credit, self::customerBalance($customerId)));
    }

    public static function worker(int $workerId, ?int $taskId, string $type, int $paisa, ?string $note = null, ?int $by = null): void
    {
        if ($paisa === 0) {
            return;
        }
        Database::insert('worker_ledger', [
            'worker_id' => $workerId, 'task_id' => $taskId, 'type' => $type,
            'amount' => Money::toDecimal($paisa), 'note' => $note, 'created_by' => $by,
        ]);
    }

    public static function customer(int $customerId, ?int $taskId, string $type, int $paisa, ?string $note = null, ?int $by = null): void
    {
        if ($paisa === 0) {
            return;
        }
        Database::insert('customer_ledger', [
            'customer_id' => $customerId, 'task_id' => $taskId, 'type' => $type,
            'amount' => Money::toDecimal($paisa), 'note' => $note, 'created_by' => $by,
        ]);
    }

    /** True when the helper owes more than the dues cap (BR §5). */
    public static function workerOverDues(int $workerId): bool
    {
        return self::workerBalance($workerId) < -Money::fromDecimal((string) setting('max_worker_dues'));
    }

    /**
     * Money rows when a task completes (BR §4). $task = the tasks row (locked by the caller).
     * Writes once: a second call for the same task does nothing.
     */
    public static function onTaskCompleted(array $task): void
    {
        $taskId = (int) $task['id'];
        if (Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'earning'", [$taskId])) {
            return;
        }
        $workerId = (int) $task['worker_id'];
        $customerId = (int) $task['customer_id'];
        $fee = Money::fromDecimal((string) $task['worker_fee']);
        $platform = Money::fromDecimal((string) $task['platform_fee']);
        $commission = Money::fromDecimal((string) $task['commission']);
        $discount = Money::fromDecimal((string) $task['discount']);
        $customerFees = $fee + $platform - $discount;

        self::worker($workerId, $taskId, 'earning', $fee, 'Helper fee');
        self::worker($workerId, $taskId, 'commission', -$commission, 'Helpigo commission');

        if ($task['payment_method'] === 'cash') {
            // Case A: the helper collected the fees in cash and holds Helpigo's share.
            self::worker($workerId, $taskId, 'fees_collected_cash', -$customerFees, 'Fees collected in cash');
        } else {
            // Case B/C: the customer pays the fees to Helpigo by wallet.
            self::customer($customerId, $taskId, 'fees_due', -$customerFees, 'Fees for ' . $task['task_number']);
        }

        if ((int) $task['item_prepaid'] === 1) {
            // Case C: Helpigo advanced the item money; reimburse the receipt amount, refund the rest to the customer.
            $itemCost = $task['item_cost'] !== null ? Money::fromDecimal((string) $task['item_cost']) : 0;
            self::worker($workerId, $taskId, 'item_reimbursement', $itemCost, 'Items per receipt');
            $advance = PaymentService::itemAdvance($taskId);
            if ($advance > $itemCost) {
                self::customer($customerId, $taskId, 'refund', $advance - $itemCost, 'Unspent item advance');
            } elseif ($itemCost > $advance) {
                // An approved over-budget purchase: the customer owes the difference.
                self::customer($customerId, $taskId, 'fees_due', -($itemCost - $advance), 'Items above the advance');
            }
        }

        // Referral reward: the referrer gets credit when the referred customer's first task completes.
        $completedBefore = (int) Database::value("SELECT COUNT(*) FROM tasks WHERE customer_id = ? AND status = 'completed' AND id <> ?", [$customerId, $taskId]);
        $referrer = Database::value('SELECT referred_by FROM users WHERE id = ?', [$customerId]);
        if ($completedBefore === 0 && $referrer !== null) {
            self::customer((int) $referrer, $taskId, 'referral_credit', Money::fromDecimal((string) setting('referral_credit')), 'Friend completed their first task');
        }
    }

    /**
     * A task ended without completion (cancelled or expired): give back credit used on it and refund a
     * verified item advance to the customer. If item money was already sent to a helper, their
     * advance_paid row stays and they return it as a settlement.
     */
    public static function onTaskClosed(array $task): void
    {
        $taskId = (int) $task['id'];
        if (Database::value("SELECT 1 FROM customer_ledger WHERE task_id = ? AND note = 'Task closed: credit returned'", [$taskId])
            || Database::value("SELECT 1 FROM customer_ledger WHERE task_id = ? AND note = 'Task closed: advance refunded'", [$taskId])) {
            return;
        }
        $discount = Money::fromDecimal((string) $task['discount']);
        if ($discount > 0) {
            self::customer((int) $task['customer_id'], $taskId, 'credit_used', $discount, 'Task closed: credit returned');
        }
        $advance = PaymentService::itemAdvance($taskId);
        if ($advance > 0) {
            self::customer((int) $task['customer_id'], $taskId, 'refund', $advance, 'Task closed: advance refunded');
        }
    }

    /** Customer cancelled after a helper accepted (BR §6): fee to the helper, due for the customer. */
    public static function onLateCancel(array $task, int $feePaisa): void
    {
        if ($feePaisa <= 0 || $task['worker_id'] === null) {
            return;
        }
        self::worker((int) $task['worker_id'], (int) $task['id'], 'cancellation_fee', $feePaisa, 'Customer cancelled after you accepted');
        self::customer((int) $task['customer_id'], (int) $task['id'], 'cancellation_due', -$feePaisa, 'Cancellation fee');
    }

    /** Admin sends a payout to the helper's wallet. */
    public static function payout(int $workerId, int $paisa, string $trxId, int $adminId): void
    {
        Database::transaction(static function () use ($workerId, $paisa, $trxId, $adminId): void {
            Database::one('SELECT user_id FROM worker_profiles WHERE user_id = ? FOR UPDATE', [$workerId]);
            $balance = self::workerBalance($workerId);
            if ($paisa <= 0 || $paisa > $balance) {
                throw new DomainException('Payout must be more than ৳0 and at most the balance (' . Money::format($balance) . ').');
            }
            self::worker($workerId, null, 'payout', -$paisa, 'Paid to wallet, TrxID ' . $trxId, $adminId);
            Database::run("UPDATE payout_requests SET status = 'paid', trx_id = ?, processed_by = ?, processed_at = ? WHERE worker_id = ? AND status = 'pending'",
                [$trxId, $adminId, date('Y-m-d H:i:s'), $workerId]);
            AuditService::log('payout', 'worker', $workerId, ['amount' => Money::toDecimal($paisa), 'trx' => $trxId], $adminId);
        });
        NotificationService::notify($workerId, 'payout', 'Payout sent: ' . Money::format($paisa), 'TrxID ' . $trxId, '/worker/earnings', true);
    }

    /** Helper paid their dues to Helpigo (verified by admin against the statement). */
    public static function settlement(int $workerId, int $paisa, string $trxId, int $adminId): void
    {
        if ($paisa <= 0) {
            throw new DomainException('Amount must be more than ৳0.');
        }
        Database::transaction(static function () use ($workerId, $paisa, $trxId, $adminId): void {
            self::worker($workerId, null, 'settlement', $paisa, 'Dues paid, TrxID ' . $trxId, $adminId);
            AuditService::log('settlement', 'worker', $workerId, ['amount' => Money::toDecimal($paisa), 'trx' => $trxId], $adminId);
        });
        NotificationService::notify($workerId, 'settlement', 'Payment received: ' . Money::format($paisa), null, '/worker/earnings');
    }

    /** Admin correction on either ledger; reason required. */
    public static function adjust(string $who, int $userId, int $paisa, string $reason, int $adminId): void
    {
        $reason = trim($reason);
        if ($reason === '' || $paisa === 0) {
            throw new DomainException('Amount and reason are required.');
        }
        Database::transaction(static function () use ($who, $userId, $paisa, $reason, $adminId): void {
            $who === 'worker'
                ? self::worker($userId, null, 'adjustment', $paisa, $reason, $adminId)
                : self::customer($userId, null, 'adjustment', $paisa, $reason, $adminId);
            AuditService::log('ledger_adjustment', $who, $userId, ['amount' => Money::toDecimal($paisa), 'reason' => $reason], $adminId);
        });
    }

    /** Refund of a customer credit balance sent to their wallet. */
    public static function refundPaid(int $customerId, int $paisa, string $trxId, int $adminId): void
    {
        Database::transaction(static function () use ($customerId, $paisa, $trxId, $adminId): void {
            $balance = self::customerBalance($customerId);
            if ($paisa <= 0 || $paisa > $balance) {
                throw new DomainException('Refund must be more than ৳0 and at most the credit (' . Money::format($balance) . ').');
            }
            self::customer($customerId, null, 'refund_paid', -$paisa, 'Refund sent, TrxID ' . $trxId, $adminId);
            AuditService::log('refund_paid', 'customer', $customerId, ['amount' => Money::toDecimal($paisa), 'trx' => $trxId], $adminId);
        });
        NotificationService::notify($customerId, 'refund', 'Refund sent: ' . Money::format($paisa), 'TrxID ' . $trxId, '/app/account', true);
    }

    /** @return list<array<string, mixed>> */
    public static function workerRows(int $workerId, int $limit = 100): array
    {
        return Database::all(
            'SELECT l.*, t.task_number FROM worker_ledger l LEFT JOIN tasks t ON t.id = l.task_id
             WHERE l.worker_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$workerId]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function customerRows(int $customerId, int $limit = 100): array
    {
        return Database::all(
            'SELECT l.*, t.task_number FROM customer_ledger l LEFT JOIN tasks t ON t.id = l.task_id
             WHERE l.customer_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$customerId]
        );
    }
}
