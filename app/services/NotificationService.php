<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * In-app notifications (SHR-02), optional SMS for key events (SHR-03) and staff email alerts.
 * Never throws: a failed notification must not undo the action that caused it.
 */
final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, bool $sms = false): void
    {
        try {
            Database::insert('notifications', [
                'user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 150),
                'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'link' => $link,
            ]);
            if ($sms) {
                $phone = Database::value('SELECT phone FROM users WHERE id = ?', [$userId]);
                if (is_string($phone)) {
                    SmsService::send($phone, 'Helpigo: ' . $title . ($body ? ' ' . $body : ''));
                }
            }
        } catch (Throwable $e) {
            Logger::error('Notification failed', ['user' => $userId, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** Email to the operations inbox (OPS_EMAIL). */
    public static function staff(string $subject, string $body): void
    {
        $to = (string) config('app.ops_email', '');
        if ($to === '') {
            return;
        }
        try {
            Mailer::send($to, 'Helpigo: ' . $subject, $body . "\n\n" . rtrim((string) config('app.url'), '/') . "/admin\n");
        } catch (Throwable $e) {
            Logger::error('Staff email failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function latest(int $userId, int $limit = 50): array
    {
        return Database::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$userId]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $userId]);
    }
}
