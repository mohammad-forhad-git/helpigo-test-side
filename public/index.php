<?php

declare(strict_types=1);

/*
 * Single entry point for every page.
 *
 * Where is the application code?
 * - Default: one folder up (public_html/../app). Works locally and on a normal Hostinger domain.
 * - Subdomain or other layouts: copy app-location.example.php to app-location.php next to this
 *   file and put the real path of the project folder in it. See docs/deployment-guide.md.
 */

// PHP's built-in dev server: let it serve real static files (CSS, JS, images) directly.
if (PHP_SAPI === 'cli-server') {
    $requested = realpath(__DIR__ . (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if ($requested !== false && is_file($requested) && str_starts_with($requested, __DIR__)
        && !str_ends_with(strtolower($requested), '.php')) {
        return false;
    }
}

define('PUBLIC_PATH', __DIR__);

$projectRoot = is_file(__DIR__ . '/app-location.php')
    ? rtrim((string) require __DIR__ . '/app-location.php', '/\\')
    : dirname(__DIR__);

require $projectRoot . '/app/bootstrap.php';

App\Core\App::run();
