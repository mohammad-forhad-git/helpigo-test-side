<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\Money;
use App\Services\ReportService;
use App\Services\SettingsService;
use DomainException;

/** ADM-07 settings, ADM-08 zones and services, ADM-10 reports. */
final class SettingsController extends AdminController
{
    /** key => [label, kind, help]. kind: money | int | rate | text | phone */
    public const FIELDS = [
        'platform_fee'             => ['Platform fee per task (৳)', 'money', 'Charged to the customer on every task.'],
        'commission_rate'          => ['Commission rate', 'rate', 'Share of the helper fee kept by Helpigo, e.g. 0.15 = 15%.'],
        'open_task_expiry_minutes' => ['Open task expiry (minutes)', 'int', 'After this, an unaccepted task closes.'],
        'auto_complete_hours'      => ['Auto-complete after (hours)', 'int', 'Delivered without code → completes if no issue.'],
        'dispute_window_hours'     => ['Problem report window (hours)', 'int', ''],
        'refund_hours'             => ['Refund promise (hours)', 'int', 'Shown in the refund policy.'],
        'float_tier1'              => ['Purchase limit: new helper (৳)', 'money', ''],
        'float_tier2'              => ['Purchase limit: 20 tasks, 4.5★ (৳)', 'money', ''],
        'float_tier3'              => ['Purchase limit: 60 tasks, 4.5★ (৳)', 'money', 'Also the maximum item budget per task.'],
        'max_worker_dues'          => ['Helper dues cap (৳)', 'money', 'Helpers owing more cannot take tasks.'],
        'min_payout'               => ['Minimum payout (৳)', 'money', ''],
        'cancel_fee_assigned'      => ['Cancellation fee after accept (৳)', 'money', 'Paid to the helper.'],
        'strike_limit'             => ['Strikes in 30 days before pause', 'int', ''],
        'first_task_discount'      => ['Welcome credit (৳)', 'money', 'For every new customer.'],
        'referral_credit'          => ['Referral credit (৳)', 'money', 'For both sides.'],
        'new_customer_max_active'  => ['Max running tasks for new customers', 'int', ''],
        'bkash_number'             => ['Helpigo bKash number', 'phone', 'Customers send wallet payments here. Empty = hidden.'],
        'nagad_number'             => ['Helpigo Nagad number', 'phone', ''],
        'support_phone'            => ['Support phone', 'phone', 'Shown on SOS and policy pages.'],
        'support_email'            => ['Support email', 'text', ''],
        'service_hours'            => ['Service hours', 'text', 'e.g. 8:00 AM – 10:00 PM'],
    ];

    public function index(Request $request): void
    {
        $this->page('settings', [
            'title'      => 'Settings',
            'fields'     => self::FIELDS,
            'zones'      => Database::all('SELECT z.*, (SELECT COUNT(*) FROM tasks t WHERE t.zone_id = z.id) AS used FROM zones z ORDER BY sort_order, id'),
            'categories' => Database::all('SELECT * FROM categories ORDER BY sort_order, id'),
        ]);
    }

    public function save(Request $request): void
    {
        $this->attempt('/admin/settings', function () use ($request): string {
            $values = [];
            foreach (self::FIELDS as $key => [$label, $kind]) {
                $raw = trim($request->str($key));
                $values[$key] = match ($kind) {
                    'money' => Money::toDecimal(Money::tryFromDecimal(str_replace(',', '', $raw)) ?? throw new DomainException("$label: enter an amount.")),
                    'int'   => preg_match('/^\d{1,6}$/', $raw) && (int) $raw > 0 ? $raw : throw new DomainException("$label: enter a whole number above 0."),
                    'rate'  => preg_match('/^0(\.\d{1,4})?$/', $raw) ? $raw : throw new DomainException("$label: enter a rate between 0 and 0.9999."),
                    'phone' => $raw === '' ? '' : (Validator::normalizeBdPhone($raw) ?? throw new DomainException("$label: enter a valid mobile number.")),
                    default => mb_substr($raw, 0, 120),
                };
            }
            if (Money::fromDecimal($values['float_tier1']) > Money::fromDecimal($values['float_tier2']) || Money::fromDecimal($values['float_tier2']) > Money::fromDecimal($values['float_tier3'])) {
                throw new DomainException('Purchase limits must increase from tier 1 to tier 3.');
            }
            SettingsService::setMany($values);
            AuditService::log('settings_update', 'settings', null, $values, $this->adminId());
            return 'Settings saved. New tasks use the new values; existing tasks keep theirs.';
        });
    }

    public function saveZone(Request $request): void
    {
        $this->attempt('/admin/settings#zones', function () use ($request): string {
            $name = trim($request->str('name'));
            if ($name === '' || mb_strlen($name) > 80) {
                throw new DomainException('Zone name is required.');
            }
            $id = $request->int('id');
            $data = ['name' => $name, 'name_bn' => trim($request->str('name_bn')) ?: null, 'is_active' => $request->str('is_active') === '1' ? 1 : 0, 'sort_order' => $request->int('sort_order')];
            if ($id > 0) {
                Database::update('zones', $data, 'id = :w_id', ['w_id' => $id]);
            } else {
                $id = Database::insert('zones', $data + ['slug' => slugify($name) . '-' . bin2hex(random_bytes(2))]);
            }
            AuditService::log('zone_save', 'zone', $id, $data, $this->adminId());
            return 'Zone saved.';
        });
    }

    public function saveCategory(Request $request, string $id): void
    {
        $this->attempt('/admin/settings#services', function () use ($request, $id): string {
            $base = Money::tryFromDecimal(str_replace(',', '', $request->str('base_fee'))) ?? throw new DomainException('Enter the helper fee.');
            $perItem = Money::tryFromDecimal(str_replace(',', '', $request->str('per_item_fee') ?: '0')) ?? throw new DomainException('Enter the per-item fee.');
            $included = $request->int('included_items');
            if ($base <= 0 || $included < 0 || $included > 1000) {
                throw new DomainException('Check the fee and included items.');
            }
            $data = [
                'name' => mb_substr(trim($request->str('name')), 0, 80) ?: throw new DomainException('Name is required.'),
                'description' => mb_substr(trim($request->str('description')), 0, 255) ?: null,
                'base_fee' => Money::toDecimal($base), 'per_item_fee' => Money::toDecimal($perItem), 'included_items' => $included,
                'is_active' => $request->str('is_active') === '1' ? 1 : 0,
            ];
            Database::update('categories', $data, 'id = :w_id', ['w_id' => (int) $id]);
            AuditService::log('category_save', 'category', (int) $id, $data, $this->adminId());
            return 'Service saved.';
        });
    }

    public function reports(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $this->page('reports', [
            'title' => 'Reports', 'from' => $from, 'to' => $to,
            'kpis'  => ReportService::kpis($from, $to),
            'daily' => ReportService::daily($from, $to),
        ]);
    }

    public function csv(Request $request): void
    {
        [$from, $to] = $this->range($request);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="helpigo-daily-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['date', 'tasks_created', 'tasks_completed', 'revenue_bdt']);
        foreach (ReportService::daily($from, $to) as $row) {
            fputcsv($out, [$row['date'], $row['created'], $row['completed'], Money::toDecimal($row['revenue'])]);
        }
        fclose($out);
    }

    /** @return array{0:string, 1:string} from, to (max 92 days) */
    private function range(Request $request): array
    {
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('to')) ? $request->query('to') : date('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('from')) ? $request->query('from') : date('Y-m-d', strtotime($to . ' -13 days'));
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
            $from = date('Y-m-d', strtotime($to . ' -92 days'));
        }
        return [$from, $to];
    }
}
