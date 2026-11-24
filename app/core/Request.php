<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $files,
        private readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $base = (string) config('app.base_path', '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }
        // Fallback when URL rewriting is unavailable: index.php?r=/login
        if (($path === '' || $path === '/') && isset($_GET['r']) && is_string($_GET['r'])) {
            $path = $_GET['r'];
        }
        $path = '/' . trim(rawurldecode($path), '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    /** Raw POST value (string or array). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** Trimmed POST string. Arrays and missing values become ''. */
    public function str(string $key): string
    {
        $value = $this->post[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) ? (int) trim($value) : $default;
    }

    /** @return array<string, mixed> all POST data */
    public function all(): array
    {
        return $this->post;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return null;
        }
        return $file['error'] === UPLOAD_ERR_NO_FILE ? null : $file;
    }

    /** Multiple-file input (name="images[]") as a list of single-file arrays. */
    public function files(string $key): array
    {
        $raw = $this->files[$key] ?? null;
        if (!is_array($raw) || !isset($raw['name']) || !is_array($raw['name'])) {
            return [];
        }
        $list = [];
        foreach ($raw['name'] as $i => $name) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = [
                'name'     => $name,
                'type'     => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error'    => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $raw['size'][$i] ?? 0,
            ];
        }
        return $list;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json')
            || strtolower($this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function referer(): string
    {
        return $this->header('Referer');
    }
}
