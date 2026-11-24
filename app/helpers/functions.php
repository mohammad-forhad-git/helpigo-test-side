<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\Money;
use App\Services\SettingsService;

/** Reads config/app.php, config/database.php ... e.g. config('app.debug'). */
function config(string $key, mixed $default = null): mixed
{
    static $loaded = [];
    $parts = explode('.', $key);
    $file = array_shift($parts);
    if (!array_key_exists($file, $loaded)) {
        $path = APP_PATH . '/config/' . $file . '.php';
        $loaded[$file] = is_file($path) ? require $path : [];
    }
    $value = $loaded[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** HTML-escape. Use for EVERY value printed into a page. */
function e(mixed $value): string
{
    // Arrays can arrive from tampered form input (name="phone[]"); print nothing instead of failing.
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $base = (string) config('app.base_path', '');
    $url = $base . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/** Asset URL with a version stamp so browsers reload changed CSS/JS. */
function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/** Public URL for an uploaded image path stored in the DB (e.g. "uploads/categories/abc.webp"). */
function upload_url(?string $path): string
{
    return $path ? url($path) : '';
}

/** Formats integer paisa as Taka. Bills always show paisa (৳12.00); pass false for shelf prices (৳350). */
function money(int $paisa, bool $alwaysDecimals = true): string
{
    return Money::format($paisa, $alwaysDecimals);
}

/** Formats a DECIMAL string from the database as Taka. */
function money_db(mixed $decimal, bool $alwaysDecimals = true): string
{
    return Money::format(Money::fromDecimal((string) $decimal), $alwaysDecimals);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function csrf_token(): string
{
    return Csrf::token();
}

/** Previous form input after a validation error. */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('old', []);
    return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
}

/** Validation error for a field, if any. */
function field_error(string $key): ?string
{
    $errors = Session::getFlash('errors', []);
    return is_array($errors) ? ($errors[$key] ?? null) : null;
}

function setting(string $key, ?string $default = null): ?string
{
    return SettingsService::get($key, $default);
}

function partial(string $template, array $data = []): string
{
    return View::partial($template, $data);
}

function redirect(string $path): never
{
    $target = str_starts_with($path, 'http') ? $path : url($path);
    header('Location: ' . $target, true, 303);
    exit;
}

/** "13 Nov 2026, 4:05 PM" */
function format_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('j M Y, g:i A', $ts);
}

function format_date(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('j M Y', $ts);
}

/** Converts plain text with newlines into safe <br> HTML. */
function nl2br_e(?string $text): string
{
    return nl2br(e($text ?? ''), false);
}

/** Splits a newline list ("Benefit 1\nBenefit 2") into an array of non-empty lines. */
function lines(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text ?? '') ?: []), static fn ($l) => $l !== ''));
}

function slugify(string $text): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? substr($slug, 0, 160) : 'item-' . bin2hex(random_bytes(3));
}

function is_current_path(string $prefix): bool
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $full = url($prefix);
    return $prefix === '/' ? $path === $full : str_starts_with($path, $full);
}

/** The logged-in user (id, role, name, phone, locale) or null. */
function current_user(): ?array
{
    return App\Services\AuthService::user();
}

/**
 * Interface text. English is the source; Bangla comes from app/lang/bn.php when the user picks it (M7).
 * Use placeholders like :name -> t("Hello :name", ["name" => $n]).
 */
function t(string $text, array $replace = []): string
{
    static $bn = null;
    $locale = (string) (current_user()['locale'] ?? App\Core\Session::get('locale', 'en'));
    if ($locale === 'bn') {
        if ($bn === null) {
            $file = APP_PATH . "/lang/bn.php";
            $bn = is_file($file) ? (array) require $file : [];
        }
        $text = $bn[$text] ?? $text;
    }
    foreach ($replace as $key => $value) {
        $text = str_replace(":" . $key, (string) $value, $text);
    }
    return $text;
}

/** Shows a phone as 017XX-XXX678 to people who should not see the full number. */
function mask_phone(?string $phone): string
{
    $phone = (string) $phone;
    return strlen($phone) === 11 ? substr($phone, 0, 3) . "XX-XXX" . substr($phone, 8) : "";
}

/** A category or zone name in the user's language (name_bn when Bangla is chosen and set). */
function local_name(array $row, string $key = 'name'): string
{
    $locale = (string) (current_user()['locale'] ?? App\Core\Session::get('locale', 'en'));
    return $locale === 'bn' && !empty($row[$key . '_bn']) ? (string) $row[$key . '_bn'] : (string) ($row[$key] ?? '');
}
