<?php

declare(strict_types=1);

use App\Services\TaskStatus as S;

test('BR6 allowed moves', function () {
    $allowed = [
        ['open', 'assigned', 'worker'], ['open', 'assigned', 'customer'], ['open', 'expired', 'system'],
        ['open', 'cancelled', 'customer'], ['assigned', 'in_progress', 'worker'], ['assigned', 'open', 'worker'],
        ['assigned', 'cancelled', 'customer'], ['in_progress', 'delivering', 'worker'], ['in_progress', 'cancelled', 'customer'],
        ['delivering', 'completed', 'worker'], ['delivering', 'awaiting_confirmation', 'worker'], ['delivering', 'disputed', 'customer'],
        ['awaiting_confirmation', 'completed', 'customer'], ['awaiting_confirmation', 'completed', 'system'],
        ['awaiting_confirmation', 'disputed', 'customer'], ['completed', 'disputed', 'customer'],
        ['disputed', 'completed', 'admin'], ['disputed', 'cancelled', 'admin'],
    ];
    foreach ($allowed as [$from, $to, $role]) {
        assert_true(S::allows($from, $to, $role), "$role should move $from -> $to");
    }
});

test('BR6 forbidden moves', function () {
    $forbidden = [
        ['open', 'completed', 'worker'], ['open', 'expired', 'customer'], ['open', 'in_progress', 'worker'],
        ['assigned', 'completed', 'worker'], ['delivering', 'completed', 'customer'], ['delivering', 'cancelled', 'customer'],
        ['completed', 'cancelled', 'customer'], ['cancelled', 'open', 'customer'], ['expired', 'open', 'system'],
        ['disputed', 'completed', 'customer'], ['awaiting_confirmation', 'completed', 'worker'],
    ];
    foreach ($forbidden as [$from, $to, $role]) {
        assert_true(!S::allows($from, $to, $role), "$role must not move $from -> $to");
    }
    assert_throws(DomainException::class, fn () => S::assert('completed', 'open', 'admin'));
});

test('final statuses have no way out except a dispute of a completed task', function () {
    foreach (S::FINAL as $from) {
        foreach (S::all() as $to) {
            foreach (['customer', 'worker', 'admin', 'system'] as $role) {
                if (S::allows($from, $to, $role)) {
                    assert_true($from === 'completed' && $to === 'disputed', "unexpected exit $from -> $to");
                }
            }
        }
    }
});
