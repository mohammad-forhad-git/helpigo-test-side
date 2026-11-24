<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use DomainException;

/**
 * One-time SMS codes (docs/business-rules.md §8): 6 digits, valid 10 minutes, 5 tries,
 * at most 3 sends per hour per phone and 10 per IP. Only a hash of the code is stored.
 */
final class OtpService
{
    public const VALID_MINUTES = 10;
    public const MAX_TRIES = 5;
    private const PER_PHONE_HOUR = 3;
    private const PER_IP_HOUR = 10;

    /** Sends a code. Throws DomainException when a send limit is reached. Returns the code (tests read it from the SMS log). */
    public static function send(string $phone, string $purpose, string $ip): string
    {
        $hourAgo = date('Y-m-d H:i:s', time() - 3600);
        if ((int) Database::value('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > ?', [$phone, $hourAgo]) >= self::PER_PHONE_HOUR
            || (int) Database::value('SELECT COUNT(*) FROM otp_codes WHERE ip_address = ? AND created_at > ?', [$ip, $hourAgo]) >= self::PER_IP_HOUR) {
            Logger::warning('OTP send limit', ['phone' => mask_phone($phone), 'ip' => $ip]);
            throw new DomainException('Too many codes requested. Please wait an hour and try again.');
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::insert('otp_codes', [
            'phone' => $phone, 'purpose' => $purpose, 'code_hash' => hash('sha256', $purpose . '|' . $phone . '|' . $code),
            'expires_at' => date('Y-m-d H:i:s', time() + self::VALID_MINUTES * 60), 'ip_address' => $ip,
        ]);
        SmsService::send($phone, "Your Helpigo code is $code. It expires in " . self::VALID_MINUTES . ' minutes. Never share it with anyone.');
        return $code;
    }

    /** Checks the latest unused code. A code works once. */
    public static function verify(string $phone, string $purpose, string $code): bool
    {
        return (bool) Database::transaction(static function () use ($phone, $purpose, $code): bool {
            $row = Database::one(
                'SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$phone, $purpose, date('Y-m-d H:i:s')]
            );
            if ($row === null || (int) $row['attempts'] >= self::MAX_TRIES) {
                return false;
            }
            $ok = preg_match('/^\d{6}$/', $code) === 1
                && hash_equals((string) $row['code_hash'], hash('sha256', $purpose . '|' . $phone . '|' . $code));
            if ($ok) {
                Database::run('UPDATE otp_codes SET used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $row['id']]);
            } else {
                Database::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            }
            return $ok;
        });
    }

    public static function prune(): void
    {
        Database::run('DELETE FROM otp_codes WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}
