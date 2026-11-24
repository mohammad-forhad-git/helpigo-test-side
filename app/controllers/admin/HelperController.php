<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\CatalogService;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\PrivateFileService;
use App\Services\TaskFlowService;
use App\Services\WorkerService;
use DomainException;

/** ADM-02: helper review, documents, status, purchase limit, money. */
final class HelperController extends AdminController
{
    public function index(Request $request): void
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'incomplete', 'rejected', 'suspended'], true) ? $request->query('status') : '';
        $this->page('helpers/index', ['title' => 'Helpers', 'status' => $status, 'helpers' => WorkerService::listForAdmin($status)]);
    }

    public function show(Request $request, string $id): void
    {
        $profile = WorkerService::profile((int) $id) ?? $this->notFound();
        $this->page('helpers/show', [
            'title'      => $profile['name'],
            'p'          => $profile,
            'zones'      => array_column(CatalogService::zones(), 'name', 'id'),
            'categories' => array_column(CatalogService::categories(), 'name', 'id'),
            'balance'    => LedgerService::workerBalance((int) $id),
            'rows'       => LedgerService::workerRows((int) $id, 50),
            'strikes'    => WorkerService::strikes((int) $id),
            'tasks'      => TaskFlowService::workerTasks((int) $id, true),
            'active'     => TaskFlowService::workerTasks((int) $id, false),
            'payout'     => Database::one("SELECT * FROM payout_requests WHERE worker_id = ? AND status = 'pending'", [(int) $id]),
            'log'        => Database::all("SELECT a.*, u.name AS admin_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity_type = 'worker' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 20", [(int) $id]),
        ]);
    }

    public function document(Request $request, string $id, string $kind): void
    {
        $column = match ($kind) { 'nid' => 'nid_image', 'selfie' => 'selfie_image', default => $this->notFound() };
        $file = Database::value("SELECT $column FROM worker_profiles WHERE user_id = ?", [(int) $id]);
        if (!is_string($file)) {
            $this->notFound();
        }
        PrivateFileService::stream(WorkerService::docsDir(), $file);
    }

    public function review(Request $request, string $id): void
    {
        $this->attempt('/admin/helpers/' . (int) $id, function () use ($request, $id): string {
            WorkerService::review($this->adminId(), (int) $id, $request->str('action'), $request->str('note'));
            return 'Saved. The helper has been notified.';
        });
    }

    public function float(Request $request, string $id): void
    {
        $this->attempt('/admin/helpers/' . (int) $id, function () use ($request, $id): string {
            $paisa = Money::tryFromDecimal(str_replace(',', '', $request->str('float_limit'))) ?? throw new DomainException('Enter an amount.');
            WorkerService::setFloat($this->adminId(), (int) $id, $paisa);
            return 'Purchase limit updated.';
        });
    }
}
