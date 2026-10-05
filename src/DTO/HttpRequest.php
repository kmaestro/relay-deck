<?php

declare(strict_types=1);

namespace ApiClient\DTO;

use ApiClient\Exception\InvalidRequestException;
use JsonSerializable;

/**
 * Immutable request definition shared by storage, bindings and HTTP transport.
 */
final readonly class HttpRequest implements JsonSerializable
{
    /** @var list<string> */
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * @param list<array<string, mixed>> $query
     * @param list<array<string, mixed>> $headers
     * @param array<string, mixed> $body
     * @param array<string, mixed> $auth
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $query = [],
        public array $headers = [],
        public array $body = ['type' => 'none', 'content' => '', 'fields' => []],
        public array $auth = ['type' => 'none'],
        public float $timeout = 30.0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $method = strtoupper(self::string($data, 'method', 'GET'));
        $url = self::string($data, 'url');
        $timeout = $data['timeout'] ?? 30;

        if (!is_int($timeout) && !is_float($timeout) && !is_numeric($timeout)) {
            throw new InvalidRequestException('Timeout must be a number.', 'invalid_timeout');
        }

        return new self(
            method: $method,
            url: $url,
            query: self::rows($data['query'] ?? $data['params'] ?? []),
            headers: self::rows($data['headers'] ?? []),
            body: self::map($data['body'] ?? ['type' => 'none']),
            auth: self::map($data['auth'] ?? ['type' => 'none']),
            timeout: (float) $timeout,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeFileContents = true): array
    {
        $body = $this->body;

        if (!$includeFileContents && isset($body['fields']) && is_array($body['fields'])) {
            $body['fields'] = array_map(
                static function (mixed $field): mixed {
                    if (!is_array($field) || !isset($field['file']) || !is_array($field['file'])) {
                        return $field;
                    }

                    $field['file']['contentBase64'] = null;
                    $field['file']['missing'] = true;

                    return $field;
                },
                $body['fields'],
            );
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'query' => $this->query,
            'headers' => $this->headers,
            'body' => $body,
            'auth' => $this->auth,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        if (!is_scalar($value) && $value !== null) {
            throw new InvalidRequestException(sprintf('Field "%s" must be a string.', $key));
        }

        return (string) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidRequestException('Key/value rows must be an array.');
        }

        $rows = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidRequestException('Request body and auth must be objects.');
        }

        return $value;
    }
}
