<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use DomainException;
use Throwable;

/**
 * Helper profiles, verification, availability and purchase-limit tiers
 * (requirements HLP-01, HLP-02, ADM-02; docs/business-rules.md §3, §6 strikes, §8).
 */
final class WorkerService
{
    public static function docsDir(): string
    {
        return STORAGE_PATH . '/private/worker-docs';
    }

    public static function profile(int $workerId): ?array
    {
        $profile = Database::one(
            'SELECT p.*, u.name, u.phone, u.phone_verified_at, u.status AS account_status, u.created_at AS joined_at
             FROM worker_profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?',
            [$workerId]
        );
        if ($profile === null) {
            return null;
        }
        $profile['zone_ids'] = array_map('intval', array_column(Database::all('SELECT zone_id FROM worker_zones WHERE worker_id = ?', [$workerId]), 'zone_id'));
        $profile['category_ids'] = array_map('intval', array_column(Database::all('SELECT category_id FROM worker_categories WHERE worker_id = ?', [$workerId]), 'category_id'));
        return $profile;
    }

    /**
     * Saves the profile and submits it for review. Documents are required on first submit;
     * later saves may keep the stored ones. Approved helpers can change zones, services and wallet
     * without a new review; changing identity details sends the profile back to review.
     */
    public static function saveProfile(int $workerId, array $input, ?array $nidFile, ?array $selfieFile): void
    {
        $profile = self::profile($workerId) ?? throw new DomainException('Profile not found.');
        if (in_array($profile['status'], ['suspended'], true)) {
            throw new DomainException('Your account is paused. Please contact Helpigo support.');
        }
        if ($profile['phone_verified_at'] === null) {
            throw new DomainException('Please verify your mobile number first.');
        }

        $v = (new Validator($input))
            ->in('gender', 'gender', ['female', 'male', 'other'])
            ->string('nid_number', 'National ID number', 20, true, 10)
            ->string('reference_name', 'Reference name', 100, true, 3)
            ->bdPhone('reference_phone', 'Reference phone')
            ->in('payout_method', 'payout wallet', ['bkash', 'nagad', 'rocket'])
            ->bool('age_confirm');
        $clean = $v->clean();
        if (!preg_match('/^\d{10}(\d{3}|\d{7})?$/', (string) ($clean['nid_number'] ?? ''))) {
            $v->addError('nid_number', 'National ID number must have 10, 13 or 17 digits.');
        }
        $payout = Validator::normalizeWallet((string) ($input['payout_number'] ?? ''), (string) ($clean['payout_method'] ?? ''));
        if ($payout === null) {
            $v->addError('payout_number', 'Please enter a valid wallet number.');
        }
        if ((int) $clean['age_confirm'] !== 1) {
            $v->addError('age_confirm', 'Helpers must be 18 or older.');
        }
        if (($clean['reference_phone'] ?? null) === $profile['phone']) {
            $v->addError('reference_phone', 'The reference must be another person.');
        }
        $zones = self::ids($input['zones'] ?? [], 'SELECT id FROM zones WHERE is_active = 1');
        $categories = self::ids($input['categories'] ?? [], 'SELECT id FROM categories WHERE is_active = 1');
        if ($zones === []) {
            $v->addError('zones', 'Choose at least one area.');
        }
        if ($categories === []) {
            $v->addError('categories', 'Choose at least one service.');
        }
        if ($nidFile === null && $profile['nid_image'] === null) {
            $v->addError('nid_image', 'Please add a photo of your National ID.');
        }
        if ($selfieFile === null && $profile['selfie_image'] === null) {
            $v->addError('selfie_image', 'Please add a clear photo of your face.');
        }
        if ($v->fails()) {
            throw new ValidationFailed($v->errors());
        }

        $stored = [];
        try {
            $maxBytes = (int) config('app.upload_image_max_mb', 4) * 1024 * 1024;
            $nidName = $nidFile !== null ? ($stored[] = UploadService::storeImage($nidFile, self::docsDir(), $maxBytes, 1600)) : $profile['nid_image'];
            $selfieName = $selfieFile !== null ? ($stored[] = UploadService::storeImage($selfieFile, self::docsDir(), $maxBytes, 800)) : $profile['selfie_image'];

            $identityChanged = $nidFile !== null || $selfieFile !== null
                || $clean['nid_number'] !== $profile['nid_number'] || $clean['gender'] !== $profile['gender'];
            $status = match (true) {
                $profile['status'] === 'approved' && !$identityChanged => 'approved',
                default => 'pending',
            };

            Database::transaction(static function () use ($workerId, $clean, $payout, $nidName, $selfieName, $status, $zones, $categories): void {
                Database::update('worker_profiles', [
                    'gender' => $clean['gender'], 'nid_number' => $clean['nid_number'], 'nid_image' => $nidName,
                    'selfie_image' => $selfieName, 'reference_name' => $clean['reference_name'],
                    'reference_phone' => $clean['reference_phone'], 'payout_method' => $clean['payout_method'],
                    'payout_number' => $payout, 'status' => $status,
                ] + ($status === 'pending' ? ['review_note' => null, 'is_online' => 0] : []), 'user_id = :w_id', ['w_id' => $workerId]);
                Database::run('DELETE FROM worker_zones WHERE worker_id = ?', [$workerId]);
                Database::run('DELETE FROM worker_categories WHERE worker_id = ?', [$workerId]);
                foreach ($zones as $zoneId) {
                    Database::insert('worker_zones', ['worker_id' => $workerId, 'zone_id' => $zoneId]);
                }
                foreach ($categories as $categoryId) {
                    Database::insert('worker_categories', ['worker_id' => $workerId, 'category_id' => $categoryId]);
                }
            });
        } catch (Throwable $e) {
            foreach ($stored as $name) {
                UploadService::delete(self::docsDir(), $name);
            }
            throw $e;
        }
        // Replaced documents are deleted only after the new ones are saved.
        foreach ([[$nidFile, $profile['nid_image']], [$selfieFile, $profile['selfie_image']]] as [$new, $old]) {
            if ($new !== null && $old !== null) {
                UploadService::delete(self::docsDir(), $old);
            }
        }
        if ($status === 'pending' && $profile['status'] !== 'pending') {
            NotificationService::staff('New helper to review', $profile['name'] . ' submitted a helper profile.');
        }
    }

    /** @return list<int> only ids that exist in the allowed list */
    private static function ids(mixed $raw, string $allowedSql): array
    {
        $allowed = array_map('intval', array_column(Database::all($allowedSql), 'id'));
        $ids = array_map('intval', array_filter(is_array($raw) ? $raw : [], 'is_scalar'));
        return array_values(array_unique(array_intersect($ids, $allowed)));
    }

    public static function setOnline(int $workerId, bool $online): void
    {
        $profile = self::profile($workerId) ?? throw new DomainException('Profile not found.');
        if ($online && $profile['status'] !== 'approved') {
            throw new DomainException('You can go online after your profile is approved.');
        }
        Database::update('worker_profiles', ['is_online' => $online ? 1 : 0, 'last_seen_at' => date('Y-m-d H:i:s')], 'user_id = :w_id', ['w_id' => $workerId]);
    }

    public static function touch(int $workerId): void
    {
        Database::run('UPDATE worker_profiles SET last_seen_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s'), $workerId]);
    }

    /** SYS-03: helpers idle for 30 minutes go offline. */
    public static function offlineIdle(): int
    {
        return Database::run('UPDATE worker_profiles SET is_online = 0 WHERE is_online = 1 AND (last_seen_at IS NULL OR last_seen_at < ?)',
            [date('Y-m-d H:i:s', time() - 1800)])->rowCount();
    }

    /** ADM-02: approve, reject (reason required), suspend (reason required) or reactivate. */
    public static function review(int $adminId, int $workerId, string $action, string $note): void
    {
        $profile = self::profile($workerId) ?? throw new DomainException('Helper not found.');
        $note = trim($note);
        $status = match ($action) {
            'approve'  => 'approved',
            'reject'   => 'rejected',
            'suspend'  => 'suspended',
            'reactivate' => 'approved',
            default    => throw new DomainException('Unknown action.'),
        };
        if (in_array($action, ['reject', 'suspend'], true) && $note === '') {
            throw new DomainException('Please write the reason; the helper will see it.');
        }
        if ($action === 'approve' && ($profile['nid_image'] === null || $profile['selfie_image'] === null)) {
            throw new DomainException('This profile has no documents yet.');
        }
        Database::transaction(static function () use ($adminId, $workerId, $status, $note, $action): void {
            Database::update('worker_profiles', [
                'status' => $status, 'review_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'),
            ] + ($status !== 'approved' ? ['is_online' => 0] : []), 'user_id = :w_id', ['w_id' => $workerId]);
            AuditService::log('helper_' . $action, 'worker', $workerId, $note !== '' ? ['note' => $note] : [], $adminId);
        });
        $messages = [
            'approve'    => ['You are approved as a Helpigo helper', 'Go online to start receiving tasks.'],
            'reject'     => ['Your helper profile needs changes', $note],
            'suspend'    => ['Your helper account is paused', $note],
            'reactivate' => ['Your helper account is active again', null],
        ];
        [$title, $body] = $messages[$action];
        NotificationService::notify($workerId, 'helper_review', $title, $body, '/worker', true);
    }

    public static function setFloat(int $adminId, int $workerId, int $paisa): void
    {
        if ($paisa < 0 || $paisa > 10_000_000) {
            throw new DomainException('Invalid purchase limit.');
        }
        Database::update('worker_profiles', ['float_limit' => Money::toDecimal($paisa)], 'user_id = :w_id', ['w_id' => $workerId]);
        AuditService::log('helper_float', 'worker', $workerId, ['float_limit' => Money::toDecimal($paisa)], $adminId);
    }

    /** BR §3: purchase limit from the helper's record. Never lowers a limit an admin raised above tier 3. */
    public static function recalcTier(int $workerId): void
    {
        $p = Database::one('SELECT completed_count, rating_avg, rating_count, float_limit FROM worker_profiles WHERE user_id = ?', [$workerId]);
        if ($p === null) {
            return;
        }
        $t1 = Money::fromDecimal((string) setting('float_tier1'));
        $t2 = Money::fromDecimal((string) setting('float_tier2'));
        $t3 = Money::fromDecimal((string) setting('float_tier3'));
        $current = Money::fromDecimal((string) $p['float_limit']);
        if ($current > $t3) {
            return;
        }
        $done = (int) $p['completed_count'];
        $goodRating = (int) $p['rating_count'] === 0 || (float) $p['rating_avg'] >= 4.5;
        $upheld = (int) Database::value(
            "SELECT COUNT(*) FROM complaints WHERE against_id = ? AND status = 'resolved' AND resolved_at > ?",
            [$workerId, date('Y-m-d H:i:s', time() - 90 * 86400)]
        );
        $limit = match (true) {
            $done >= 60 && $goodRating && $upheld === 0 => $t3,
            $done >= 20 && $goodRating => $t2,
            default => $t1,
        };
        if ($limit !== $current) {
            Database::update('worker_profiles', ['float_limit' => Money::toDecimal($limit)], 'user_id = :w_id', ['w_id' => $workerId]);
            if ($limit > $current) {
                NotificationService::notify($workerId, 'float', 'Your purchase limit is now ' . Money::format($limit, false), 'You can take bigger shopping tasks.', '/worker');
            }
        }
    }

    /** Strikes = tasks the helper dropped after accepting, in the last 30 days (BR §6). */
    public static function strikes(int $workerId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM task_events WHERE actor_id = ? AND from_status = 'assigned' AND to_status = 'open' AND created_at > ?",
            [$workerId, date('Y-m-d H:i:s', time() - 30 * 86400)]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function listForAdmin(string $status = ''): array
    {
        $where = $status !== '' ? 'WHERE p.status = ?' : '';
        return Database::all(
            "SELECT p.*, u.name, u.phone, u.created_at AS joined_at,
                    (SELECT COALESCE(SUM(amount), 0) FROM worker_ledger l WHERE l.worker_id = p.user_id) AS balance
             FROM worker_profiles p JOIN users u ON u.id = p.user_id $where
             ORDER BY FIELD(p.status, 'pending', 'approved', 'incomplete', 'rejected', 'suspended'), u.id DESC",
            $status !== '' ? [$status] : []
        );
    }
}
