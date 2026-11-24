<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use DomainException;

/**
 * Wallet payments from customers (CUS-04, CUS-11, ADM-04). The customer sends money with bKash/Nagad
 * "Send Money" or "Payment" to Helpigo's number and submits the transaction ID; staff verify it
 * against the account statement. A (method, TrxID) pair can only be used once.
 * Purposes: item_advance (prepay a basket), fees (task fees), due (cancellation fee or other dues).
 */
final class PaymentService
{
    /** Helpigo's receiving numbers (settings bkash_number / nagad_number). */
    public static function accounts(): array
    {
        return array_filter([
            'bkash' => (string) setting('bkash_number', ''),
            'nagad' => (string) setting('nagad_number', ''),
        ]);
    }

    /** What the customer still has to pay for a task's fees by wallet (0 for cash tasks). */
    public static function feesOutstanding(array $task): int
    {
        if ($task['payment_method'] === 'cash' || in_array($task['status'], ['cancelled', 'expired', 'open'], true)) {
            return 0;
        }
        $fees = Money::fromDecimal((string) $task['worker_fee']) + Money::fromDecimal((string) $task['platform_fee']) - Money::fromDecimal((string) $task['discount']);
        $paid = self::sum((int) $task['id'], 'fees', ['pending', 'verified']);
        return max(0, $fees - $paid);
    }

    /** Verified item advance for a task, in paisa. */
    public static function itemAdvance(int $taskId): int
    {
        return self::sum($taskId, 'item_advance', ['verified']);
    }

    private static function sum(int $taskId, string $purpose, array $statuses): int
    {
        $value = Database::value(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE task_id = ? AND purpose = ? AND status IN (' . Database::placeholders(count($statuses)) . ')',
            array_merge([$taskId, $purpose], $statuses)
        );
        return Money::fromDecimal((string) ($value ?? '0'));
    }

    /**
     * Customer submits a payment. For item_advance the amount must equal the item budget;
     * for fees, the outstanding fees; for due, at most the outstanding due.
     */
    public static function submit(int $customerId, ?string $taskNumber, string $purpose, array $input): int
    {
        $v = (new Validator($input))
            ->in('method', 'wallet', ['bkash', 'nagad'])
            ->bdPhone('sender_number', 'Your wallet number')
            ->string('trx_id', 'Transaction ID', 30, true, 6);
        if ($v->fails()) {
            throw new ValidationFailed($v->errors());
        }
        $clean = $v->clean();
        $trx = strtoupper((string) preg_replace('/\s+/', '', (string) $clean['trx_id']));
        if (!preg_match('/^[A-Z0-9]{6,30}$/', $trx)) {
            throw new ValidationFailed(['trx_id' => 'Transaction ID has only letters and numbers.']);
        }

        $taskId = null;
        if ($purpose === 'due') {
            $balance = LedgerService::customerBalance($customerId);
            $pending = Money::fromDecimal((string) (Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payer_id = ? AND purpose = 'due' AND status = 'pending'", [$customerId]) ?? '0'));
            $amount = max(0, -$balance - $pending);
            if ($amount === 0) {
                throw new DomainException('You have nothing to pay right now.');
            }
        } else {
            $task = Database::one('SELECT * FROM tasks WHERE task_number = ? AND customer_id = ?', [(string) $taskNumber, $customerId]) ?? throw new DomainException('Task not found.');
            $taskId = (int) $task['id'];
            if ($purpose === 'item_advance') {
                if ($task['status'] !== TaskStatus::OPEN || (int) $task['item_prepaid'] === 1) {
                    throw new DomainException('Advance payment is only for open tasks that are not prepaid yet.');
                }
                if (Database::value("SELECT 1 FROM payments WHERE task_id = ? AND purpose = 'item_advance' AND status = 'pending'", [$taskId])) {
                    throw new DomainException('Your advance payment is waiting for verification.');
                }
                $amount = Money::fromDecimal((string) $task['item_budget']);
            } elseif ($purpose === 'fees') {
                $amount = self::feesOutstanding($task);
                if ($amount === 0) {
                    throw new DomainException('The fees for this task are already paid or submitted.');
                }
            } else {
                throw new DomainException('Unknown payment.');
            }
        }

        try {
            $id = Database::insert('payments', [
                'task_id' => $taskId, 'payer_id' => $customerId, 'purpose' => $purpose, 'method' => $clean['method'],
                'amount' => Money::toDecimal($amount), 'sender_number' => $clean['sender_number'], 'trx_id' => $trx,
            ]);
        } catch (\Throwable $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ValidationFailed(['trx_id' => 'This transaction ID was already used.']);
            }
            throw $e;
        }
        NotificationService::staff('Payment to verify', strtoupper($clean['method']) . ' ' . Money::format($amount) . " TrxID $trx");
        return $id;
    }

    /** ADM-04: staff confirm the money arrived. */
    public static function verify(int $adminId, int $paymentId): void
    {
        $payment = Database::transaction(static function () use ($adminId, $paymentId): array {
            $p = Database::one("SELECT * FROM payments WHERE id = ? FOR UPDATE", [$paymentId]);
            if ($p === null || $p['status'] !== 'pending') {
                throw new DomainException('This payment was already handled.');
            }
            Database::update('payments', ['status' => 'verified', 'verified_by' => $adminId, 'verified_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $paymentId]);
            $amount = Money::fromDecimal((string) $p['amount']);
            if ($p['purpose'] === 'item_advance') {
                $task = Database::one('SELECT id, status FROM tasks WHERE id = ? FOR UPDATE', [$p['task_id']]);
                Database::update('tasks', ['item_prepaid' => 1], 'id = :w_id', ['w_id' => $p['task_id']]);
                TaskFlowService::event((int) $p['task_id'], $adminId, $task['status'], $task['status'], 'Item advance received: ' . Money::format($amount));
            } else {
                LedgerService::customer((int) $p['payer_id'], $p['task_id'] !== null ? (int) $p['task_id'] : null, 'payment', $amount, strtoupper($p['method']) . ' ' . $p['trx_id'], $adminId);
            }
            AuditService::log('payment_verify', 'payment', $paymentId, [], $adminId);
            return $p;
        });
        NotificationService::notify((int) $payment['payer_id'], 'payment', 'Payment received: ' . money_db($payment['amount']), null,
            $payment['task_id'] !== null ? '/app/tasks/' . Database::value('SELECT task_number FROM tasks WHERE id = ?', [$payment['task_id']]) : '/app/account');
    }

    /** Money not found: the customer is told why and can submit again. */
    public static function reject(int $adminId, int $paymentId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please write the reason; the customer will see it.');
        }
        $payment = Database::transaction(static function () use ($adminId, $paymentId, $reason): array {
            $p = Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null || $p['status'] !== 'pending') {
                throw new DomainException('This payment was already handled.');
            }
            Database::update('payments', ['status' => 'rejected', 'verified_by' => $adminId, 'verified_at' => date('Y-m-d H:i:s'), 'note' => mb_substr($reason, 0, 255)],
                'id = :w_id', ['w_id' => $paymentId]);
            AuditService::log('payment_reject', 'payment', $paymentId, ['reason' => $reason], $adminId);
            return $p;
        });
        NotificationService::notify((int) $payment['payer_id'], 'payment', 'Payment not found: ' . money_db($payment['amount']), $reason, '/app/account', true);
    }

    /** @return list<array<string, mixed>> */
    public static function forTask(int $taskId): array
    {
        return Database::all('SELECT * FROM payments WHERE task_id = ? ORDER BY id', [$taskId]);
    }

    /** @return list<array<string, mixed>> */
    public static function listForAdmin(string $status): array
    {
        return Database::all(
            'SELECT p.*, u.name AS payer_name, u.phone AS payer_phone, t.task_number
             FROM payments p JOIN users u ON u.id = p.payer_id LEFT JOIN tasks t ON t.id = p.task_id
             WHERE p.status = ? ORDER BY p.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200',
            [$status]
        );
    }
}
