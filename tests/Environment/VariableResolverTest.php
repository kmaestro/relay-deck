<?php

declare(strict_types=1);

namespace ApiClient\Tests\Environment;

use ApiClient\DTO\HttpRequest;
use ApiClient\Environment\VariableResolver;
use ApiClient\Exception\UnresolvedVariableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableResolver::class)]
final class VariableResolverTest extends TestCase
{
    public function testItResolvesSimpleAndNestedVariables(): void
    {
        $resolver = new VariableResolver();

        self::assertSame(
            'https://api.example.test/v1/users',
            $resolver->resolve('{{api_url}}/users', [
                'host' => 'api.example.test',
                'base_url' => 'https://{{host}}',
                'api_url' => '{{base_url}}/v1',
            ]),
        );
    }

    public function testItResolvesEverySupportedRequestArea(): void
    {
        $resolver = new VariableResolver();
        $request = new HttpRequest(
            method: 'POST',
            url: '{{base_url}}/users',
            query: [['enabled' => true, 'key' => 'tenant', 'value' => '{{tenant}}']],
            headers: [['enabled' => true, 'key' => 'X-Token', 'value' => '{{token}}']],
            body: ['type' => 'json', 'content' => '{"tenant":"{{tenant}}"}', 'fields' => []],
            auth: ['type' => 'bearer', 'token' => '{{token}}'],
        );

        $resolved = $resolver->resolveRequest($request, [
            'base_url' => 'https://api.example.test',
            'tenant' => 'north',
            'token' => 'secret-value',
        ]);

        self::assertSame('https://api.example.test/users', $resolved->url);
        self::assertSame('north', $resolved->query[0]['value']);
        self::assertSame('secret-value', $resolved->headers[0]['value']);
        self::assertSame('{"tenant":"north"}', $resolved->body['content']);
        self::assertSame('secret-value', $resolved->auth['token']);
    }

    public function testItDoesNotResolveDisabledRows(): void
    {
        $resolver = new VariableResolver();
        $request = new HttpRequest(
            method: 'GET',
            url: 'https://example.test',
            query: [['enabled' => false, 'key' => 'ignored', 'value' => '{{missing}}']],
        );

        $resolved = $resolver->resolveRequest($request, []);

        self::assertSame('{{missing}}', $resolved->query[0]['value']);
    }

    public function testItReportsUnknownVariables(): void
    {
        $resolver = new VariableResolver();

        $this->expectException(UnresolvedVariableException::class);
        $this->expectExceptionMessage('missing_token');

        $resolver->resolve('Bearer {{missing_token}}', []);
    }
}
