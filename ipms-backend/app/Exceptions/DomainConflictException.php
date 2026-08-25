<?php

namespace App\Exceptions;

use RuntimeException;

final class DomainConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }
}
