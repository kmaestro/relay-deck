<?php

declare(strict_types=1);

namespace ApiClient\Environment;

use ApiClient\DTO\HttpRequest;
use ApiClient\Exception\UnresolvedVariableException;

/**
 * Resolves {{name}} placeholders, including variables referencing variables.
 */
final class VariableResolver
{
    private const string PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*\}\}/';

    /**
     * @param array<string, scalar|null> $variables
     */
    public function resolve(string $value, array $variables, bool $strict = true): string
    {
        $resolved = $value;

        for ($iteration = 0; $iteration < 10; ++$iteration) {
            $next = preg_replace_callback(
                self::PATTERN,
                static function (array $matches) use ($variables): string {
                    $name = $matches[1];

                    if (!array_key_exists($name, $variables)) {
                        return $matches[0];
                    }

                    return (string) ($variables[$name] ?? '');
                },
                $resolved,
            );

            if ($next === null || $next === $resolved) {
                break;
            }

            $resolved = $next;
        }

        if ($strict) {
            $unresolved = $this->findUnresolved($resolved);

            if ($unresolved !== []) {
                throw new UnresolvedVariableException($unresolved);
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    public function resolveRequest(HttpRequest $request, array $variables): HttpRequest
    {
        return new HttpRequest(
            method: $request->method,
            url: $this->resolve($request->url, $variables),
            query: $this->resolveRows($request->query, $variables),
            headers: $this->resolveRows($request->headers, $variables),
            body: $this->resolveBody($request->body, $variables),
            auth: $this->resolveMap($request->auth, $variables),
            timeout: $request->timeout,
        );
    }

    /**
     * @return list<string>
     */
    public function findUnresolved(string $value): array
    {
        preg_match_all(self::PATTERN, $value, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, scalar|null> $variables
     *
     * @return list<array<string, mixed>>
     */
    private function resolveRows(array $rows, array $variables): array
    {
        foreach ($rows as &$row) {
            if (($row['enabled'] ?? true) === false) {
                continue;
            }

            $row = $this->resolveMap($row, $variables);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null> $variables
     *
     * @return array<string, mixed>
     */
    private function resolveBody(array $body, array $variables): array
    {
        $type = (string) ($body['type'] ?? 'none');

        if (in_array($type, ['json', 'raw'], true) && isset($body['content'])) {
            $body['content'] = $this->resolve((string) $body['content'], $variables);
        }

        if (in_array($type, ['form', 'multipart'], true) && isset($body['fields']) && is_array($body['fields'])) {
            /** @var list<array<string, mixed>> $fields */
            $fields = array_values(array_filter($body['fields'], 'is_array'));
            $body['fields'] = $this->resolveRows($fields, $variables);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, scalar|null> $variables
     *
     * @return array<string, mixed>
     */
    private function resolveMap(array $values, array $variables): array
    {
        foreach ($values as $key => $value) {
            if ($key === 'contentBase64') {
                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->resolve($value, $variables);
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->resolveMap($value, $variables);
            }
        }

        return $values;
    }
}
