<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Files people must not reach by URL: shopping-list photos, prescriptions, receipts,
 * helper ID documents. Stored under storage/private and streamed only after an access check.
 */
final class PrivateFileService
{
    public static function taskDir(): string
    {
        return STORAGE_PATH . '/private/task-files';
    }

    /** Stores an uploaded image (re-encoded, random name). Returns the file name. */
    public static function storeTaskImage(array $file): string
    {
        $maxBytes = (int) config('app.upload_image_max_mb', 4) * 1024 * 1024;
        return UploadService::storeImage($file, self::taskDir(), $maxBytes, 1600);
    }

    /**
     * The attachment row if $user may see it: the task's customer, the assigned helper while
     * the task is active or finished, or an admin. Anyone else gets 404 (never reveal it exists).
     */
    public static function findTaskAttachment(array $user, int $attachmentId): array
    {
        $row = Database::one(
            'SELECT a.*, t.customer_id, t.worker_id, t.status FROM task_attachments a
             JOIN tasks t ON t.id = a.task_id WHERE a.id = ?',
            [$attachmentId]
        );
        if ($row === null) {
            throw new HttpException(404);
        }
        $allowed = match ($user['role']) {
            'admin'    => true,
            'customer' => (int) $row['customer_id'] === (int) $user['id'],
            'worker'   => (int) $row['worker_id'] === (int) $user['id'],
            default    => false,
        };
        if (!$allowed) {
            throw new HttpException(404);
        }
        return $row;
    }

    /** Sends the file with headers that stop caching and content sniffing. */
    public static function stream(string $directory, string $name): never
    {
        $path = realpath($directory . DIRECTORY_SEPARATOR . basename($name));
        $base = realpath($directory);
        if ($path === false || $base === false || !str_starts_with($path, $base) || !is_file($path)) {
            throw new HttpException(404);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new HttpException(404);
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
}
