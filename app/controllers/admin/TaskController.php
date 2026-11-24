<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\TaskFlowService;
use App\Services\TaskStatus;

/** ADM-03, ADM-06, ADM-11: watch tasks, see everything about one, cancel, send advances, decide disputes. */
final class TaskController extends AdminController
{
    public function index(Request $request): void
    {
        $status = in_array($request->query('status'), TaskStatus::all(), true) ? $request->query('status') : '';
        $q = trim($request->query('q'));
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 't.status = ?';
            $params[] = $status;
        } elseif ($request->query('view') !== 'all') {
            $where[] = "t.status IN ('open','assigned','in_progress','delivering','awaiting_confirmation','disputed')";
        }
        if ($request->query('review') === '1') {
            $where[] = 't.needs_review = 1';
        }
        if ($q !== '') {
            $where[] = '(t.task_number LIKE ? OR t.title LIKE ? OR cu.phone LIKE ? OR w.phone LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $tasks = Database::all(
            'SELECT t.task_number, t.title, t.status, t.created_at, t.preferred_at, t.worker_fee, t.item_prepaid, t.payment_method,
                    c.name AS category_name, z.name AS zone_name, cu.name AS customer_name, w.name AS worker_name
             FROM tasks t JOIN categories c ON c.id = t.category_id JOIN zones z ON z.id = t.zone_id
             JOIN users cu ON cu.id = t.customer_id LEFT JOIN users w ON w.id = t.worker_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.id DESC LIMIT 200',
            $params
        );
        $this->page('tasks/index', ['title' => 'Tasks', 'tasks' => $tasks, 'status' => $status, 'q' => $q, 'view' => $request->query('view')]);
    }

    public function show(Request $request, string $number): void
    {
        $task = TaskFlowService::load($number) ?? $this->notFound();
        $id = (int) $task['id'];
        $this->page('tasks/show', [
            'title'       => $task['task_number'],
            'task'        => $task,
            'items'       => Database::all('SELECT * FROM task_items WHERE task_id = ? ORDER BY sort_order, id', [$id]),
            'attachments' => Database::all('SELECT * FROM task_attachments WHERE task_id = ? ORDER BY id', [$id]),
            'events'      => Database::all('SELECT e.*, u.name AS actor_name, u.role AS actor_role FROM task_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.task_id = ? ORDER BY e.id', [$id]),
            'offers'      => Database::all('SELECT o.*, u.name AS worker_name FROM task_offers o JOIN users u ON u.id = o.worker_id WHERE o.task_id = ? ORDER BY o.id', [$id]),
            'approvals'   => Database::all('SELECT * FROM task_approvals WHERE task_id = ? ORDER BY id', [$id]),
            'messages'    => Database::all('SELECT m.*, u.name AS sender_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.task_id = ? ORDER BY m.id', [$id]),
            'payments'    => PaymentService::forTask($id),
            'workerRows'  => Database::all('SELECT * FROM worker_ledger WHERE task_id = ? ORDER BY id', [$id]),
            'customerRows'=> Database::all('SELECT * FROM customer_ledger WHERE task_id = ? ORDER BY id', [$id]),
            'complaints'  => Database::all('SELECT * FROM complaints WHERE task_id = ? ORDER BY id', [$id]),
            'reviews'     => Database::all('SELECT r.*, u.name AS reviewer_name FROM reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.task_id = ?', [$id]),
            'advanceSent' => (bool) Database::value("SELECT 1 FROM worker_ledger WHERE task_id = ? AND type = 'advance_paid'", [$id]),
            'advance'     => PaymentService::itemAdvance($id),
        ]);
    }

    public function cancel(Request $request, string $number): void
    {
        $this->attempt('/admin/tasks/' . rawurlencode($number), function () use ($request, $number): string {
            TaskFlowService::cancelByAdmin($this->adminId(), $number, $request->str('reason'));
            return 'Task cancelled. Both sides were notified.';
        });
    }

    public function advance(Request $request, string $number): void
    {
        $this->attempt('/admin/tasks/' . rawurlencode($number), function () use ($request, $number): string {
            TaskFlowService::recordAdvance($this->adminId(), $number, $request->str('trx_id'));
            return 'Advance recorded. The helper can start.';
        });
    }

    public function resolve(Request $request, string $number): void
    {
        $this->attempt('/admin/tasks/' . rawurlencode($number), function () use ($request, $number): string {
            $refund = $request->str('refund') !== '' ? (Money::tryFromDecimal(str_replace(',', '', $request->str('refund'))) ?? -1) : 0;
            if ($refund < 0) {
                throw new \DomainException('Refund must be an amount like 50 or 50.50.');
            }
            TaskFlowService::resolveDispute($this->adminId(), $number, $request->str('outcome'), $request->str('resolution'), $refund);
            return 'Decision saved and sent to both sides.';
        });
    }

    public function clearReview(Request $request, string $number): void
    {
        $this->attempt('/admin/tasks/' . rawurlencode($number), function () use ($number): string {
            Database::run('UPDATE tasks SET needs_review = 0 WHERE task_number = ?', [$number]);
            \App\Services\AuditService::log('task_review_cleared', 'task', (int) Database::value('SELECT id FROM tasks WHERE task_number = ?', [$number]), [], $this->adminId());
            return 'Task released to helpers.';
        });
    }
}
