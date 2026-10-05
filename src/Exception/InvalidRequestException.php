<?php

declare(strict_types=1);

namespace ApiClient\Exception;

final class InvalidRequestException extends ApiClientException
{
    public function __construct(string $message, string $errorType = 'invalid_request')
    {
        parent::__construct($message, $errorType);
    }
}
