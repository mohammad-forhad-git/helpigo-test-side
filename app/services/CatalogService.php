<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Service categories and zones (admin-managed lists). */
final class CatalogService
{
    /** @return list<array<string, mixed>> */
    public static function categories(): array
    {
        return Database::all('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, id');
    }

    public static function category(string $code): ?array
    {
        return Database::one('SELECT * FROM categories WHERE code = ? AND is_active = 1', [$code]);
    }

    /** @return list<array<string, mixed>> */
    public static function zones(): array
    {
        return Database::all('SELECT * FROM zones WHERE is_active = 1 ORDER BY sort_order, id');
    }

    public static function zoneIsActive(int $zoneId): bool
    {
        return (bool) Database::value('SELECT 1 FROM zones WHERE id = ? AND is_active = 1', [$zoneId]);
    }
}
