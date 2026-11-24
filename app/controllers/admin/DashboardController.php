<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\ReportService;

/** ADM-01: what needs attention now. */
final class DashboardController extends AdminController
{
    public function index(Request $request): void
    {
        $this->page('dashboard', [
            'title'  => 'Dashboard',
            'live'   => ReportService::live(),
            'zones'  => ReportService::onlineByZone(),
            'today'  => ReportService::kpis(date('Y-m-d'), date('Y-m-d')),
            'oldest' => Database::all(
                "SELECT t.task_number, t.title, t.created_at, z.name AS zone_name, c.name AS category_name
                 FROM tasks t JOIN zones z ON z.id = t.zone_id JOIN categories c ON c.id = t.category_id
                 WHERE t.status = 'open' ORDER BY t.created_at LIMIT 10"
            ),
        ]);
    }
}
