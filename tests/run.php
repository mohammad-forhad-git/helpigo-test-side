<?php

declare(strict_types=1);

/*
 * Runs the automated tests.
 *   php tests/run.php            unit + integration
 *   php tests/run.php unit       unit tests only (no database needed)
 *   php tests/run.php integration
 * Integration tests use the database named in TEST_DB_NAME (must end with _test). It is wiped each time.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/bootstrap.php';

$suite = $argv[1] ?? 'all';
$dirs = match ($suite) {
    'unit' => ['Unit'],
    'integration' => ['Integration'],
    default => ['Unit', 'Integration'],
};

foreach ($dirs as $dir) {
    foreach (glob(__DIR__ . '/' . $dir . '/*Test.php') ?: [] as $file) {
        $before = count($GLOBALS['__tests']);
        require $file;
        for ($i = $before; $i < count($GLOBALS['__tests']); $i++) {
            $GLOBALS['__tests'][$i][0] = $dir . '/' . basename($file, '.php') . ' › ' . $GLOBALS['__tests'][$i][0];
        }
    }
}

$passed = 0;
$failed = [];
$start = microtime(true);
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        $passed++;
        echo "  ✓ $name" . PHP_EOL;
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  ✗ $name" . PHP_EOL . '      ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL
            . '      at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    }
}

printf(PHP_EOL . "%d passed, %d failed (%.1fs)" . PHP_EOL, $passed, count($failed), microtime(true) - $start);
exit($failed === [] ? 0 : 1);
