<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown to stop a request with an HTTP status (404, 403, 419...). The message is safe to show. */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
