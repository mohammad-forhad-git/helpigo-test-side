<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

/**
 * Task chat between the customer and the assigned helper (SHR-01). Writable while the task is
 * active; readable afterwards for 24 hours (and always by admins).
 */
final class MessageService
{
    /** @return array{task: array, can_write: bool} or throws when the user may not read the chat */
    public static function access(array $user, string $number): array
    {
        $task = TaskFlowService::load($number) ?? throw new DomainException('Task not found.');
        $role = $user['role'];
        $party = ($role === 'customer' && (int) $task['customer_id'] === (int) $user['id'])
            || ($role === 'worker' && $task['worker_id'] !== null && (int) $task['worker_id'] === (int) $user['id']);
        if ($role !== 'admin' && !$party) {
            throw new DomainException('Task not found.');
        }
        if ($task['worker_id'] === null) {
            throw new DomainException('Chat opens when a helper accepts the task.');
        }
        $active = TaskStatus::isActive($task['status']) || $task['status'] === TaskStatus::DISPUTED;
        $recent = strtotime((string) $task['updated_at']) > time() - 86400;
        if ($role !== 'admin' && !$active && !$recent) {
            throw new DomainException('This chat is closed.');
        }
        return ['task' => $task, 'can_write' => $party && $active];
    }

    public static function send(array $user, string $number, string $body): void
    {
        $access = self::access($user, $number);
        if (!$access['can_write']) {
            throw new DomainException('This chat is closed.');
        }
        $body = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $body));
        if ($body === '' || mb_strlen($body) > 1000) {
            throw new DomainException('Messages are 1 to 1000 characters.');
        }
        $task = $access['task'];
        Database::insert('messages', ['task_id' => $task['id'], 'sender_id' => $user['id'], 'body' => $body]);
        $to = (int) $user['id'] === (int) $task['customer_id'] ? (int) $task['worker_id'] : (int) $task['customer_id'];
        $link = $to === (int) $task['customer_id'] ? '/app/tasks/' . $number : '/worker/tasks/' . $number;
        // One unread "new message" notification per task at a time, to avoid flooding.
        if (!Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'message' AND link = ? AND read_at IS NULL", [$to, $link])) {
            NotificationService::notify($to, 'message', 'New message from ' . $user['name'], mb_substr($body, 0, 120), $link);
        }
    }

    /** Messages after $afterId; marks the other side's messages read. */
    public static function list(array $user, string $number, int $afterId = 0): array
    {
        $access = self::access($user, $number);
        $taskId = (int) $access['task']['id'];
        $rows = Database::all(
            'SELECT m.id, m.sender_id, m.body, m.created_at, u.name AS sender_name FROM messages m JOIN users u ON u.id = m.sender_id
             WHERE m.task_id = ? AND m.id > ? ORDER BY m.id LIMIT 200',
            [$taskId, $afterId]
        );
        if ($user['role'] !== 'admin') {
            Database::run('UPDATE messages SET read_at = ? WHERE task_id = ? AND sender_id <> ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $taskId, $user['id']]);
        }
        return ['messages' => $rows, 'can_write' => $access['can_write']];
    }
}
