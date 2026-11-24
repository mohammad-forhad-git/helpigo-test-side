<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * All money math uses integer paisa (৳1 = 100 paisa).
 * Integers are exact; floats like 0.1 + 0.2 are not. The database stores DECIMAL(12,2),
 * which we convert to and from paisa at the edges.
 */
final class Money
{
    /** "1665.50" -> 166550. Throws on anything that is not a plain decimal with max 2 places. */
    public static function fromDecimal(string $value): int
    {
        $paisa = self::tryFromDecimal($value);
        if ($paisa === null) {
            throw new InvalidArgumentException("Invalid money value: $value");
        }
        return $paisa;
    }

    public static function tryFromDecimal(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(-)?(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $paisa = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$paisa : $paisa;
    }

    /** 166550 -> "1665.50" (for the database) */
    public static function toDecimal(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** 166550 -> "৳1,665.50"; with $alwaysDecimals=false, 35000 -> "৳350" */
    public static function format(int $paisa, bool $alwaysDecimals = true): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);
        $taka = number_format(intdiv($abs, 100));
        $fraction = $abs % 100;
        if (!$alwaysDecimals && $fraction === 0) {
            return $sign . '৳' . $taka;
        }
        return sprintf('%s৳%s.%02d', $sign, $taka, $fraction);
    }

    /**
     * $paisa × $rate, rounded half-up to the nearest paisa.
     * $rate is a decimal string with up to 4 places, e.g. "0.0100" for 1%.
     * Example: 155000 × 0.01 = 1550 (৳15.50). 12345 × 0.01 = 123.45 -> 123.
     */
    public static function percentOf(int $paisa, string $rate): int
    {
        if ($paisa < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }
        $units = self::rateToUnits($rate);
        return intdiv($paisa * $units + 5000, 10000);
    }

    /** "0.0100" -> 100 (rate in 1/10000ths). Allowed range 0 – 1. */
    public static function rateToUnits(string $rate): int
    {
        $rate = trim($rate);
        if (!preg_match('/^(\d)(?:\.(\d{1,4}))?$/', $rate, $m)) {
            throw new InvalidArgumentException("Invalid rate: $rate");
        }
        $units = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0');
        if ($units > 10000) {
            throw new InvalidArgumentException('Rate must be between 0 and 1.');
        }
        return $units;
    }

    /** 100 -> "1%" ; 125 -> "1.25%" */
    public static function rateLabel(string $rate): string
    {
        $units = self::rateToUnits($rate);
        $percent = rtrim(rtrim(sprintf('%d.%02d', intdiv($units, 100), $units % 100), '0'), '.');
        return $percent . '%';
    }
}
