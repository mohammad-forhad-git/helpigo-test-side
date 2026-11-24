<?php

declare(strict_types=1);

/*
 * Application bootstrap. Loaded by public/index.php, bin/* scripts and tests.
 * It sets up paths, the class autoloader, configuration and error handling.
 * It does NOT start the session; App::run() does that for web requests only.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

// public/index.php defines PUBLIC_PATH itself, because on Hostinger the web root
// (public_html) is not inside the project folder.
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . '/public');
}

/*
 * PSR-4 style autoloader: App\Services\Money -> app/services/Money.php
 * Folder names are lowercase, class file names keep their case. Linux servers
 * are case-sensitive, so this rule must be followed exactly.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $dir = implode('/', array_map('strtolower', $parts));
    $path = APP_PATH . '/' . ($dir !== '' ? $dir . '/' : '') . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_PATH . '/helpers/functions.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) config('app.timezone', 'Asia/Dhaka'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register((bool) config('app.debug', false));
