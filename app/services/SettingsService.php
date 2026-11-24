<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Admin-editable settings stored in site_settings (fees, limits, contact info...). */
final class SettingsService
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** Fallbacks if a setting row is missing. */
    private const DEFAULTS = [
        'site_name'                => 'Helpigo',
        'site_tagline'             => 'Your Task, Our People.',
        'platform_fee'             => '15.00',
        'commission_rate'          => '0.1500',
        'task_prefix'              => 'HG',
        'open_task_expiry_minutes' => '60',
        'auto_complete_hours'      => '24',
        'dispute_window_hours'     => '24',
        'refund_hours'             => '48',
        'float_tier1'              => '1000.00',
        'float_tier2'              => '3000.00',
        'float_tier3'              => '5000.00',
        'max_worker_dues'          => '500.00',
        'min_payout'               => '100.00',
        'payout_weekday'           => '0',
        'cancel_fee_assigned'      => '30.00',
        'strike_limit'             => '3',
        'first_task_discount'      => '50.00',
        'referral_credit'          => '50.00',
        'new_customer_max_active'  => '2',
        'service_hours'            => '8:00 AM – 10:00 PM',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT setting_key, setting_value FROM site_settings') as $row) {
                self::$cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    /** @param array<string, string> $values */
    public static function setMany(array $values): void
    {
        Database::transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                Database::run(
                    'INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['k' => $key, 'v' => $value]
                );
            }
        });
        self::$cache = null;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
