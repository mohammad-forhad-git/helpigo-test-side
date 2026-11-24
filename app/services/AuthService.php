<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use DomainException;

/**
 * Accounts for all three roles. Everyone logs in with mobile number + password.
 * - Passwords: password_hash()/password_verify() only.
 * - 5 failed attempts per phone, or 20 per IP, within 15 minutes -> locked for the rest of the window.
 * - Session ID and CSRF token are replaced after login (stops session fixation).
 * - Admin accounts are never created through the website, only with bin/create-admin.php.
 */
final class AuthService
{
    public const MIN_PASSWORD = 8;
    public const MIN_ADMIN_PASSWORD = 12;

    private const WINDOW_MINUTES = 15;
    private const MAX_PER_PHONE = 5;
    private const MAX_PER_IP = 20;
    // Checked when the phone is unknown, so response time does not reveal which numbers have accounts.
    private const DUMMY_HASH = '$2y$12$bmYISo.4xwPlwJLiGv02AONKeU2R4B71EfbMn3yxUBte8Mf7ZXMmO';

    private static ?array $user = null;

    /**
     * Creates a customer or worker account and logs it in.
     * @param array<string, mixed> $input name, phone, password, password_confirmation, role
     * @return int new user id
     */
    public static function register(array $input): int
    {
        $role = (string) ($input['role'] ?? 'customer');
        if (!in_array($role, ['customer', 'worker'], true)) {
            throw new DomainException('Please choose how you want to use Helpigo.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Please enter your name (up to 100 characters).');
        }
        $phone = Validator::normalizeBdPhone((string) ($input['phone'] ?? ''));
        if ($phone === null) {
            throw new DomainException('Please enter a valid Bangladeshi mobile number, e.g. 01712345678.');
        }
        $password = (string) ($input['password'] ?? '');
        self::assertPassword($password, (string) ($input['password_confirmation'] ?? ''), self::MIN_PASSWORD);

        $referralInput = strtoupper(trim((string) ($input['referral'] ?? '')));
        $id = Database::transaction(static function () use ($role, $name, $phone, $password, $referralInput): int {
            if (Database::value('SELECT 1 FROM users WHERE phone = ?', [$phone])) {
                throw new DomainException('An account with this mobile number already exists. Please log in.');
            }
            $referrer = $role === 'customer' && $referralInput !== ''
                ? Database::value("SELECT id FROM users WHERE referral_code = ? AND role = 'customer'", [$referralInput])
                : null;
            if ($referralInput !== '' && $role === 'customer' && $referrer === null) {
                throw new DomainException('That referral code was not found. Check it or leave it empty.');
            }
            $id = Database::insert('users', [
                'role'          => $role,
                'name'          => $name,
                'phone'         => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'referral_code' => $role === 'customer' ? self::newReferralCode() : null,
                'referred_by'   => $referrer !== null ? (int) $referrer : null,
            ]);
            if ($role === 'worker') {
                Database::insert('worker_profiles', [
                    'user_id'     => $id,
                    'float_limit' => (string) setting('float_tier1'),
                ]);
            } else {
                // BR §9: welcome credit for the first task, plus referral credit when invited by a friend.
                LedgerService::customer($id, null, 'promo_credit', Money::fromDecimal((string) setting('first_task_discount')), 'Welcome discount');
                if ($referrer !== null) {
                    LedgerService::customer($id, null, 'referral_credit', Money::fromDecimal((string) setting('referral_credit')), 'Invited by a friend');
                }
            }
            return $id;
        });

        AuditService::log('register', 'user', $id, ['role' => $role], $id);
        self::startSession($id);
        return $id;
    }

    /** @return array{ok:bool, message:string} */
    public static function attempt(string $phoneInput, string $password, string $ip): array
    {
        $phone = Validator::normalizeBdPhone($phoneInput) ?? substr(trim($phoneInput), 0, 20);

        if (self::isLocked($phone, $ip)) {
            Logger::warning('Login blocked by rate limit', ['phone' => mask_phone($phone), 'ip' => $ip]);
            return ['ok' => false, 'message' => 'Too many failed attempts. Please wait 15 minutes and try again.'];
        }

        $user = Database::one('SELECT id, password_hash, status FROM users WHERE phone = ?', [$phone]);
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) && $user !== null;

        Database::insert('login_attempts', ['phone' => $phone, 'ip_address' => $ip, 'success' => $valid ? 1 : 0]);

        if (!$valid) {
            return ['ok' => false, 'message' => 'Mobile number or password is incorrect.'];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'message' => 'This account is suspended. Please contact Helpigo support.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :w_id', ['w_id' => $user['id']]);
        }

        self::startSession((int) $user['id']);
        AuditService::log('login', 'user', (int) $user['id'], [], (int) $user['id']);
        return ['ok' => true, 'message' => 'Welcome back.'];
    }

    private static function startSession(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set('user_id', $userId);
        Session::set('last_activity', time());
        Session::set('login_at', time());
        Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $userId]);
        self::$user = null;
    }

    private static function isLocked(string $phone, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $byPhone = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE phone = ? AND success = 0 AND attempted_at >= ?',
            [$phone, $since]
        );
        $byIp = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $since]
        );
        return $byPhone >= self::MAX_PER_PHONE || $byIp >= self::MAX_PER_IP;
    }

    public static function logout(): void
    {
        if (self::id() !== null) {
            AuditService::log('logout', 'user', self::id());
        }
        foreach (['user_id', 'last_activity', 'login_at', 'intended'] as $key) {
            Session::forget($key);
        }
        self::$user = null;
        Session::regenerate();
        Csrf::rotate();
    }

    public static function id(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    /** The logged-in, active user, or null. */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        if (self::$user === null || (int) self::$user['id'] !== $id) {
            self::$user = Database::one(
                "SELECT id, role, name, phone, email, locale, password_changed_at, phone_verified_at, referral_code FROM users WHERE id = ? AND status = 'active'",
                [$id]
            );
        }
        return self::$user;
    }

    /** True when the password changed after this session logged in (other devices get logged out). */
    public static function sessionIsStale(array $user): bool
    {
        if (empty($user['password_changed_at'])) {
            return false;
        }
        return strtotime((string) $user['password_changed_at']) >= (int) Session::get('login_at', 0);
    }

    public static function assertPassword(string $password, string $confirm, int $min): void
    {
        if (mb_strlen($password) < $min) {
            throw new DomainException("The password must be at least $min characters.");
        }
        if (strlen($password) > 200) {
            throw new DomainException('The password is too long.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new DomainException('The two passwords do not match.');
        }
    }

    private static function newReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I look-alikes
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Database::value('SELECT 1 FROM users WHERE referral_code = ?', [$code]));
        return $code;
    }

    /** ACC-04: send a verification code to the logged-in user's phone. */
    public static function sendVerification(int $userId, string $ip): void
    {
        $phone = (string) Database::value('SELECT phone FROM users WHERE id = ?', [$userId]);
        OtpService::send($phone, 'verify_phone', $ip);
    }

    public static function verifyPhone(int $userId, string $code): void
    {
        $phone = (string) Database::value('SELECT phone FROM users WHERE id = ?', [$userId]);
        if (!OtpService::verify($phone, 'verify_phone', trim($code))) {
            throw new DomainException('That code is wrong or expired. Request a new one.');
        }
        Database::update('users', ['phone_verified_at' => date('Y-m-d H:i:s')], 'id = :w_id', ['w_id' => $userId]);
        self::$user = null;
        AuditService::log('phone_verified', 'user', $userId, [], $userId);
    }

    public static function phoneVerified(int $userId): bool
    {
        return Database::value('SELECT phone_verified_at FROM users WHERE id = ?', [$userId]) !== null;
    }

    /**
     * ACC-05: forgot password. Always behaves the same whether the number exists or not,
     * so the form cannot be used to find out who has an account.
     */
    public static function requestReset(string $phoneInput, string $ip): void
    {
        $phone = Validator::normalizeBdPhone($phoneInput);
        if ($phone === null) {
            throw new DomainException('Please enter a valid Bangladeshi mobile number, e.g. 01712345678.');
        }
        if (Database::value("SELECT 1 FROM users WHERE phone = ? AND status = 'active'", [$phone])) {
            OtpService::send($phone, 'reset_password', $ip);
        } else {
            Logger::info('Password reset for unknown number', ['phone' => mask_phone($phone), 'ip' => $ip]);
        }
    }

    public static function resetPassword(string $phoneInput, string $code, string $password, string $confirm): void
    {
        $phone = Validator::normalizeBdPhone($phoneInput) ?? '';
        $user = Database::one('SELECT id, role FROM users WHERE phone = ?', [$phone]);
        self::assertPassword($password, $confirm, ($user['role'] ?? '') === 'admin' ? self::MIN_ADMIN_PASSWORD : self::MIN_PASSWORD);
        if ($user === null || !OtpService::verify($phone, 'reset_password', trim($code))) {
            throw new DomainException('That code is wrong or expired. Request a new one.');
        }
        Database::update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'phone_verified_at' => date('Y-m-d H:i:s'),   // the code proves they hold the phone
        ], 'id = :w_id', ['w_id' => $user['id']]);
        Database::run('DELETE FROM login_attempts WHERE phone = ? AND success = 0', [$phone]);
        AuditService::log('password_reset', 'user', (int) $user['id'], [], (int) $user['id']);
    }

    /** ACC-06: change password (current one required) and/or language. */
    public static function updateAccount(int $userId, string $locale, string $current, string $new, string $confirm): string
    {
        $fields = [];
        if (in_array($locale, ['en', 'bn'], true)) {
            $fields['locale'] = $locale;
        }
        if ($new !== '') {
            $user = Database::one('SELECT role, password_hash FROM users WHERE id = ?', [$userId]);
            if (!password_verify($current, (string) $user['password_hash'])) {
                throw new DomainException('Your current password is incorrect.');
            }
            self::assertPassword($new, $confirm, $user['role'] === 'admin' ? self::MIN_ADMIN_PASSWORD : self::MIN_PASSWORD);
            $fields['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            $fields['password_changed_at'] = date('Y-m-d H:i:s');
        }
        if ($fields === []) {
            return 'Nothing changed.';
        }
        Database::update('users', $fields, 'id = :w_id', ['w_id' => $userId]);
        if (isset($fields['password_hash'])) {
            // Keep this session; other devices are logged out by sessionIsStale().
            Session::regenerate();
            Session::set('login_at', strtotime($fields['password_changed_at']) + 1);
            AuditService::log('password_change', 'user', $userId, [], $userId);
        }
        self::$user = null;
        return isset($fields['password_hash']) ? 'Saved. Other devices have been logged out.' : 'Saved.';
    }

    /** Where each role lands after login. */
    public static function homeFor(string $role): string
    {
        return match ($role) {
            'admin'  => '/admin',
            'worker' => '/worker',
            default  => '/app',
        };
    }

    /** Removes login attempts older than a day (keeps the table small). */
    public static function pruneAttempts(): void
    {
        Database::run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }

    /** Tests: forget the cached user between simulated requests. */
    public static function forgetCache(): void
    {
        self::$user = null;
    }
}
