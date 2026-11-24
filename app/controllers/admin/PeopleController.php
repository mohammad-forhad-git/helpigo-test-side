<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\ComplaintService;
use App\Services\LedgerService;
use App\Services\TaskService;
use DomainException;

/** ADM-09 customers and ADM-06 complaints. */
final class PeopleController extends AdminController
{
    public function customers(Request $request): void
    {
        $q = trim($request->query('q'));
        $customers = Database::all(
            "SELECT u.id, u.name, u.phone, u.status, u.created_at, u.phone_verified_at,
                    (SELECT COUNT(*) FROM tasks t WHERE t.customer_id = u.id) AS tasks,
                    (SELECT COALESCE(SUM(amount), 0) FROM customer_ledger l WHERE l.customer_id = u.id) AS balance
             FROM users u WHERE u.role = 'customer'" . ($q !== '' ? ' AND (u.name LIKE ? OR u.phone LIKE ?)' : '') . ' ORDER BY u.id DESC LIMIT 200',
            $q !== '' ? ["%$q%", "%$q%"] : []
        );
        $this->page('people/customers', ['title' => 'Customers', 'customers' => $customers, 'q' => $q]);
    }

    public function customer(Request $request, string $id): void
    {
        $user = Database::one("SELECT * FROM users WHERE id = ? AND role = 'customer'", [(int) $id]) ?? $this->notFound();
        $this->page('people/customer', [
            'title'     => $user['name'],
            'user'      => $user,
            'tasks'     => array_merge(TaskService::listForCustomer((int) $id, 'active', 50), TaskService::listForCustomer((int) $id, 'past', 50)),
            'balance'   => LedgerService::customerBalance((int) $id),
            'credit'    => LedgerService::customerCredit((int) $id),
            'rows'      => LedgerService::customerRows((int) $id, 50),
            'addresses' => \App\Services\AddressService::forUser((int) $id),
        ]);
    }

    public function setStatus(Request $request, string $id): void
    {
        $this->attempt('/admin/customers/' . (int) $id, function () use ($request, $id): string {
            $status = $request->str('status') === 'suspended' ? 'suspended' : 'active';
            if ($status === 'suspended' && $request->str('reason') === '') {
                throw new DomainException('Please write the reason.');
            }
            Database::run("UPDATE users SET status = ? WHERE id = ? AND role <> 'admin'", [$status, (int) $id]);
            AuditService::log('user_' . $status, 'user', (int) $id, ['reason' => $request->str('reason')], $this->adminId());
            return $status === 'suspended' ? 'Account suspended.' : 'Account active again.';
        });
    }

    public function complaints(Request $request): void
    {
        $filter = $request->query('show') === 'closed' ? 'closed' : 'open';
        $this->page('people/complaints', ['title' => 'Complaints', 'filter' => $filter, 'complaints' => ComplaintService::listForAdmin($filter)]);
    }

    public function closeComplaint(Request $request, string $id): void
    {
        $this->attempt('/admin/complaints', function () use ($request, $id): string {
            ComplaintService::close($this->adminId(), (int) $id, $request->str('status'), $request->str('resolution'));
            return 'Complaint updated.';
        });
    }
}
