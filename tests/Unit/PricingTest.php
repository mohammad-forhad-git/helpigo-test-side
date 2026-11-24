<?php

declare(strict_types=1);

use App\Services\PricingService;

$grocery = ['pricing_mode' => 'fixed', 'base_fee' => '100.00', 'included_items' => 15, 'per_item_fee' => '3.00'];
$medicine = ['pricing_mode' => 'fixed', 'base_fee' => '80.00', 'included_items' => 0, 'per_item_fee' => '0.00'];
$custom = ['pricing_mode' => 'offer', 'base_fee' => '80.00', 'included_items' => 0, 'per_item_fee' => '0.00'];

test('BR2 grocery with 15 items: fee 100, commission 15, platform 15', function () use ($grocery) {
    $q = PricingService::quote($grocery, 15, null, '15.00', '0.1500');
    assert_same(10000, $q['worker_fee']);
    assert_same(1500, $q['commission']);
    assert_same(1500, $q['platform_fee']);
    assert_same(11500, $q['customer_fees']);
    assert_same(8500, $q['worker_net']);
    assert_same(3000, $q['helpigo_revenue']);
});

test('BR2 grocery with 18 items adds 3 extra items at ৳3 (testing.md case)', function () use ($grocery) {
    $q = PricingService::quote($grocery, 18, null, '15.00', '0.1500');
    assert_same(10900, $q['worker_fee']);
    assert_same(1635, $q['commission']);
});

test('BR2 per-item fee is ignored when the category includes no item allowance', function () use ($medicine) {
    $q = PricingService::quote($medicine, 30, null, '15.00', '0.1500');
    assert_same(8000, $q['worker_fee']);
    assert_same(1200, $q['commission']);
});

test('BR2 offer tasks use the offer, never below the minimum', function () use ($custom) {
    $q = PricingService::quote($custom, 0, 12000, '15.00', '0.1500');
    assert_same(12000, $q['worker_fee']);
    assert_same(1800, $q['commission']);
    assert_throws(DomainException::class, fn () => PricingService::quote($custom, 0, 7999, '15.00', '0.1500'), 'at least');
    assert_throws(DomainException::class, fn () => PricingService::quote($custom, 0, null, '15.00', '0.1500'));
});

test('BR9 discount reduces what the customer pays and Helpigo revenue, never the helper', function () use ($grocery) {
    $q = PricingService::quote($grocery, 1, null, '15.00', '0.1500', 5000);
    assert_same(5000, $q['discount']);
    assert_same(6500, $q['customer_fees']);
    assert_same(8500, $q['worker_net']);
    assert_same(-2000, $q['helpigo_revenue']);
    $big = PricingService::quote($grocery, 1, null, '15.00', '0.1500', 99999);
    assert_same(11500, $big['discount'], 'discount capped at the fees total');
});

test('BR2 commission rounds half up to the paisa', function () use ($custom) {
    // 15% of ৳80.10 = 12.015 -> 12.02
    assert_same(1202, PricingService::quote($custom, 0, 8010, '15.00', '0.1500')['commission']);
});
