<?php

declare(strict_types=1);

namespace ApiClient\Exception;

final class UnresolvedVariableException extends ApiClientException
{
    /**
     * @param list<string> $variables
     */
    public function __construct(public readonly array $variables)
    {
        parent::__construct(
            'Unresolved environment variable(s): ' . implode(', ', $variables) . '.',
            'unresolved_variable',
            ['variables' => $variables],
        );
    }
}
