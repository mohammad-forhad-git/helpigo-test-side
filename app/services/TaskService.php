<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Validator;
use DomainException;
use Throwable;

/**
 * Creating, reading and changing tasks. Rules: docs/business-rules.md §2, §3, §6, §8.
 * Every status change goes through TaskStatus and writes a task_events row in the same transaction.
 * Methods take the acting user's id and check ownership themselves.
 */
final class TaskService
{
    public const MAX_ITEMS = 40;
    public const MAX_LIST_PHOTOS = 3;
    public const MAX_PRESCRIPTION_PHOTOS = 2;

    /**
     * Validates a new-task form. Returns [clean data, errors]. Files are checked in create().
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(int $customerId, array $category, array $input): array
    {
        $purchase = (int) $category['involves_purchase'] === 1;
        $v = (new Validator($input))
            ->string('title', 'Task title', 120, true, 3)
            ->string('description', 'Details', 1000, false)
            ->string('pickup_location', $purchase ? 'Shop or market' : 'Pick-up point', 255, $category['code'] !== 'custom')
            ->in('when', 'time', ['asap', 'later'])
            ->in('payment_method', 'payment method', ['cash', 'bkash', 'nagad'])
            ->bool('prefer_female')
            ->bool('has_prescription');

        $tier3 = Money::fromDecimal((string) setting('float_tier3'));
        if ($purchase) {
            $v->money('item_budget', 'Item budget', 100, $tier3);
        }
        if ($category['pricing_mode'] === 'offer') {
            $v->money('offer_fee', 'Your fee offer', Money::fromDecimal((string) $category['base_fee']), 1_000_000);
        }

        $clean = $v->clean();
        $clean['item_budget'] = $purchase ? ($clean['item_budget'] ?? null) : 0;
        $clean['offer_fee'] ??= null;
        $clean['has_prescription'] = (int) $category['allows_prescription'] === 1 ? $clean['has_prescription'] : 0;

        // Drop-off address: one of the customer's saved addresses in an active zone.
        $clean['address'] = null;
        if ((int) $category['needs_dropoff'] === 1) {
            $address = AddressService::find($customerId, (int) ($input['address_id'] ?? 0));
            if ($address === null) {
                $v->addError('address_id', 'Please choose a drop-off address.');
            } elseif ((int) $address['zone_active'] !== 1) {
                $v->addError('address_id', 'We do not serve this area yet.');
            } else {
                $clean['address'] = $address;
            }
        }

        // Preferred time: as soon as possible, or between 30 minutes and 7 days from now.
        $clean['preferred_at'] = null;
        if (($clean['when'] ?? '') === 'later') {
            $ts = strtotime((string) ($input['preferred_at'] ?? ''));
            if ($ts === false || $ts < time() + 1800 || $ts > time() + 7 * 86400) {
                $v->addError('preferred_at', 'Choose a time between 30 minutes and 7 days from now.');
            } else {
                $clean['preferred_at'] = date('Y-m-d H:i:00', $ts);
            }
        }

        $clean['items'] = self::parseItems($input);
        if (count($clean['items']) > self::MAX_ITEMS) {
            $v->addError('items', 'Please list at most ' . self::MAX_ITEMS . ' items, or add a photo of your list.');
        }

        return [$clean, $v->errors()];
    }

    /**
     * Creates an open task. $files = ['list' => [...uploads], 'prescription' => [...uploads]].
     * @return string task number
     */
    public static function create(int $customerId, array $category, array $clean, array $files = []): string
    {
        $purchase = (int) $category['involves_purchase'] === 1;
        $listFiles = $files['list'] ?? [];
        $rxFiles = $files['prescription'] ?? [];

        if (count($listFiles) > self::MAX_LIST_PHOTOS || count($rxFiles) > self::MAX_PRESCRIPTION_PHOTOS) {
            throw new DomainException('Too many photos. Up to ' . self::MAX_LIST_PHOTOS . ' list photos and ' . self::MAX_PRESCRIPTION_PHOTOS . ' prescription photos.');
        }
        if ($purchase && $clean['items'] === [] && $listFiles === [] && $rxFiles === []) {
            throw new DomainException('Please list what to buy, or add a photo of your list.');
        }
        if ((int) $clean['has_prescription'] === 1 && $rxFiles === []) {
            throw new DomainException('Prescription medicines need a photo of the prescription.');
        }
        self::assertCustomerMayPost($customerId);

        // BR §9: available credit (first-task discount, referral, refunds) is applied automatically.
        $credit = LedgerService::customerCredit($customerId);
        $quote = PricingService::current($category, count($clean['items']), $clean['offer_fee'], $credit);

        // Store photos first (outside the transaction); remove them again if saving the task fails.
        $stored = [];
        try {
            foreach (['list' => $listFiles, 'prescription' => $rxFiles] as $kind => $list) {
                foreach ($list as $file) {
                    $stored[] = [$kind, PrivateFileService::storeTaskImage($file)];
                }
            }

            return Database::transaction(static function () use ($customerId, $category, $clean, $quote, $stored): string {
                $address = $clean['address'];
                $start = $clean['preferred_at'] !== null ? strtotime($clean['preferred_at']) : time();
                $number = self::newTaskNumber();

                $taskId = Database::insert('tasks', [
                    'task_number'      => $number,
                    'customer_id'      => $customerId,
                    'category_id'      => (int) $category['id'],
                    'zone_id'          => (int) ($address['zone_id'] ?? self::fallbackZone($customerId)),
                    'title'            => $clean['title'],
                    'description'      => $clean['description'],
                    'pickup_location'  => $clean['pickup_location'],
                    'dropoff_address'  => $address['address_line'] ?? null,
                    'dropoff_landmark' => $address['landmark'] ?? null,
                    'recipient_name'   => $address['contact_name'] ?? null,
                    'recipient_phone'  => $address['contact_phone'] ?? null,
                    'preferred_at'     => $clean['preferred_at'],
                    'item_budget'      => Money::toDecimal((int) $clean['item_budget']),
                    'worker_fee'       => Money::toDecimal($quote['worker_fee']),
                    'platform_fee'     => Money::toDecimal($quote['platform_fee']),
                    'commission'       => Money::toDecimal($quote['commission']),
                    'discount'         => Money::toDecimal($quote['discount']),
                    'payment_method'   => $clean['payment_method'],
                    'prefer_female_worker' => (int) $clean['prefer_female'],
                    'status'           => TaskStatus::OPEN,
                    'needs_review'     => self::looksProhibited($clean) ? 1 : 0,
                    'delivery_code'    => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                    'expires_at'       => date('Y-m-d H:i:s', $start + (int) setting('open_task_expiry_minutes') * 60),
                ]);

                foreach ($clean['items'] as $i => $item) {
                    Database::insert('task_items', [
                        'task_id' => $taskId, 'name' => $item['name'], 'quantity' => $item['quantity'],
                        'note' => $item['note'], 'sort_order' => $i,
                    ]);
                }
                foreach ($stored as [$kind, $name]) {
                    Database::insert('task_attachments', [
                        'task_id' => $taskId, 'uploaded_by' => $customerId, 'kind' => $kind, 'file_name' => $name,
                    ]);
                }
                if ($quote['discount'] > 0) {
                    LedgerService::customer($customerId, $taskId, 'credit_used', -$quote['discount'], 'Credit applied to ' . $number);
                }
                self::event($taskId, $customerId, null, TaskStatus::OPEN);
                return $number;
            });
        } catch (Throwable $e) {
            foreach ($stored as [, $name]) {
                UploadService::delete(PrivateFileService::taskDir(), $name);
            }
            throw $e;
        }
    }

    /** BR §8: no unpaid dues; new customers may have at most N open or active tasks. */
    private static function assertCustomerMayPost(int $customerId): void
    {
        if (Database::value('SELECT phone_verified_at FROM users WHERE id = ?', [$customerId]) === null) {
            throw new DomainException('Please verify your mobile number first.');
        }
        // Payments waiting for verification count as paid, so customers are not blocked while staff check them.
        $balance = LedgerService::customerBalance($customerId)
            + Money::fromDecimal((string) (Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payer_id = ? AND purpose IN ('fees','due') AND status = 'pending'", [$customerId]) ?? '0'));
        if ($balance < 0) {
            throw new DomainException('Please pay your outstanding ' . Money::format(-$balance) . ' before posting a new task.');
        }
        $completed = (int) Database::value("SELECT COUNT(*) FROM tasks WHERE customer_id = ? AND status = 'completed'", [$customerId]);
        if ($completed === 0) {
            $live = (int) Database::value(
                "SELECT COUNT(*) FROM tasks WHERE customer_id = ? AND status IN ('open','assigned','in_progress','delivering','awaiting_confirmation')",
                [$customerId]
            );
            $max = (int) setting('new_customer_max_active');
            if ($live >= $max) {
                throw new DomainException("New accounts can have up to $max tasks running at once. Please wait for one to finish.");
            }
        }
    }

    /** Custom tasks without a drop-off still need a zone for matching: the customer's first saved address. */
    private static function fallbackZone(int $customerId): int
    {
        $zone = Database::value('SELECT zone_id FROM addresses WHERE user_id = ? ORDER BY id LIMIT 1', [$customerId]);
        if ($zone === null) {
            throw new DomainException('Please save an address first so we know your area.');
        }
        return (int) $zone;
    }

    /** Words that send a task to admin review before helpers see it (BR §10). English and Bangla. */
    private const FLAGGED = [
        'yaba', 'ganja', 'heroin', 'phensedyl', 'fensidyl', 'cocaine', 'weed', 'alcohol', 'beer', 'wine', 'whisky', 'vodka',
        'gun', 'pistol', 'weapon', 'knife', 'bullet', 'cash delivery', 'deliver cash', 'send money to',
        'ইয়াবা', 'গাঁজা', 'ফেনসিডিল', 'মদ', 'অস্ত্র', 'পিস্তল', 'ছুরি', 'নগদ টাকা',
    ];

    /** True when the title, details or items mention something on the prohibited list. */
    public static function looksProhibited(array $clean): bool
    {
        $text = mb_strtolower(implode(' ', array_merge(
            [(string) ($clean['title'] ?? ''), (string) ($clean['description'] ?? ''), (string) ($clean['pickup_location'] ?? '')],
            array_map(static fn (array $i): string => $i['name'] . ' ' . ($i['note'] ?? ''), $clean['items'] ?? [])
        )));
        foreach (self::FLAGGED as $word) {
            if (preg_match('/(^|[^\p{L}])' . preg_quote($word, '/') . '($|[^\p{L}])/u', $text)) {
                return true;
            }
        }
        return false;
    }

    /** "HG-261116-4821". Random suffix; the unique key is the final guard. */
    private static function newTaskNumber(): string
    {
        $prefix = (string) setting('task_prefix') . '-' . date('ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $number = $prefix . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!Database::value('SELECT 1 FROM tasks WHERE task_number = ?', [$number])) {
                return $number;
            }
        }
        throw new \RuntimeException('Could not create a task number.');
    }

    /** @return list<array{name:string, quantity:?string, note:?string}> */
    private static function parseItems(array $input): array
    {
        $names = is_array($input['item_name'] ?? null) ? $input['item_name'] : [];
        $qtys = is_array($input['item_qty'] ?? null) ? $input['item_qty'] : [];
        $notes = is_array($input['item_note'] ?? null) ? $input['item_note'] : [];
        $clip = static fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== ''
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', trim($v)), 0, $max) : null;

        $items = [];
        foreach (array_values($names) as $i => $name) {
            $name = $clip($name, 120);
            if ($name === null) {
                continue;
            }
            $items[] = ['name' => $name, 'quantity' => $clip($qtys[$i] ?? null, 40), 'note' => $clip($notes[$i] ?? null, 255)];
        }
        return $items;
    }

    // ------------------------------------------------------------------ reading

    /** The customer's own task with category, zone, items, files and events; null if not theirs. */
    public static function findForCustomer(int $customerId, string $number): ?array
    {
        $task = Database::one(
            'SELECT t.*, c.name AS category_name, c.code AS category_code, c.icon AS category_icon,
                    c.pricing_mode, c.involves_purchase, z.name AS zone_name
             FROM tasks t JOIN categories c ON c.id = t.category_id JOIN zones z ON z.id = t.zone_id
             WHERE t.task_number = ? AND t.customer_id = ?',
            [$number, $customerId]
        );
        if ($task === null) {
            return null;
        }
        $task['items'] = Database::all('SELECT * FROM task_items WHERE task_id = ? ORDER BY sort_order, id', [$task['id']]);
        $task['attachments'] = Database::all('SELECT id, kind, created_at FROM task_attachments WHERE task_id = ? ORDER BY id', [$task['id']]);
        $task['events'] = Database::all('SELECT to_status, note, created_at FROM task_events WHERE task_id = ? ORDER BY id', [$task['id']]);
        return $task;
    }

    /** @return list<array<string, mixed>> */
    public static function listForCustomer(int $customerId, string $filter = 'active', int $limit = 50): array
    {
        $statuses = $filter === 'past' ? TaskStatus::FINAL : array_merge([TaskStatus::OPEN, TaskStatus::DISPUTED], TaskStatus::ACTIVE);
        return Database::all(
            'SELECT t.task_number, t.title, t.status, t.created_at, t.preferred_at, t.worker_fee, t.platform_fee, t.discount,
                    c.name AS category_name, c.icon AS category_icon
             FROM tasks t JOIN categories c ON c.id = t.category_id
             WHERE t.customer_id = ? AND t.status IN (' . Database::placeholders(count($statuses)) . ')
             ORDER BY t.id DESC LIMIT ' . max(1, min(200, $limit)),
            array_merge([$customerId], $statuses)
        );
    }

    // ------------------------------------------------------------------ changes

    /** SYS-01: open tasks past their expiry become expired. Returns how many. Safe to run repeatedly. */
    public static function expireDue(): int
    {
        $ids = Database::all("SELECT id FROM tasks WHERE status = 'open' AND expires_at < ? LIMIT 500", [date('Y-m-d H:i:s')]);
        $count = 0;
        foreach ($ids as $row) {
            $count += (int) Database::transaction(static function () use ($row): bool {
                $task = Database::one('SELECT * FROM tasks WHERE id = ? FOR UPDATE', [$row['id']]);
                if ($task === null || $task['status'] !== TaskStatus::OPEN) {
                    return false;   // accepted or cancelled in the meantime
                }
                Database::update('tasks', ['status' => TaskStatus::EXPIRED], 'id = :w_id', ['w_id' => $task['id']]);
                self::event((int) $task['id'], null, TaskStatus::OPEN, TaskStatus::EXPIRED, 'No helper accepted in time');
                LedgerService::onTaskClosed($task);
                NotificationService::notify((int) $task['customer_id'], 'expired', 'No helper was free for your task', 'You can post it again.', '/app/tasks/' . $task['task_number'], true);
                return true;
            });
        }
        if ($count > 0) {
            Logger::info('Expired open tasks', ['count' => $count]);
        }
        return $count;
    }

    private static function event(int $taskId, ?int $actorId, ?string $from, string $to, ?string $note = null): void
    {
        Database::insert('task_events', [
            'task_id' => $taskId, 'actor_id' => $actorId, 'from_status' => $from, 'to_status' => $to, 'note' => $note,
        ]);
    }
}
