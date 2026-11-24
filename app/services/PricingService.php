<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Task prices (docs/business-rules.md §2). Pure functions on integer paisa, no database access,
 * so the same numbers are used for the price shown before booking and the values stored.
 */
final class PricingService
{
    /**
     * @param array<string, mixed> $category row from `categories`
     * @param int $itemCount number of item rows (photo-only list = 0)
     * @param int|null $offerPaisa customer's suggested fee or accepted offer (offer categories only)
     * @param int $discountPaisa credit available to the customer (applied up to the fees total)
     * @return array{worker_fee:int, platform_fee:int, commission:int, discount:int, fees_total:int, customer_fees:int, worker_net:int, helpigo_revenue:int}
     */
    public static function quote(array $category, int $itemCount, ?int $offerPaisa, string $platformFee, string $commissionRate, int $discountPaisa = 0): array
    {
        $base = Money::fromDecimal((string) $category['base_fee']);

        if ($category['pricing_mode'] === 'offer') {
            if ($offerPaisa === null) {
                throw new DomainException('Please suggest a fee for this task.');
            }
            if ($offerPaisa < $base) {
                throw new DomainException('The fee must be at least ' . Money::format($base, false) . '.');
            }
            $workerFee = $offerPaisa;
        } else {
            $extraItems = max(0, $itemCount - (int) $category['included_items']);
            $perItem = (int) $category['included_items'] > 0 ? Money::fromDecimal((string) $category['per_item_fee']) : 0;
            $workerFee = $base + $extraItems * $perItem;
        }

        $platform = Money::fromDecimal($platformFee);
        $commission = Money::percentOf($workerFee, $commissionRate);
        $feesTotal = $workerFee + $platform;
        $discount = max(0, min($discountPaisa, $feesTotal));

        return [
            'worker_fee'      => $workerFee,
            'platform_fee'    => $platform,
            'commission'      => $commission,
            'discount'        => $discount,
            'fees_total'      => $feesTotal,
            'customer_fees'   => $feesTotal - $discount,
            'worker_net'      => $workerFee - $commission,
            // Discounts are paid by Helpigo, never by the helper.
            'helpigo_revenue' => $platform + $commission - $discount,
        ];
    }

    /** Quote with the current settings. */
    public static function current(array $category, int $itemCount, ?int $offerPaisa = null, int $discountPaisa = 0): array
    {
        return self::quote(
            $category,
            $itemCount,
            $offerPaisa,
            (string) setting('platform_fee'),
            (string) setting('commission_rate'),
            $discountPaisa
        );
    }
}
