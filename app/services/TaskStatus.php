<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * The task state machine (docs/business-rules.md §6). Every status change must pass allows();
 * TaskService writes the change and a task_events row in one transaction.
 */
final class TaskStatus
{
    public const OPEN = 'open';
    public const ASSIGNED = 'assigned';
    public const IN_PROGRESS = 'in_progress';
    public const DELIVERING = 'delivering';
    public const AWAITING = 'awaiting_confirmation';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const DISPUTED = 'disputed';

    /** Statuses in which a helper is working on the task (details visible to them). */
    public const ACTIVE = [self::ASSIGNED, self::IN_PROGRESS, self::DELIVERING, self::AWAITING];
    /** Statuses that never change again. */
    public const FINAL = [self::COMPLETED, self::CANCELLED, self::EXPIRED];

    /** from => [to => roles allowed to make that move]. "system" = cron jobs. */
    private const TRANSITIONS = [
        self::OPEN => [
            self::ASSIGNED  => ['worker', 'customer'],   // helper accepts; customer accepts an offer
            self::EXPIRED   => ['system'],
            self::CANCELLED => ['customer', 'admin'],
        ],
        self::ASSIGNED => [
            self::IN_PROGRESS => ['worker'],
            self::CANCELLED   => ['customer', 'admin'],
            self::OPEN        => ['worker', 'admin'],     // helper drops the task (strike)
        ],
        self::IN_PROGRESS => [
            self::DELIVERING => ['worker'],
            self::CANCELLED  => ['customer', 'admin'],
        ],
        self::DELIVERING => [
            self::COMPLETED => ['worker'],                // with the delivery code
            self::AWAITING  => ['worker'],
            self::DISPUTED  => ['customer'],
        ],
        self::AWAITING => [
            self::COMPLETED => ['customer', 'system'],
            self::DISPUTED  => ['customer'],
        ],
        self::COMPLETED => [
            self::DISPUTED => ['customer'],               // within the dispute window
        ],
        self::DISPUTED => [
            self::COMPLETED => ['admin'],
            self::CANCELLED => ['admin'],
        ],
    ];

    private const LABELS = [
        self::OPEN        => 'Finding a helper',
        self::ASSIGNED    => 'Helper assigned',
        self::IN_PROGRESS => 'In progress',
        self::DELIVERING  => 'On the way',
        self::AWAITING    => 'Waiting for your confirmation',
        self::COMPLETED   => 'Completed',
        self::CANCELLED   => 'Cancelled',
        self::EXPIRED     => 'Expired',
        self::DISPUTED    => 'Under review',
    ];

    public static function allows(string $from, string $to, string $role): bool
    {
        return in_array($role, self::TRANSITIONS[$from][$to] ?? [], true);
    }

    public static function assert(string $from, string $to, string $role): void
    {
        if (!self::allows($from, $to, $role)) {
            throw new DomainException('This task can no longer be changed this way.');
        }
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }
}
