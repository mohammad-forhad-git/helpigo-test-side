<?php

declare(strict_types=1);

/*
 * Tiny test framework (no Composer needed, so it runs on any PHP install).
 *   test('name', function () { assert_same(1, 1); });
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

final class AssertionFailed extends RuntimeException
{
}

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

/** Asserts that $fn throws $class (and optionally that the message contains $contains). */
function assert_throws(string $class, callable $fn, ?string $contains = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            throw new AssertionFailed("exception message '{$e->getMessage()}' does not contain '$contains'");
        }
        return;
    }
    throw new AssertionFailed("expected $class to be thrown, nothing was thrown");
}

/** Recreates the test database from schema.sql + seed.sql. Refuses to touch any DB not named *_test. */
function reset_test_db(): void
{
    $name = (string) config('database.test_name');
    if ($name === '' || !str_ends_with($name, '_test')) {
        throw new RuntimeException('TEST_DB_NAME must be set and end with "_test".');
    }
    Database::useDatabase($name);
    $pdo = Database::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (['schema.sql', 'seed.sql'] as $file) {
        $sql = (string) file_get_contents(BASE_PATH . '/database/' . $file);
        foreach (preg_split('/;\s*\R/', $sql) as $statement) {
            $statement = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
    App\Services\SettingsService::clearCache();
}

/** Simulates a fresh browser: empty session, no cached user. */
function fresh_session(): void
{
    $_SESSION = [];
    App\Services\AuthService::forgetCache();
}
