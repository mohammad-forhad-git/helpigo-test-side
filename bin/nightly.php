<?php

declare(strict_types=1);

/*
 * Nightly maintenance (SYS-04, SYS-05). Cron: daily at 03:00.
 *   php bin/nightly.php [--backup-dir=/home/user/backups] [--keep=30]
 * - Recalculates helper purchase-limit tiers.
 * - Removes old login attempts and one-time codes.
 * - Writes a gzipped SQL backup of the database (pure PHP, works where mysqldump is not allowed)
 *   and keeps the newest N backups.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Services\WorkerService;

$options = getopt('', ['backup-dir::', 'keep::', 'no-backup']);

foreach (Database::all("SELECT user_id FROM worker_profiles WHERE status = 'approved'") as $row) {
    WorkerService::recalcTier((int) $row['user_id']);
}
AuthService::pruneAttempts();
OtpService::prune();
echo date('Y-m-d H:i:s') . ' tiers recalculated, old login attempts and codes removed' . PHP_EOL;

if (isset($options['no-backup'])) {
    exit(0);
}

$dir = rtrim((string) ($options['backup-dir'] ?? (getenv('HOME') ? getenv('HOME') . '/backups' : STORAGE_PATH . '/private/backups')), '/\\');
$keep = max(1, (int) ($options['keep'] ?? 30));
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$file = $dir . '/helpigo-' . date('Ymd-His') . '.sql.gz';
$gz = gzopen($file, 'wb6');
$pdo = Database::pdo();
gzwrite($gz, "-- Helpigo backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $table = (string) $table;
    $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM);
    gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n\n");
    $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
        gzwrite($gz, "INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', $values) . ");\n");
    }
    gzwrite($gz, "\n");
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
gzclose($gz);
@chmod($file, 0600);
echo "Backup written: $file (" . round(filesize($file) / 1024) . " KB)" . PHP_EOL;

$backups = glob($dir . '/helpigo-*.sql.gz') ?: [];
rsort($backups);
foreach (array_slice($backups, $keep) as $old) {
    @unlink($old);
}
