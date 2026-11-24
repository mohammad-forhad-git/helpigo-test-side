<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

/** Complaints, reports and SOS (CUS-13, SHR-05, ADM-06). */
final class ComplaintService
{
    public const TYPES = [
        'not_delivered' => 'Not delivered',
        'wrong_items'   => 'Wrong or missing items',
        'overcharge'    => 'Charged too much',
        'behaviour'     => 'Rude or unprofessional',
        'safety'        => 'Safety concern',
        'payment'       => 'Payment problem',
        'other'         => 'Something else',
    ];

    public static function create(int $reporterId, ?int $taskId, ?int $againstId, string $type, string $description, bool $sos = false): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new DomainException('Choose what went wrong.');
        }
        $id = Database::insert('complaints', [
            'task_id' => $taskId, 'reporter_id' => $reporterId, 'against_id' => $againstId, 'type' => $type,
            'priority' => $sos || $type === 'safety' ? 1 : 0, 'description' => mb_substr(trim($description), 0, 1000),
        ]);
        NotificationService::staff($sos ? 'SOS / safety report' : 'New complaint', self::TYPES[$type] . ': ' . mb_substr($description, 0, 300));
        return $id;
    }

    /** SOS from an active task (customer or helper). */
    public static function sos(array $user, string $number, string $description): void
    {
        $task = TaskFlowService::load($number) ?? throw new DomainException('Task not found.');
        $isCustomer = (int) $task['customer_id'] === (int) $user['id'];
        $isWorker = (int) $task['worker_id'] === (int) $user['id'];
        if (!$isCustomer && !$isWorker) {
            throw new DomainException('Task not found.');
        }
        $against = $isCustomer ? ($task['worker_id'] !== null ? (int) $task['worker_id'] : null) : (int) $task['customer_id'];
        self::create((int) $user['id'], (int) $task['id'], $against, 'safety', trim($description) !== '' ? $description : 'SOS pressed', true);
    }

    /** @return list<array<string, mixed>> */
    public static function listForAdmin(string $filter): array
    {
        $where = $filter === 'closed' ? "c.status IN ('resolved','rejected')" : "c.status IN ('open','in_review')";
        return Database::all(
            "SELECT c.*, r.name AS reporter_name, r.role AS reporter_role, a.name AS against_name, t.task_number, t.status AS task_status
             FROM complaints c JOIN users r ON r.id = c.reporter_id LEFT JOIN users a ON a.id = c.against_id
             LEFT JOIN tasks t ON t.id = c.task_id WHERE $where ORDER BY c.priority DESC, c.id DESC LIMIT 200"
        );
    }

    /** Close a complaint that is not tied to a disputed task (disputes close via TaskFlowService::resolveDispute). */
    public static function close(int $adminId, int $id, string $status, string $resolution): void
    {
        $resolution = trim($resolution);
        if (!in_array($status, ['in_review', 'resolved', 'rejected'], true) || ($status !== 'in_review' && $resolution === '')) {
            throw new DomainException('Choose a status and write the decision.');
        }
        $c = Database::one('SELECT * FROM complaints WHERE id = ?', [$id]) ?? throw new DomainException('Complaint not found.');
        Database::update('complaints', [
            'status' => $status, 'resolution' => $resolution !== '' ? mb_substr($resolution, 0, 1000) : null,
            'handled_by' => $adminId, 'resolved_at' => $status === 'in_review' ? null : date('Y-m-d H:i:s'),
        ], 'id = :w_id', ['w_id' => $id]);
        AuditService::log('complaint_' . $status, 'complaint', $id, [], $adminId);
        if ($status !== 'in_review') {
            NotificationService::notify((int) $c['reporter_id'], 'complaint', 'Your report was reviewed', mb_substr($resolution, 0, 200));
            if ($status === 'resolved' && $c['against_id'] !== null) {
                WorkerService::recalcTier((int) $c['against_id']);
            }
        }
    }
}
