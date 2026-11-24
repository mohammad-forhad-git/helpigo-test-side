<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use RuntimeException;

/**
 * Safe image uploads.
 * 1. Check PHP upload status and size.
 * 2. Detect the real type from the file content (finfo), not from the name or browser.
 * 3. Re-encode the image with GD. This strips anything hidden inside the file (scripts, metadata).
 * 4. Save under a random name with a fixed extension.
 */
final class UploadService
{
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_PIXELS = 40_000_000; // refuse "decompression bomb" images

    /**
     * @param array{tmp_name:string, error:int, size:int, name:string} $file
     * @param string $directory absolute target folder
     * @return string the new file name (not the full path)
     */
    public static function storeImage(array $file, string $directory, int $maxBytes, int $maxWidth = 1600): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required for image uploads.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new DomainException('The image is too large.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException('The image could not be uploaded. Please try again.');
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
            throw new DomainException('Invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxBytes) {
            throw new DomainException(sprintf('Images must be smaller than %d MB.', (int) ceil($maxBytes / 1048576)));
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new DomainException('Only JPG, PNG or WebP images are allowed.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new DomainException('This file is not a valid image.');
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
        };
        if ($source === false) {
            throw new DomainException('This image could not be read. Please try a different file.');
        }

        [$width, $height] = [$info[0], $info[1]];
        if ($width > $maxWidth) {
            $newHeight = (int) round($height * $maxWidth / $width);
            $resized = imagecreatetruecolor($maxWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload folder could not be created.');
        }
        $ext = self::ALLOWED[$mime];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        $ok = match ($ext) {
            'jpg'  => imagejpeg($source, $target, 85),
            'png'  => imagepng($source, $target, 6),
            'webp' => imagewebp($source, $target, 85),
        };
        imagedestroy($source);
        if (!$ok) {
            throw new RuntimeException('Image could not be saved.');
        }
        @chmod($target, 0644);
        return $name;
    }

    /** Deletes a file only if it is really inside $directory (no "../" tricks). */
    public static function delete(string $directory, string $name): void
    {
        $base = realpath($directory);
        $path = realpath($directory . DIRECTORY_SEPARATOR . basename($name));
        if ($base !== false && $path !== false && str_starts_with($path, $base) && is_file($path)) {
            @unlink($path);
        }
    }

    public static function publicDir(string $sub): string
    {
        return PUBLIC_PATH . '/uploads/' . $sub;
    }

    public static function proofDir(): string
    {
        return STORAGE_PATH . '/private/payment-proofs';
    }
}
