<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Numbers for the admin dashboard and reports (ADM-01, ADM-10; proposal §17 pilot targets). */
final class ReportService
{
    /** Live operations counts. */
    public static function live(): array
    {
        $n = static fn (string $sql, array $p = []): int => (int) Database::value($sql, $p);
        return [
            'open_tasks'       => $n("SELECT COUNT(*) FROM tasks WHERE status = 'open'"),
            'old_open_tasks'   => $n("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND created_at < ?", [date('Y-m-d H:i:s', time() - 1800)]),
            'active_tasks'     => $n("SELECT COUNT(*) FROM tasks WHERE status IN ('assigned','in_progress','delivering','awaiting_confirmation')"),
            'disputed'         => $n("SELECT COUNT(*) FROM tasks WHERE status = 'disputed'"),
            'to_review'        => $n("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND needs_review = 1"),
            'helpers_online'   => $n('SELECT COUNT(*) FROM worker_profiles WHERE is_online = 1'),
            'helpers_pending'  => $n("SELECT COUNT(*) FROM worker_profiles WHERE status = 'pending'"),
            'payments_pending' => $n("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'payouts_pending'  => $n("SELECT COUNT(*) FROM payout_requests WHERE status = 'pending'"),
            'complaints_open'  => $n("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_review')"),
            'sos_open'         => $n("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_review') AND priority = 1"),
            'advances_to_send' => $n("SELECT COUNT(*) FROM tasks t WHERE t.item_prepaid = 1 AND t.status = 'assigned'
                                      AND NOT EXISTS (SELECT 1 FROM worker_ledger l WHERE l.task_id = t.id AND l.type = 'advance_paid')"),
        ];
    }

    /** @return list<array{zone:string, online:int}> */
    public static function onlineByZone(): array
    {
        return Database::all(
            'SELECT z.name AS zone, COUNT(p.user_id) AS online FROM zones z
             LEFT JOIN worker_zones wz ON wz.zone_id = z.id
             LEFT JOIN worker_profiles p ON p.user_id = wz.worker_id AND p.is_online = 1
             WHERE z.is_active = 1 GROUP BY z.id, z.name ORDER BY z.sort_order'
        );
    }

    /** Pilot KPIs for a date range (inclusive). Money in paisa. */
    public static function kpis(string $from, string $to): array
    {
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $one = static fn (string $sql, array $p = []): mixed => Database::value($sql, $p);
        $created = (int) $one('SELECT COUNT(*) FROM tasks WHERE created_at BETWEEN ? AND ?', $range);
        $completed = (int) $one("SELECT COUNT(*) FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range);
        $cancelled = (int) $one("SELECT COUNT(*) FROM tasks WHERE status IN ('cancelled','expired') AND created_at BETWEEN ? AND ?", $range);
        $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

        // Median minutes from posting to assignment.
        $waits = array_map('intval', array_column(Database::all(
            'SELECT TIMESTAMPDIFF(MINUTE, created_at, assigned_at) AS m FROM tasks WHERE assigned_at IS NOT NULL AND created_at BETWEEN ? AND ? ORDER BY m',
            $range
        ), 'm'));
        $median = $waits === [] ? null : $waits[intdiv(count($waits), 2)];

        $revenue = (int) round(100 * (float) $one(
            "SELECT COALESCE(SUM(platform_fee + commission - discount), 0) FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range
        ));
        $customers = (int) $one("SELECT COUNT(DISTINCT customer_id) FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range);
        $repeat = (int) $one(
            "SELECT COUNT(*) FROM (SELECT customer_id FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ? GROUP BY customer_id HAVING COUNT(*) > 1) x", $range
        );
        $complaints = (int) $one("SELECT COUNT(*) FROM complaints WHERE status = 'resolved' AND task_id IS NOT NULL AND created_at BETWEEN ? AND ?", $range);

        return [
            'days'             => $days,
            'created'          => $created,
            'completed'        => $completed,
            'completed_per_day'=> round($completed / $days, 1),
            'completion_rate'  => $created > 0 ? round(100 * $completed / $created, 1) : null,
            'cancel_rate'      => $created > 0 ? round(100 * $cancelled / $created, 1) : null,
            'median_wait'      => $median,
            'avg_rating'       => ($r = $one('SELECT AVG(r.rating) FROM reviews r JOIN users u ON u.id = r.reviewee_id WHERE u.role = \'worker\' AND r.created_at BETWEEN ? AND ?', $range)) !== null ? round((float) $r, 2) : null,
            'repeat_rate'      => $customers > 0 ? round(100 * $repeat / $customers, 1) : null,
            'complaint_rate'   => $completed > 0 ? round(100 * $complaints / $completed, 1) : null,
            'revenue'          => $revenue,
            'revenue_per_task' => $completed > 0 ? intdiv($revenue, $completed) : 0,
            'customers'        => (int) $one("SELECT COUNT(*) FROM users WHERE role = 'customer'"),
            'helpers_approved' => (int) $one("SELECT COUNT(*) FROM worker_profiles WHERE status = 'approved'"),
        ];
    }

    /** One row per day: tasks created, completed, revenue (paisa). */
    public static function daily(string $from, string $to): array
    {
        $rows = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = date('Y-m-d', $d);
            $range = [$day . ' 00:00:00', $day . ' 23:59:59'];
            $rows[] = [
                'date'      => $day,
                'created'   => (int) Database::value('SELECT COUNT(*) FROM tasks WHERE created_at BETWEEN ? AND ?', $range),
                'completed' => (int) Database::value("SELECT COUNT(*) FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range),
                'revenue'   => (int) round(100 * (float) Database::value("SELECT COALESCE(SUM(platform_fee + commission - discount), 0) FROM tasks WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range)),
            ];
        }
        return $rows;
    }
}
