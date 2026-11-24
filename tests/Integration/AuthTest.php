<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\AuthService;

$register = static function (array $override = []): int {
    return AuthService::register($override + [
        'role' => 'customer', 'name' => 'Rahima Akter', 'phone' => '01712345678',
        'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
    ]);
};

test('customer registers, is logged in and lands on /app', function () use ($register) {
    reset_test_db();
    fresh_session();
    $id = $register();
    assert_same($id, AuthService::id());
    assert_same('customer', AuthService::user()['role']);
    assert_same('/app', AuthService::homeFor('customer'));
    assert_true(password_verify('secret-pass', (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$id])), 'password is hashed');
});

test('worker registration creates an incomplete worker profile with the default purchase limit', function () use ($register) {
    reset_test_db();
    fresh_session();
    $id = $register(['role' => 'worker', 'phone' => '01812345678']);
    $profile = Database::one('SELECT status, float_limit FROM worker_profiles WHERE user_id = ?', [$id]);
    assert_same('incomplete', $profile['status']);
    assert_same('1000.00', (string) $profile['float_limit']);
});

test('registration rejects duplicate phones in any format, admin role, weak and mismatched passwords', function () use ($register) {
    reset_test_db();
    fresh_session();
    $register();
    fresh_session();
    assert_throws(DomainException::class, fn () => $register(['phone' => '+8801712345678']), 'already exists');
    assert_throws(DomainException::class, fn () => $register(['phone' => '01912345678', 'role' => 'admin']), 'choose');
    assert_throws(DomainException::class, fn () => $register(['phone' => '01912345678', 'password' => 'short', 'password_confirmation' => 'short']), 'at least');
    assert_throws(DomainException::class, fn () => $register(['phone' => '01912345678', 'password_confirmation' => 'different-pass']), 'do not match');
    assert_throws(DomainException::class, fn () => $register(['phone' => '12345']), 'valid');
    assert_same(1, (int) Database::value('SELECT COUNT(*) FROM users'));
});

test('login works with any phone format; wrong password fails; suspended accounts are refused', function () use ($register) {
    reset_test_db();
    fresh_session();
    $id = $register();
    fresh_session();
    assert_same(false, AuthService::attempt('01712345678', 'wrong-pass', '10.0.0.1')['ok']);
    assert_same(null, AuthService::id());
    assert_same(true, AuthService::attempt('+880 1712-345678', 'secret-pass', '10.0.0.1')['ok']);
    assert_same($id, AuthService::id());

    fresh_session();
    Database::run("UPDATE users SET status = 'suspended' WHERE id = ?", [$id]);
    $result = AuthService::attempt('01712345678', 'secret-pass', '10.0.0.1');
    assert_same(false, $result['ok']);
    assert_true(str_contains($result['message'], 'suspended'), 'suspension message shown');
});

test('5 failed logins lock the phone for 15 minutes, even with the right password', function () use ($register) {
    reset_test_db();
    fresh_session();
    $register();
    fresh_session();
    for ($i = 0; $i < 5; $i++) {
        AuthService::attempt('01712345678', 'wrong-pass', '10.0.0.' . $i);
    }
    $result = AuthService::attempt('01712345678', 'secret-pass', '10.0.0.9');
    assert_same(false, $result['ok']);
    assert_true(str_contains($result['message'], 'Too many'), 'lockout message');
});

test('logout clears the session user', function () use ($register) {
    reset_test_db();
    fresh_session();
    $register();
    AuthService::logout();
    assert_same(null, AuthService::id());
    assert_same(null, AuthService::user());
});
