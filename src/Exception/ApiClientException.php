<?php

declare(strict_types=1);

namespace ApiClient\Exception;

use RuntimeException;
use Throwable;

class ApiClientException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly string $errorType = 'application_error',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
