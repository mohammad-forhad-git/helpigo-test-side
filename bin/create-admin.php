<?php

declare(strict_types=1);

/*
 * Creates an admin (staff) account, or resets the password of an existing admin.
 *   php bin/create-admin.php
 * Non-interactive (automated setups/tests):
 *   php bin/create-admin.php --name="Owner" --phone=01712345678 --password-env=ADMIN_PASSWORD
 * The password is read from the terminal or from the named environment variable,
 * never from a command-line argument (those are visible to other users and saved in shell history).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Validator;
use App\Services\AuthService;

$options = getopt('', ['name::', 'phone::', 'password-env::']);

function ask(string $question): string
{
    echo $question;
    return trim((string) fgets(STDIN));
}

function askHidden(string $question): string
{
    echo $question;
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
    } else {
        echo '(input will be visible) ';
        $value = trim((string) fgets(STDIN));
    }
    echo PHP_EOL;
    return $value;
}

$name = isset($options['name']) ? trim((string) $options['name']) : ask('Name: ');
$phone = Validator::normalizeBdPhone(isset($options['phone']) ? (string) $options['phone'] : ask('Mobile number: '));

if (isset($options['password-env'])) {
    $password = (string) getenv((string) $options['password-env']);
    $confirm = $password;
} else {
    $password = askHidden('Password (min ' . AuthService::MIN_ADMIN_PASSWORD . ' characters): ');
    $confirm = askHidden('Repeat password: ');
}

if ($name === '' || $phone === null) {
    fwrite(STDERR, "A name and a valid Bangladeshi mobile number are required.\n");
    exit(1);
}
try {
    AuthService::assertPassword($password, $confirm, AuthService::MIN_ADMIN_PASSWORD);
} catch (DomainException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = Database::one('SELECT id, role FROM users WHERE phone = ?', [$phone]);
if ($existing !== null && $existing['role'] !== 'admin') {
    fwrite(STDERR, "This number belongs to a {$existing['role']} account. Use a different number for staff.\n");
    exit(1);
}
if ($existing !== null) {
    Database::update('users', [
        'name' => $name, 'password_hash' => $hash, 'status' => 'active', 'password_changed_at' => date('Y-m-d H:i:s'),
    ], 'id = :w_id', ['w_id' => $existing['id']]);
    echo "Password updated for admin $phone." . PHP_EOL;
} else {
    Database::insert('users', [
        'role' => 'admin', 'name' => $name, 'phone' => $phone, 'password_hash' => $hash, 'phone_verified_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Admin $phone created." . PHP_EOL;
}
