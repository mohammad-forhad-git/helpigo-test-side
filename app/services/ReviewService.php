<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

/** Ratings both ways (CUS-12, HLP-10): completed tasks only, once per side, within 7 days. */
final class ReviewService
{
    public const WINDOW_DAYS = 7;

    public static function rate(array $user, string $number, int $rating, string $comment): void
    {
        $task = TaskFlowService::load($number) ?? throw new DomainException('Task not found.');
        $isCustomer = $user['role'] === 'customer' && (int) $task['customer_id'] === (int) $user['id'];
        $isWorker = $user['role'] === 'worker' && (int) $task['worker_id'] === (int) $user['id'];
        if (!$isCustomer && !$isWorker) {
            throw new DomainException('Task not found.');
        }
        if ($task['status'] !== TaskStatus::COMPLETED || strtotime((string) $task['completed_at']) < time() - self::WINDOW_DAYS * 86400) {
            throw new DomainException('You can rate a completed task within ' . self::WINDOW_DAYS . ' days.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new DomainException('Choose 1 to 5 stars.');
        }
        $reviewee = $isCustomer ? (int) $task['worker_id'] : (int) $task['customer_id'];
        Database::transaction(static function () use ($task, $user, $reviewee, $rating, $comment, $isCustomer): void {
            try {
                Database::insert('reviews', [
                    'task_id' => $task['id'], 'reviewer_id' => $user['id'], 'reviewee_id' => $reviewee,
                    'rating' => $rating, 'comment' => mb_substr(trim($comment), 0, 500) ?: null,
                ]);
            } catch (\Throwable $e) {
                if (Database::isDuplicateKey($e)) {
                    throw new DomainException('You already rated this task.');
                }
                throw $e;
            }
            if ($isCustomer) {
                $stats = Database::one('SELECT AVG(rating) AS avg_rating, COUNT(*) AS n FROM reviews WHERE reviewee_id = ?', [$reviewee]);
                Database::update('worker_profiles', ['rating_avg' => number_format((float) $stats['avg_rating'], 2, '.', ''), 'rating_count' => (int) $stats['n']],
                    'user_id = :w_id', ['w_id' => $reviewee]);
            }
        });
        if ($isCustomer) {
            WorkerService::recalcTier($reviewee);
            $p = Database::one('SELECT rating_avg, rating_count FROM worker_profiles WHERE user_id = ?', [$reviewee]);
            if ((int) $p['rating_count'] >= 10 && (float) $p['rating_avg'] < 4.0) {
                NotificationService::staff('Helper rating below 4.0', "Helper #$reviewee average {$p['rating_avg']} after {$p['rating_count']} ratings.");
            }
        }
    }

    public static function mine(int $taskId, int $userId): ?array
    {
        return Database::one('SELECT * FROM reviews WHERE task_id = ? AND reviewer_id = ?', [$taskId, $userId]);
    }
}
