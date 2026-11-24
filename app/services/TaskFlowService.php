<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

/**
 * Everything that happens to a task after it is posted: matching, offers, execution, approvals,
 * delivery code, completion, cancellation, disputes (docs/business-rules.md §3–§7).
 * Each change locks the task row (SELECT … FOR UPDATE), checks TaskStatus, writes task_events and
 * any ledger rows in ONE transaction, then sends notifications after commit.
 */
final class TaskFlowService
{
    public const MAX_CODE_TRIES = 5;

    // ================================================================== helper side: finding work

    /** Why this helper may not take tasks right now, or null when they may. */
    public static function workerBlock(int $workerId): ?string
    {
        $p = Database::one('SELECT p.status, p.is_online, u.status AS account FROM worker_profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?', [$workerId]);
        return match (true) {
            $p === null || $p['account'] !== 'active' => 'Your account is not active.',
            $p['status'] !== 'approved' => 'Your profile is not approved yet.',
            (int) $p['is_online'] !== 1 => 'Go online to take tasks.',
            LedgerService::workerOverDues($workerId) => 'You owe Helpigo more than ' . money_db(setting('max_worker_dues'), false) . '. Please pay your dues to take new tasks.',
            self::activeTaskId($workerId) !== null => 'Finish your current task first.',
            default => null,
        };
    }

    public static function activeTaskId(int $workerId): ?int
    {
        $id = Database::value(
            "SELECT id FROM tasks WHERE worker_id = ? AND status IN ('assigned','in_progress','delivering','awaiting_confirmation') LIMIT 1",
            [$workerId]
        );
        return $id === null ? null : (int) $id;
    }

    /** SQL conditions that make an open task visible to helper :w (zone, service, gender, purchase limit). */
    private const ELIGIBLE = "t.status = 'open' AND t.needs_review = 0 AND t.expires_at > :now AND p.status = 'approved'
        AND t.zone_id IN (SELECT zone_id FROM worker_zones WHERE worker_id = :w1)
        AND t.category_id IN (SELECT category_id FROM worker_categories WHERE worker_id = :w2)
        AND (t.prefer_female_worker = 0 OR p.gender = 'female')
        AND (t.item_prepaid = 1 OR t.item_budget <= p.float_limit)";

    /** HLP-03: open tasks this helper may take, newest first. Addresses are not included. */
    public static function feed(int $workerId): array
    {
        return Database::all(
            'SELECT t.task_number, t.title, t.pickup_location, t.preferred_at, t.item_budget, t.worker_fee, t.commission,
                    t.payment_method, t.item_prepaid, t.created_at, c.name AS category_name, c.icon AS category_icon,
                    c.pricing_mode, z.name AS zone_name,
                    (SELECT COUNT(*) FROM task_items i WHERE i.task_id = t.id) AS item_count,
                    (SELECT o.amount FROM task_offers o WHERE o.task_id = t.id AND o.worker_id = :w3) AS my_offer
             FROM tasks t JOIN categories c ON c.id = t.category_id JOIN zones z ON z.id = t.zone_id
             JOIN worker_profiles p ON p.user_id = :w4
             WHERE ' . self::ELIGIBLE . ' ORDER BY COALESCE(t.preferred_at, t.created_at), t.id LIMIT 50',
            ['now' => date('Y-m-d H:i:s'), 'w1' => $workerId, 'w2' => $workerId, 'w3' => $workerId, 'w4' => $workerId]
        );
    }

    private static function eligible(int $workerId, int $taskId): bool
    {
        return (bool) Database::value(
            'SELECT 1 FROM tasks t JOIN worker_profiles p ON p.user_id = :w4 WHERE t.id = :id AND ' . self::ELIGIBLE,
            ['id' => $taskId, 'now' => date('Y-m-d H:i:s'), 'w1' => $workerId, 'w2' => $workerId, 'w4' => $workerId]
        );
    }

    /**
     * A task as the helper may see it: their own task (any status), or an open task in their feed.
     * Drop-off details and phone numbers only while the task is active (BR §7). Never the delivery code.
     */
    public static function findForWorker(int $workerId, string $number): ?array
    {
        $task = self::load($number);
        if ($task === null) {
            return null;
        }
        $mine = (int) $task['worker_id'] === $workerId;
        if (!$mine && !($task['status'] === TaskStatus::OPEN && self::eligible($workerId, (int) $task['id']))) {
            return null;
        }
        $active = $mine && TaskStatus::isActive($task['status']);
        unset($task['delivery_code']);
        if (!$active) {
            foreach (['dropoff_address', 'dropoff_landmark', 'recipient_name', 'recipient_phone'] as $key) {
                $task[$key] = null;
            }
            $task['customer_phone'] = null;
        }
        $task['is_mine'] = $mine;
        $task['is_active'] = $active;
        $task['items'] = Database::all('SELECT * FROM task_items WHERE task_id = ? ORDER BY sort_order, id', [$task['id']]);
        $task['attachments'] = Database::all('SELECT id, kind, created_at FROM task_attachments WHERE task_id = ? ORDER BY id', [$task['id']]);
        $task['approvals'] = Database::all('SELECT * FROM task_approvals WHERE task_id = ? ORDER BY id DESC', [$task['id']]);
        $task['my_offer'] = Database::one('SELECT * FROM task_offers WHERE task_id = ? AND worker_id = ?', [$task['id'], $workerId]);
        $task['advance_sent'] = (bool) Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'advance_paid'", [$task['id']]);
        return $task;
    }

    /** Full task row with names; for internal use and admin. */
    public static function load(string $number): ?array
    {
        return Database::one(
            'SELECT t.*, c.name AS category_name, c.code AS category_code, c.icon AS category_icon, c.pricing_mode,
                    c.involves_purchase, c.base_fee, z.name AS zone_name,
                    cu.name AS customer_name, cu.phone AS customer_phone,
                    w.name AS worker_name, w.phone AS worker_phone
             FROM tasks t JOIN categories c ON c.id = t.category_id JOIN zones z ON z.id = t.zone_id
             JOIN users cu ON cu.id = t.customer_id LEFT JOIN users w ON w.id = t.worker_id
             WHERE t.task_number = ?',
            [$number]
        );
    }

    /** @return list<array<string, mixed>> the helper's own tasks */
    public static function workerTasks(int $workerId, bool $past): array
    {
        $statuses = $past ? ['completed', 'cancelled', 'disputed'] : TaskStatus::ACTIVE;
        return Database::all(
            'SELECT t.task_number, t.title, t.status, t.worker_fee, t.commission, t.completed_at, t.created_at, t.preferred_at,
                    c.name AS category_name, c.icon AS category_icon
             FROM tasks t JOIN categories c ON c.id = t.category_id
             WHERE t.worker_id = ? AND t.status IN (' . Database::placeholders(count($statuses)) . ') ORDER BY t.id DESC LIMIT 100',
            array_merge([$workerId], $statuses)
        );
    }

    // ================================================================== accepting work

    /** HLP-04: accept a fixed-price task. Race-safe: the task row is locked, so only one helper wins. */
    public static function accept(int $workerId, string $number): void
    {
        $task = Database::transaction(static function () use ($workerId, $number): array {
            $task = self::lock($number);
            if ($task['status'] !== TaskStatus::OPEN) {
                throw new DomainException('Sorry, another helper already took this task.');
            }
            if (Database::value('SELECT pricing_mode FROM categories WHERE id = ?', [$task['category_id']]) === 'offer') {
                throw new DomainException('This is a custom task: send an offer instead.');
            }
            self::assertWorkerCanTake($workerId, (int) $task['id']);
            self::assign($task, $workerId, $workerId, null);
            return $task;
        });
        self::notifyAssigned($task, $workerId);
    }

    /** Offer on a custom task (CUS-07). One offer per helper per task; sending again updates it. */
    public static function sendOffer(int $workerId, string $number, string $amountInput, string $note): void
    {
        $task = self::load($number) ?? throw new DomainException('Task not found.');
        if ($task['status'] !== TaskStatus::OPEN || $task['pricing_mode'] !== 'offer') {
            throw new DomainException('This task is not taking offers.');
        }
        self::assertWorkerCanTake($workerId, (int) $task['id']);
        $amount = Money::tryFromDecimal(str_replace([',', '৳', ' '], '', $amountInput));
        $min = Money::fromDecimal((string) $task['base_fee']);
        if ($amount === null || $amount < $min || $amount > 10_000_00) {
            throw new DomainException('Offer must be between ' . Money::format($min, false) . ' and ৳10,000.');
        }
        Database::run(
            "INSERT INTO task_offers (task_id, worker_id, amount, note, status) VALUES (?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), note = VALUES(note), status = 'pending'",
            [$task['id'], $workerId, Money::toDecimal($amount), mb_substr(trim($note), 0, 255) ?: null]
        );
        NotificationService::notify((int) $task['customer_id'], 'offer', 'New offer: ' . Money::format($amount, false), $task['title'], '/app/tasks/' . $number);
    }

    /** @return list<array<string, mixed>> offers with helper name and rating, for the customer */
    public static function offers(int $taskId): array
    {
        return Database::all(
            "SELECT o.*, u.name AS worker_name, p.rating_avg, p.rating_count, p.completed_count
             FROM task_offers o JOIN users u ON u.id = o.worker_id JOIN worker_profiles p ON p.user_id = o.worker_id
             WHERE o.task_id = ? AND o.status = 'pending' ORDER BY o.amount, o.id",
            [$taskId]
        );
    }

    /** Customer accepts one offer: fees are re-quoted with the offer, other offers are declined. */
    public static function acceptOffer(int $customerId, string $number, int $offerId): void
    {
        [$task, $workerId] = Database::transaction(static function () use ($customerId, $number, $offerId): array {
            $task = self::lock($number, $customerId);
            TaskStatus::assert($task['status'], TaskStatus::ASSIGNED, 'customer');
            $offer = Database::one("SELECT * FROM task_offers WHERE id = ? AND task_id = ? AND status = 'pending' FOR UPDATE", [$offerId, $task['id']]);
            if ($offer === null) {
                throw new DomainException('This offer is no longer available.');
            }
            $workerId = (int) $offer['worker_id'];
            if (TaskFlowService::workerBlock($workerId) !== null || !self::eligible($workerId, (int) $task['id'])) {
                Database::run("UPDATE task_offers SET status = 'withdrawn' WHERE id = ?", [$offerId]);
                throw new DomainException('This helper is no longer available. Please choose another offer.');
            }
            $category = Database::one('SELECT * FROM categories WHERE id = ?', [$task['category_id']]);
            $quote = PricingService::current($category, 0, Money::fromDecimal((string) $offer['amount']), Money::fromDecimal((string) $task['discount']));
            Database::update('tasks', [
                'worker_fee' => Money::toDecimal($quote['worker_fee']), 'commission' => Money::toDecimal($quote['commission']),
                'platform_fee' => Money::toDecimal($quote['platform_fee']), 'discount' => Money::toDecimal($quote['discount']),
            ], 'id = :w_id', ['w_id' => $task['id']]);
            Database::run("UPDATE task_offers SET status = IF(id = ?, 'accepted', 'declined') WHERE task_id = ? AND status = 'pending'", [$offerId, $task['id']]);
            self::assign($task, $workerId, $customerId, 'Offer accepted: ' . Money::format($quote['worker_fee'], false));
            return [$task, $workerId];
        });
        self::notifyAssigned($task, $workerId);
    }

    private static function assertWorkerCanTake(int $workerId, int $taskId): void
    {
        $block = self::workerBlock($workerId);
        if ($block !== null) {
            throw new DomainException($block);
        }
        if (!self::eligible($workerId, $taskId)) {
            throw new DomainException('This task is not available to you.');
        }
    }

    private static function assign(array $task, int $workerId, int $actorId, ?string $note): void
    {
        Database::update('tasks', ['worker_id' => $workerId, 'status' => TaskStatus::ASSIGNED, 'assigned_at' => date('Y-m-d H:i:s')],
            'id = :w_id', ['w_id' => $task['id']]);
        self::event((int) $task['id'], $actorId, TaskStatus::OPEN, TaskStatus::ASSIGNED, $note);
    }

    private static function notifyAssigned(array $task, int $workerId): void
    {
        $name = (string) Database::value('SELECT name FROM users WHERE id = ?', [$workerId]);
        NotificationService::notify((int) $task['customer_id'], 'assigned', $name . ' accepted your task', $task['title'], '/app/tasks/' . $task['task_number'], true);
        NotificationService::notify($workerId, 'assigned', 'Task assigned: ' . $task['title'], 'Open the task for the address and details.', '/worker/tasks/' . $task['task_number']);
    }

    // ================================================================== doing the task

    /** assigned → in_progress. Prepaid tasks wait until staff have sent the item advance. */
    public static function start(int $workerId, string $number): void
    {
        Database::transaction(static function () use ($workerId, $number): void {
            $task = self::lockForWorker($number, $workerId);
            TaskStatus::assert($task['status'], TaskStatus::IN_PROGRESS, 'worker');
            if ((int) $task['item_prepaid'] === 1 && !Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'advance_paid'", [$task['id']])) {
                throw new DomainException('Wait for Helpigo to send the item money to your wallet before you start.');
            }
            Database::update('tasks', ['status' => TaskStatus::IN_PROGRESS, 'started_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $task['id']]);
            self::event((int) $task['id'], $workerId, $task['status'], TaskStatus::IN_PROGRESS);
        });
        $task = self::load($number);
        NotificationService::notify((int) $task['customer_id'], 'started', 'Your helper has started', $task['title'], '/app/tasks/' . $number);
    }

    /** HLP-07: ask the customer before spending over budget or substituting an item. */
    public static function requestApproval(int $workerId, string $number, string $type, string $description, string $newBudgetInput): void
    {
        $task = Database::transaction(static function () use ($workerId, $number, $type, $description, $newBudgetInput): array {
            $task = self::lockForWorker($number, $workerId);
            if ($task['status'] !== TaskStatus::IN_PROGRESS) {
                throw new DomainException('You can ask for approval while the task is in progress.');
            }
            if (!in_array($type, ['over_budget', 'substitution', 'other'], true)) {
                throw new DomainException('Choose what you need approval for.');
            }
            $description = trim($description);
            if (mb_strlen($description) < 5) {
                throw new DomainException('Please explain what changed (at least a few words).');
            }
            if (Database::value("SELECT 1 FROM task_approvals WHERE task_id = ? AND status = 'pending'", [$task['id']])) {
                throw new DomainException('Wait for the customer to answer your previous request.');
            }
            $newBudget = null;
            if ($type === 'over_budget') {
                $newBudget = Money::tryFromDecimal(str_replace([',', '৳', ' '], '', $newBudgetInput));
                $current = Money::fromDecimal((string) $task['item_budget']);
                $float = Money::fromDecimal((string) Database::value('SELECT float_limit FROM worker_profiles WHERE user_id = ?', [$workerId]));
                if ($newBudget === null || $newBudget <= $current) {
                    throw new DomainException('The new budget must be higher than ' . Money::format($current, false) . '.');
                }
                $extra = (int) $task['item_prepaid'] === 1 ? $newBudget - $current : $newBudget;
                if ($extra > $float) {
                    throw new DomainException('That is above your purchase limit (' . Money::format($float, false) . ').');
                }
            }
            Database::insert('task_approvals', [
                'task_id' => $task['id'], 'requested_by' => $workerId, 'type' => $type,
                'description' => mb_substr($description, 0, 500), 'new_budget' => $newBudget !== null ? Money::toDecimal($newBudget) : null,
            ]);
            return $task;
        });
        NotificationService::notify((int) $task['customer_id'], 'approval', 'Your helper needs your approval', mb_substr($description, 0, 120), '/app/tasks/' . $number, true);
    }

    /** CUS-08: the customer approves or declines. Approved over-budget raises the item budget. */
    public static function decideApproval(int $customerId, string $number, int $approvalId, bool $approve): void
    {
        $task = Database::transaction(static function () use ($customerId, $number, $approvalId, $approve): array {
            $task = self::lock($number, $customerId);
            $approval = Database::one("SELECT * FROM task_approvals WHERE id = ? AND task_id = ? AND status = 'pending' FOR UPDATE", [$approvalId, $task['id']]);
            if ($approval === null) {
                throw new DomainException('This request was already answered.');
            }
            Database::update('task_approvals', ['status' => $approve ? 'approved' : 'declined', 'decided_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $approvalId]);
            if ($approve && $approval['type'] === 'over_budget' && $approval['new_budget'] !== null) {
                Database::update('tasks', ['item_budget' => $approval['new_budget']], 'id = :w_id', ['w_id' => $task['id']]);
            }
            self::event((int) $task['id'], $customerId, $task['status'], $task['status'],
                ($approve ? 'Approved: ' : 'Declined: ') . mb_substr((string) $approval['description'], 0, 200));
            return $task;
        });
        NotificationService::notify((int) $task['worker_id'], 'approval', $approve ? 'Customer approved your request' : 'Customer declined your request', null, '/worker/tasks/' . $number, true);
    }

    /** HLP-06: receipt photo, receipt total and what happened to each item. */
    public static function saveReceipt(int $workerId, string $number, string $costInput, ?array $receiptFile, array $itemStatuses): void
    {
        $stored = null;
        try {
            Database::transaction(static function () use ($workerId, $number, $costInput, $receiptFile, $itemStatuses, &$stored): void {
                $task = self::lockForWorker($number, $workerId);
                if ($task['status'] !== TaskStatus::IN_PROGRESS) {
                    throw new DomainException('You can add the receipt while the task is in progress.');
                }
                $cost = Money::tryFromDecimal(str_replace([',', '৳', ' '], '', $costInput));
                $budget = Money::fromDecimal((string) $task['item_budget']);
                if ($cost === null || $cost <= 0) {
                    throw new DomainException('Enter the receipt total.');
                }
                if ($cost > $budget) {
                    throw new DomainException('The receipt is above the budget (' . Money::format($budget, false) . '). Ask the customer for approval first.');
                }
                $hasReceipt = (bool) Database::value("SELECT 1 FROM task_attachments WHERE task_id = ? AND kind = 'receipt'", [$task['id']]);
                if ($receiptFile === null && !$hasReceipt) {
                    throw new DomainException('Please add a photo of the receipt.');
                }
                if ($receiptFile !== null) {
                    $stored = PrivateFileService::storeTaskImage($receiptFile);
                    Database::insert('task_attachments', ['task_id' => $task['id'], 'uploaded_by' => $workerId, 'kind' => 'receipt', 'file_name' => $stored]);
                }
                Database::update('tasks', ['item_cost' => Money::toDecimal($cost)], 'id = :w_id', ['w_id' => $task['id']]);
                foreach ($itemStatuses as $itemId => $status) {
                    if (in_array($status, ['pending', 'bought', 'substituted', 'unavailable'], true)) {
                        Database::run('UPDATE task_items SET status = ? WHERE id = ? AND task_id = ?', [$status, (int) $itemId, $task['id']]);
                    }
                }
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                UploadService::delete(PrivateFileService::taskDir(), $stored);
            }
            throw $e;
        }
    }

    /** in_progress → delivering. Purchase tasks need the receipt first; no pending approval. */
    public static function startDelivery(int $workerId, string $number): void
    {
        $task = Database::transaction(static function () use ($workerId, $number): array {
            $task = self::lockForWorker($number, $workerId);
            TaskStatus::assert($task['status'], TaskStatus::DELIVERING, 'worker');
            if (Database::value("SELECT 1 FROM task_approvals WHERE task_id = ? AND status = 'pending'", [$task['id']])) {
                throw new DomainException('Wait for the customer to answer your approval request.');
            }
            $purchase = (int) Database::value('SELECT involves_purchase FROM categories WHERE id = ?', [$task['category_id']]) === 1;
            if ($purchase && $task['item_cost'] === null) {
                throw new DomainException('Add the receipt and total before delivering.');
            }
            Database::update('tasks', ['status' => TaskStatus::DELIVERING], 'id = :w_id', ['w_id' => $task['id']]);
            self::event((int) $task['id'], $workerId, $task['status'], TaskStatus::DELIVERING);
            return $task;
        });
        NotificationService::notify((int) $task['customer_id'], 'delivering', 'Your helper is on the way', 'Keep your delivery code ready.', '/app/tasks/' . $number, true);
    }

    /**
     * HLP-05: finish with the customer's 4-digit code. Wrong codes count; after 5 the helper must
     * use the no-code path. Returns true on success.
     */
    public static function completeWithCode(int $workerId, string $number, string $code): bool
    {
        $result = Database::transaction(static function () use ($workerId, $number, $code): string {
            $task = self::lockForWorker($number, $workerId);
            TaskStatus::assert($task['status'], TaskStatus::COMPLETED, 'worker');
            if ((int) $task['code_attempts'] >= self::MAX_CODE_TRIES) {
                return 'locked';
            }
            if (!preg_match('/^\d{4}$/', $code) || !hash_equals((string) $task['delivery_code'], $code)) {
                Database::run('UPDATE tasks SET code_attempts = code_attempts + 1 WHERE id = ?', [$task['id']]);
                return 'wrong';
            }
            Database::update('tasks', ['delivered_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $task['id']]);
            self::complete($task, $workerId);
            return 'ok';
        });
        if ($result === 'locked') {
            throw new DomainException('Too many wrong codes. Use "Customer not available" and add a delivery photo.');
        }
        if ($result === 'wrong') {
            throw new DomainException('That code is not correct. Ask the customer to check the code in their app.');
        }
        self::afterComplete($number);
        return true;
    }

    /** No code: delivering → awaiting_confirmation with a delivery photo. Auto-completes after 24 h. */
    public static function deliverWithoutCode(int $workerId, string $number, ?array $photo, string $note): void
    {
        if ($photo === null) {
            throw new DomainException('Please add a photo of the delivered items.');
        }
        $stored = PrivateFileService::storeTaskImage($photo);
        try {
            $task = Database::transaction(static function () use ($workerId, $number, $stored, $note): array {
                $task = self::lockForWorker($number, $workerId);
                TaskStatus::assert($task['status'], TaskStatus::AWAITING, 'worker');
                Database::insert('task_attachments', ['task_id' => $task['id'], 'uploaded_by' => $workerId, 'kind' => 'delivery', 'file_name' => $stored]);
                Database::update('tasks', ['status' => TaskStatus::AWAITING, 'delivered_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $task['id']]);
                self::event((int) $task['id'], $workerId, $task['status'], TaskStatus::AWAITING, mb_substr(trim($note), 0, 255) ?: null);
                return $task;
            });
        } catch (\Throwable $e) {
            UploadService::delete(PrivateFileService::taskDir(), $stored);
            throw $e;
        }
        NotificationService::notify((int) $task['customer_id'], 'awaiting', 'Delivered: please confirm', 'If anything is wrong, report it within ' . setting('auto_complete_hours') . ' hours.', '/app/tasks/' . $number, true);
    }

    /** CUS-10: customer confirms a no-code delivery. */
    public static function confirmByCustomer(int $customerId, string $number): void
    {
        Database::transaction(static function () use ($customerId, $number): void {
            $task = self::lock($number, $customerId);
            TaskStatus::assert($task['status'], TaskStatus::COMPLETED, 'customer');
            self::complete($task, $customerId);
        });
        self::afterComplete($number);
    }

    /** SYS-02: awaiting_confirmation older than auto_complete_hours completes. Idempotent. */
    public static function autoComplete(): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - (int) setting('auto_complete_hours') * 3600);
        $numbers = array_column(Database::all("SELECT task_number FROM tasks WHERE status = 'awaiting_confirmation' AND delivered_at < ? LIMIT 200", [$cutoff]), 'task_number');
        $done = 0;
        foreach ($numbers as $number) {
            $ok = Database::transaction(static function () use ($number): bool {
                $task = self::lock($number);
                if ($task['status'] !== TaskStatus::AWAITING) {
                    return false;
                }
                self::complete($task, null, 'Completed automatically: no issue reported');
                return true;
            });
            if ($ok) {
                $done++;
                self::afterComplete($number);
            }
        }
        return $done;
    }

    /** Status change + money rows + helper counters. Inside the caller's transaction. */
    private static function complete(array $task, ?int $actorId, ?string $note = null): void
    {
        Database::update('tasks', ['status' => TaskStatus::COMPLETED, 'completed_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $task['id']]);
        self::event((int) $task['id'], $actorId, $task['status'], TaskStatus::COMPLETED, $note);
        $task['status'] = TaskStatus::COMPLETED;
        LedgerService::onTaskCompleted($task);
        Database::run('UPDATE worker_profiles SET completed_count = completed_count + 1 WHERE user_id = ?', [$task['worker_id']]);
    }

    private static function afterComplete(string $number): void
    {
        $task = self::load($number);
        WorkerService::recalcTier((int) $task['worker_id']);
        NotificationService::notify((int) $task['customer_id'], 'completed', 'Task completed', 'Please rate ' . $task['worker_name'] . '.', '/app/tasks/' . $number);
        NotificationService::notify((int) $task['worker_id'], 'completed', 'Task completed: ' . $task['title'], 'Earnings added to your balance.', '/worker/earnings');
    }

    // ================================================================== cancelling and dropping

    /** HLP-08: helper drops an accepted task before starting. Task reopens; the helper gets a strike. */
    public static function dropByWorker(int $workerId, string $number, string $reason): void
    {
        $task = Database::transaction(static function () use ($workerId, $number, $reason): array {
            $task = self::lockForWorker($number, $workerId);
            if ($task['status'] !== TaskStatus::ASSIGNED) {
                throw new DomainException('You can only drop a task before you start it. Please contact support.');
            }
            if (Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'advance_paid'", [$task['id']])) {
                throw new DomainException('Item money was already sent to you. Please contact support to drop this task.');
            }
            Database::update('tasks', [
                'status' => TaskStatus::OPEN, 'worker_id' => null, 'assigned_at' => null,
                'expires_at' => date('Y-m-d H:i:s', time() + (int) setting('open_task_expiry_minutes') * 60),
            ], 'id = :w_id', ['w_id' => $task['id']]);
            Database::run("UPDATE task_offers SET status = 'withdrawn' WHERE task_id = ? AND worker_id = ?", [$task['id'], $workerId]);
            self::event((int) $task['id'], $workerId, TaskStatus::ASSIGNED, TaskStatus::OPEN, 'Helper dropped the task' . (trim($reason) !== '' ? ': ' . mb_substr(trim($reason), 0, 200) : ''));
            return $task;
        });
        NotificationService::notify((int) $task['customer_id'], 'dropped', 'Your helper could not continue', 'We are finding another helper.', '/app/tasks/' . $number, true);
        if (WorkerService::strikes($workerId) >= (int) setting('strike_limit')) {
            Database::run("UPDATE worker_profiles SET status = 'suspended', is_online = 0, review_note = ? WHERE user_id = ?",
                ['Paused automatically: too many dropped tasks in 30 days.', $workerId]);
            AuditService::log('helper_auto_suspend', 'worker', $workerId, ['strikes' => WorkerService::strikes($workerId)], null);
            NotificationService::notify($workerId, 'helper_review', 'Your helper account is paused', 'Too many dropped tasks. Helpigo will contact you.', '/worker', true);
            NotificationService::staff('Helper auto-paused', "Helper #$workerId dropped too many tasks.");
        }
    }

    /**
     * CUS-06: customer cancels. Open: free. Assigned, or in progress before buying: cancellation fee to
     * the helper. After buying: support handles it (items must be paid for).
     * @return int fee charged in paisa
     */
    public static function cancelByCustomer(int $customerId, string $number, string $reason): int
    {
        [$task, $fee] = Database::transaction(static function () use ($customerId, $number, $reason): array {
            $task = self::lock($number, $customerId);
            TaskStatus::assert($task['status'], TaskStatus::CANCELLED, 'customer');
            if ($task['status'] === TaskStatus::IN_PROGRESS && $task['item_cost'] !== null) {
                throw new DomainException('Your helper has already bought the items. Please contact support to cancel.');
            }
            $fee = $task['status'] === TaskStatus::OPEN ? 0 : Money::fromDecimal((string) setting('cancel_fee_assigned'));
            Database::update('tasks', [
                'status' => TaskStatus::CANCELLED, 'cancelled_by' => $customerId,
                'cancel_reason' => mb_substr(trim($reason), 0, 255) ?: null, 'cancellation_fee' => Money::toDecimal($fee),
            ], 'id = :w_id', ['w_id' => $task['id']]);
            Database::run("UPDATE task_offers SET status = 'declined' WHERE task_id = ? AND status = 'pending'", [$task['id']]);
            self::event((int) $task['id'], $customerId, $task['status'], TaskStatus::CANCELLED, trim($reason) ?: null);
            LedgerService::onLateCancel($task, $fee);
            LedgerService::onTaskClosed($task);
            return [$task, $fee];
        });
        if ($task['worker_id'] !== null) {
            NotificationService::notify((int) $task['worker_id'], 'cancelled', 'Task cancelled by the customer', $fee > 0 ? 'You receive ' . Money::format($fee, false) . '.' : null, '/worker/tasks/' . $number, true);
        }
        return $fee;
    }

    // ================================================================== disputes and admin

    /** CUS-13: customer reports a problem. Within the window the task becomes disputed. */
    public static function raiseIssue(int $customerId, string $number, string $type, string $description): void
    {
        $task = Database::transaction(static function () use ($customerId, $number, $type, $description): array {
            $task = self::lock($number, $customerId);
            $description = trim($description);
            if (mb_strlen($description) < 10) {
                throw new DomainException('Please describe the problem (at least 10 characters).');
            }
            if (!in_array($type, ['not_delivered', 'wrong_items', 'overcharge', 'behaviour', 'safety', 'payment', 'other'], true)) {
                throw new DomainException('Choose what went wrong.');
            }
            $window = (int) setting('dispute_window_hours') * 3600;
            $inWindow = in_array($task['status'], [TaskStatus::DELIVERING, TaskStatus::AWAITING], true)
                || ($task['status'] === TaskStatus::COMPLETED && strtotime((string) $task['completed_at']) > time() - $window);
            ComplaintService::create($customerId, (int) $task['id'], $task['worker_id'] !== null ? (int) $task['worker_id'] : null, $type, $description);
            if ($inWindow) {
                Database::update('tasks', ['status' => TaskStatus::DISPUTED], 'id = :w_id', ['w_id' => $task['id']]);
                self::event((int) $task['id'], $customerId, $task['status'], TaskStatus::DISPUTED, 'Problem reported');
            }
            return $task;
        });
        if ($task['worker_id'] !== null) {
            NotificationService::notify((int) $task['worker_id'], 'disputed', 'The customer reported a problem', 'Helpigo support will review it.', '/worker/tasks/' . $number);
        }
    }

    /**
     * ADM-06: decide a disputed task. completed → money rows as normal (once);
     * cancelled → no helper earning. A refund goes to the customer as credit.
     */
    public static function resolveDispute(int $adminId, string $number, string $outcome, string $resolution, int $refundPaisa): void
    {
        $resolution = trim($resolution);
        if ($resolution === '' || !in_array($outcome, ['completed', 'cancelled'], true)) {
            throw new DomainException('Choose an outcome and write the decision.');
        }
        Database::transaction(static function () use ($adminId, $number, $outcome, $resolution, $refundPaisa): void {
            $task = self::lock($number);
            TaskStatus::assert($task['status'], $outcome, 'admin');
            if ($outcome === TaskStatus::COMPLETED) {
                self::complete($task, $adminId, 'Dispute decided: ' . mb_substr($resolution, 0, 200));
            } else {
                Database::update('tasks', ['status' => TaskStatus::CANCELLED, 'cancel_reason' => mb_substr($resolution, 0, 255)], 'id = :w_id', ['w_id' => $task['id']]);
                self::event((int) $task['id'], $adminId, $task['status'], TaskStatus::CANCELLED, 'Dispute decided: ' . mb_substr($resolution, 0, 200));
                LedgerService::onTaskClosed($task);
            }
            if ($refundPaisa > 0) {
                LedgerService::customer((int) $task['customer_id'], (int) $task['id'], 'refund', $refundPaisa, 'Dispute refund', $adminId);
            }
            Database::run("UPDATE complaints SET status = 'resolved', resolution = ?, handled_by = ?, resolved_at = ? WHERE task_id = ? AND status IN ('open','in_review')",
                [mb_substr($resolution, 0, 1000), $adminId, date('Y-m-d H:i:s'), $task['id']]);
            AuditService::log('dispute_' . $outcome, 'task', (int) $task['id'], ['refund' => Money::toDecimal($refundPaisa)], $adminId);
        });
        $task = self::load($number);
        if ($outcome === TaskStatus::COMPLETED) {
            WorkerService::recalcTier((int) $task['worker_id']);
        }
        NotificationService::notify((int) $task['customer_id'], 'dispute', 'Your report was reviewed', mb_substr($resolution, 0, 200), '/app/tasks/' . $number, true);
        if ($task['worker_id'] !== null) {
            NotificationService::notify((int) $task['worker_id'], 'dispute', 'Dispute decided', mb_substr($resolution, 0, 200), '/worker/tasks/' . $number);
        }
    }

    /** Admin cancels any non-final task (no fees). */
    public static function cancelByAdmin(int $adminId, string $number, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please write the reason.');
        }
        $task = Database::transaction(static function () use ($adminId, $number, $reason): array {
            $task = self::lock($number);
            if (in_array($task['status'], TaskStatus::FINAL, true)) {
                throw new DomainException('This task is already closed.');
            }
            Database::update('tasks', ['status' => TaskStatus::CANCELLED, 'cancelled_by' => $adminId, 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = :w_id', ['w_id' => $task['id']]);
            self::event((int) $task['id'], $adminId, $task['status'], TaskStatus::CANCELLED, 'Cancelled by Helpigo: ' . mb_substr($reason, 0, 200));
            AuditService::log('task_cancel', 'task', (int) $task['id'], ['reason' => $reason], $adminId);
            LedgerService::onTaskClosed($task);
            return $task;
        });
        NotificationService::notify((int) $task['customer_id'], 'cancelled', 'Your task was cancelled by Helpigo', $reason, '/app/tasks/' . $number, true);
        if ($task['worker_id'] !== null) {
            NotificationService::notify((int) $task['worker_id'], 'cancelled', 'Task cancelled by Helpigo', $reason, '/worker/tasks/' . $number, true);
        }
    }

    /** Prepaid task (BR §4 case C): staff record the item advance sent to the assigned helper. */
    public static function recordAdvance(int $adminId, string $number, string $trxId): void
    {
        $trxId = strtoupper(trim($trxId));
        if (!preg_match('/^[A-Z0-9]{6,30}$/', $trxId)) {
            throw new DomainException('Enter the transaction ID of the transfer.');
        }
        $task = Database::transaction(static function () use ($adminId, $number, $trxId): array {
            $task = self::lock($number);
            if ((int) $task['item_prepaid'] !== 1 || $task['worker_id'] === null || !in_array($task['status'], [TaskStatus::ASSIGNED, TaskStatus::IN_PROGRESS], true)) {
                throw new DomainException('An advance is only sent for a prepaid task with an assigned helper.');
            }
            if (Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'advance_paid'", [$task['id']])) {
                throw new DomainException('The advance was already recorded.');
            }
            $advance = PaymentService::itemAdvance((int) $task['id']);
            LedgerService::worker((int) $task['worker_id'], (int) $task['id'], 'advance_paid', -$advance, 'Item money sent, TrxID ' . $trxId, $adminId);
            AuditService::log('advance_sent', 'task', (int) $task['id'], ['amount' => Money::toDecimal($advance), 'trx' => $trxId], $adminId);
            return $task;
        });
        NotificationService::notify((int) $task['worker_id'], 'advance', 'Item money sent to your wallet', 'You can start the task now.', '/worker/tasks/' . $number, true);
    }

    // ================================================================== helpers

    /** Locks the task row. With $customerId, only that customer's task. */
    private static function lock(string $number, ?int $customerId = null): array
    {
        $task = $customerId === null
            ? Database::one('SELECT * FROM tasks WHERE task_number = ? FOR UPDATE', [$number])
            : Database::one('SELECT * FROM tasks WHERE task_number = ? AND customer_id = ? FOR UPDATE', [$number, $customerId]);
        if ($task === null) {
            throw new DomainException('Task not found.');
        }
        return $task;
    }

    private static function lockForWorker(string $number, int $workerId): array
    {
        $task = Database::one('SELECT * FROM tasks WHERE task_number = ? AND worker_id = ? FOR UPDATE', [$number, $workerId]);
        if ($task === null) {
            throw new DomainException('Task not found.');
        }
        return $task;
    }

    public static function event(int $taskId, ?int $actorId, ?string $from, string $to, ?string $note = null): void
    {
        Database::insert('task_events', ['task_id' => $taskId, 'actor_id' => $actorId, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    /** Reused by validation of money text inputs. */
    public static function money(string $input): ?int
    {
        return Money::tryFromDecimal(str_replace([',', '৳', ' '], '', $input));
    }
}
