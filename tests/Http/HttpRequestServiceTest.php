<?php

declare(strict_types=1);

namespace ApiClient\Tests\Http;

use ApiClient\DTO\HttpRequest;
use ApiClient\Environment\VariableResolver;
use ApiClient\Exception\HttpRequestException;
use ApiClient\Exception\InvalidRequestException;
use ApiClient\Http\HttpRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpRequestService::class)]
final class HttpRequestServiceTest extends TestCase
{
    public function testItExecutesARequestWithVariablesQueryHeadersAndJson(): void
    {
        $captured = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = compact('method', 'url', 'options');

                return new MockResponse('{"created":true}', [
                    'http_code' => 201,
                    'response_headers' => [
                        'content-type: application/json',
                        'x-request-id: test-123',
                    ],
                ]);
            },
        );
        $service = new HttpRequestService($client, new VariableResolver());
        $request = new HttpRequest(
            method: 'POST',
            url: '{{base_url}}/items',
            query: [['enabled' => true, 'key' => 'page', 'value' => '1']],
            headers: [['enabled' => true, 'key' => 'X-Tenant', 'value' => '{{tenant}}']],
            body: ['type' => 'json', 'content' => '{"name":"test"}', 'fields' => []],
            auth: ['type' => 'bearer', 'token' => '{{token}}'],
            timeout: 10,
        );

        $response = $service->send($request, [
            'base_url' => 'https://api.example.test',
            'tenant' => 'north',
            'token' => 'secret',
        ]);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.example.test/items?page=1', $captured['url']);
        self::assertSame('{"name":"test"}', $captured['options']['body']);
        self::assertSame(['X-Tenant: north'], $captured['options']['normalized_headers']['x-tenant']);
        self::assertSame(['Authorization: Bearer secret'], $captured['options']['normalized_headers']['authorization']);
        self::assertSame(['Content-Type: application/json'], $captured['options']['normalized_headers']['content-type']);
        self::assertSame(201, $response->statusCode);
        self::assertSame('{"created":true}', $response->body);
        self::assertSame('utf8', $response->bodyEncoding);
        self::assertFalse($response->truncated);
        self::assertSame(['test-123'], $response->headers['x-request-id']);
    }

    public function testItCapsTheDisplayedResponseBody(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 100), ['http_code' => 200]));
        $service = new HttpRequestService(
            $client,
            new VariableResolver(),
            maxResponseBytes: 16,
        );

        $response = $service->send(new HttpRequest('GET', 'https://example.test/large'));

        self::assertTrue($response->truncated);
        self::assertSame(16, $response->displayedSizeBytes);
        self::assertSame(str_repeat('x', 16), $response->body);
        self::assertGreaterThanOrEqual(16, $response->sizeBytes);
    }

    public function testItRejectsInvalidUrlsBeforeTransport(): void
    {
        $service = new HttpRequestService(new MockHttpClient(), new VariableResolver());

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('HTTP and HTTPS');

        $service->send(new HttpRequest('GET', 'file:///etc/passwd'));
    }

    public function testItDoesNotTreatHttpErrorsAsTransportFailures(): void
    {
        $client = new MockHttpClient(new MockResponse('Not found', [
            'http_code' => 404,
            'response_headers' => ['content-type: text/plain'],
        ]));
        $service = new HttpRequestService($client, new VariableResolver());

        $response = $service->send(new HttpRequest('GET', 'https://example.test/missing'));

        self::assertSame(404, $response->statusCode);
        self::assertSame('Not found', $response->body);
    }

    public function testItTurnsAnIdleTimeoutIntoATypedError(): void
    {
        $body = (static function (): \Generator {
            yield '';
        })();
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => 200]));
        $service = new HttpRequestService($client, new VariableResolver());

        try {
            $service->send(new HttpRequest('GET', 'https://example.test/slow'));
            self::fail('A timeout exception was expected.');
        } catch (HttpRequestException $exception) {
            self::assertSame('timeout', $exception->errorType);
            self::assertStringContainsString('timed out', $exception->getMessage());
        }
    }
}
