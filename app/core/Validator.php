<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Money;

/**
 * Server-side validation. Browser checks are only for convenience;
 * these rules are what actually protect the data.
 *
 * Usage:
 *   $v = new Validator($request->all());
 *   $v->string('name', 'Full name', 100)->bdPhone('phone', 'Phone number');
 *   if ($v->fails()) { ... $v->errors() ... }
 *   $clean = $v->clean();
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $clean = [];

    public function __construct(private readonly array $data)
    {
    }

    private function raw(string $field): string
    {
        $value = $this->data[$field] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function string(string $field, string $label, int $max, bool $required = true, int $min = 0): self
    {
        $value = $this->raw($field);
        // Remove control characters except newlines and tabs.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $length = mb_strlen($value);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = $required ? '' : null;
            return $this;
        }
        if ($length < $min) {
            $this->errors[$field] = "$label must be at least $min characters.";
        } elseif ($length > $max) {
            $this->errors[$field] = "$label must be at most $max characters.";
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function email(string $field, string $label, bool $required = false): self
    {
        $value = strtolower($this->raw($field));
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (mb_strlen($value) > 190 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "Please enter a valid email address.";
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function bdPhone(string $field, string $label, bool $required = true): self
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        $normalized = self::normalizeBdPhone($value);
        if ($normalized === null) {
            $this->errors[$field] = "Please enter a valid Bangladeshi mobile number, e.g. 01712345678.";
            $this->clean[$field] = $value;
            return $this;
        }
        $this->clean[$field] = $normalized;
        return $this;
    }

    /** @param list<string> $allowed */
    public function in(string $field, string $label, array $allowed): self
    {
        $value = $this->raw($field);
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "Please choose a valid $label.";
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function int(string $field, string $label, int $min, int $max, bool $required = true): self
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!preg_match('/^-?\d{1,9}$/', $value) || (int) $value < $min || (int) $value > $max) {
            $this->errors[$field] = "$label must be a whole number between $min and $max.";
            $this->clean[$field] = null;
            return $this;
        }
        $this->clean[$field] = (int) $value;
        return $this;
    }

    /** Money in taka (e.g. "1550" or "1,550.50"). Stored in clean() as integer paisa. */
    public function money(string $field, string $label, int $minPaisa = 0, int $maxPaisa = 1_000_000_000, bool $required = true): self
    {
        $value = str_replace([',', ' ', '৳'], '', $this->raw($field));
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        $paisa = Money::tryFromDecimal($value);
        if ($paisa === null) {
            $this->errors[$field] = "$label must be an amount like 150 or 150.50.";
            $this->clean[$field] = null;
            return $this;
        }
        if ($paisa < $minPaisa || $paisa > $maxPaisa) {
            $this->errors[$field] = sprintf('%s must be between %s and %s.', $label, Money::format($minPaisa), Money::format($maxPaisa));
        }
        $this->clean[$field] = $paisa;
        return $this;
    }

    public function bool(string $field): self
    {
        $value = $this->data[$field] ?? null;
        $this->clean[$field] = in_array($value, ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
        return $this;
    }

    public function url(string $field, string $label, bool $required = false): self
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        $isRelative = (bool) preg_match('#^/[A-Za-z0-9/_\-?=&%.]*$#', $value) && !str_starts_with($value, '//');
        $isHttp = filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value);
        if (mb_strlen($value) > 255 || (!$isRelative && !$isHttp)) {
            $this->errors[$field] = "$label must be a link starting with / or https://";
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Adds a custom error (e.g. "slug already used"). */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function clean(): array
    {
        return $this->clean;
    }

    /**
     * Wallet account number. bKash/Nagad use the 11-digit mobile number; a Rocket account is
     * the mobile number plus one check digit (12 digits). Returns the cleaned number or null.
     */
    public static function normalizeWallet(string $value, string $method): ?string
    {
        $digits = (string) preg_replace('/[\s\-().]/', '', $value);
        if ($method === 'rocket' && preg_match('/^(\+?880|0)(1[3-9]\d{9})$/', $digits, $m)) {
            return '0' . $m[2];
        }
        return self::normalizeBdPhone($value);
    }

    /**
     * Accepts 01712345678, 01712-345678, +8801712345678, 8801712345678.
     * Returns the 11-digit local form, or null if it is not a valid BD mobile number.
     */
    public static function normalizeBdPhone(string $value): ?string
    {
        $digits = (string) preg_replace('/[\s\-().]/', '', $value);
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        }
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }
        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }
}
