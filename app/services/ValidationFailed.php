<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Thrown by a service when form input fails validation; carries the field errors for the form. */
final class ValidationFailed extends DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Please check the highlighted fields.');
    }
}
