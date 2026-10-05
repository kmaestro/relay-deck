<?php

declare(strict_types=1);

namespace ApiClient\Tests\Http;

use ApiClient\DTO\HttpRequest;
use ApiClient\Environment\VariableResolver;
use ApiClient\Exception\ApiClientException;
use ApiClient\Http\HttpRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(HttpRequestService::class)]
final class CurlExportTest extends TestCase
{
    public function testItExportsResolvedHeadersCookiesAndDuplicateQueryWithoutSending(): void
    {
        $cookie = 'session=example; encoded=a%3Ab; quote=\'$(printf substituted)`printf substituted`';
        $request = new HttpRequest(
            method: 'POST',
            url: '{{base_url}}/items?existing=1#section',
            query: [
                ['key' => 'tag', 'value' => 'one two'],
                ['key' => 'tag', 'value' => '{{tag}}'],
                ['enabled' => false, 'key' => 'ignored', 'value' => '{{missing}}'],
            ],
            headers: [
                ['key' => 'Cookie', 'value' => '{{cookie}}'],
                ['key' => 'Authorization', 'value' => 'obsolete'],
                ['enabled' => false, 'key' => 'X-Ignored', 'value' => '{{missing}}'],
            ],
            body: ['type' => 'json', 'content' => "{\n  \"name\": \"{{name}}\"\n}"],
            auth: ['type' => 'bearer', 'token' => '{{token}}'],
            timeout: 12.5,
        );

        $captured = $this->capture($this->service()->toCurl($request, [
            'base_url' => 'https://example.test',
            'tag' => 'two&three',
            'cookie' => $cookie,
            'name' => "O'Brien",
            'token' => 'demo-token',
        ]));
        $arguments = $captured['arguments'];
        $headers = $this->optionValues($arguments, ['--header', '-H']);

        self::assertNotContains('POST', $this->optionValues($arguments, ['--request', '-X']), 'A forced POST method would also affect redirected requests.');
        self::assertContains('https://example.test/items?existing=1&tag=one%20two&tag=two%26three#section', $arguments);
        self::assertContains('Cookie: ' . $cookie, $headers);
        self::assertContains('Authorization: Bearer demo-token', $headers);
        self::assertNotContains('Authorization: obsolete', $headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertContains('User-Agent: RelayDeck/1.0', $headers);
        self::assertCount(4, $headers);
        self::assertSame("{\n  \"name\": \"O'Brien\"\n}", $this->body($captured));
        self::assertContains('12.5', $this->optionValues($arguments, ['--max-time', '-m']));
        self::assertContains('10', $this->optionValues($arguments, ['--max-redirs']));
        self::assertNotEmpty(array_intersect(['--location', '-L'], $arguments));
    }

    public function testItPreservesLiteralRawBodyWithLeadingAtSignAndShellMetacharacters(): void
    {
        $body = "@file-that-must-not-be-read\n" . 'O\'Brien $(printf changed) `printf changed` $SHELL \\ "quoted"';
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'PATCH',
            'https://example.test/raw',
            headers: [['key' => 'Content-Type', 'value' => 'application/custom']],
            body: ['type' => 'raw', 'content' => $body],
        )));

        self::assertSame($body, $this->body($captured));
        self::assertContains('Content-Type: application/custom', $this->optionValues($captured['arguments'], ['--header', '-H']));
    }

    public function testItPreservesBinaryRawBody(): void
    {
        $body = "\x00\xff\r\n'\\\x01binary\n";
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'PUT',
            'https://example.test/binary',
            body: ['type' => 'raw', 'content' => $body],
        )));

        self::assertContains('@-', $this->optionValues($captured['arguments'], ['--data-binary']));
        self::assertSame($body, $this->body($captured));
    }

    public function testLargeTextBodyIsPassedThroughStdin(): void
    {
        $body = str_repeat('large body ', 7_000);
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'POST',
            'https://example.test/large',
            body: ['type' => 'raw', 'content' => $body],
        )));

        self::assertContains('@-', $this->optionValues($captured['arguments'], ['--data-binary']));
        self::assertSame($body, $this->body($captured));
    }

    public function testItExportsFormFieldsAndQueryApiKey(): void
    {
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'POST',
            'https://example.test/form',
            body: ['type' => 'form', 'fields' => [
                ['key' => 'tag', 'value' => 'one two'],
                ['key' => 'tag', 'value' => '{{tag}}'],
                ['enabled' => false, 'key' => 'ignored', 'value' => '{{missing}}'],
            ]],
            auth: ['type' => 'api_key', 'location' => 'query', 'key' => 'api key', 'value' => 'a&b'],
        ), ['tag' => 'a&b']));

        self::assertContains('https://example.test/form?api%20key=a%26b', $captured['arguments']);
        self::assertSame('tag=one%20two&tag=a%26b', $this->body($captured));
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $this->optionValues($captured['arguments'], ['--header', '-H']));
    }

    public function testItExportsMultipartWithItsMatchingBoundaryAndActualFileBytes(): void
    {
        $file = "\x00\xff\r\nFile ' contents\n";
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'POST',
            'https://example.test/upload',
            body: ['type' => 'multipart', 'fields' => [
                ['key' => 'caption', 'value' => 'hello {{name}}'],
                ['key' => 'upload', 'type' => 'file', 'file' => [
                    'name' => 'example.bin',
                    'type' => 'application/octet-stream',
                    'contentBase64' => base64_encode($file),
                ]],
                ['enabled' => false, 'key' => 'ignored', 'type' => 'file'],
            ]],
        ), ['name' => 'world']));
        $headers = $this->optionValues($captured['arguments'], ['--header', '-H']);
        $contentTypes = array_values(array_filter($headers, static fn (string $header): bool => str_starts_with(strtolower($header), 'content-type:')));

        self::assertCount(1, $contentTypes);
        self::assertSame(1, preg_match('/boundary="?([^";\s]+)/', $contentTypes[0], $matches));
        self::assertContains('@-', $this->optionValues($captured['arguments'], ['--data-binary']));
        $body = $this->body($captured);
        self::assertStringStartsWith('--' . $matches[1] . "\r\n", $body);
        self::assertStringEndsWith('--' . $matches[1] . "--\r\n", $body);
        self::assertStringContainsString('name="caption"', $body);
        self::assertStringContainsString("\r\n\r\nhello world\r\n", $body);
        self::assertStringContainsString('filename="example.bin"', $body);
        self::assertStringContainsString("\r\n\r\n" . $file . "\r\n", $body);
        self::assertStringNotContainsString('ignored', $body);
    }

    public function testHeadUsesCurlHeadModeAndBasicAuthorization(): void
    {
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'HEAD',
            'https://example.test/status',
            auth: ['type' => 'basic', 'username' => 'demo', 'password' => "pass'word"],
        )));

        self::assertNotEmpty(array_intersect(['--head', '-I'], $captured['arguments']));
        self::assertContains(
            'Authorization: Basic ' . base64_encode("demo:pass'word"),
            $this->optionValues($captured['arguments'], ['--header', '-H']),
        );
        self::assertSame('', $this->body($captured));
    }

    public function testItDistinguishesEmptyHeadersFromValuesEndingInAColon(): void
    {
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'GET',
            'https://example.test/headers',
            headers: [
                ['key' => 'X-Empty', 'value' => ''],
                ['key' => 'X-Value', 'value' => 'trailing: '],
            ],
        )));
        $headers = $this->optionValues($captured['arguments'], ['--header', '-H']);

        self::assertContains('X-Empty;', $headers, 'cURL requires a semicolon to transmit an empty header.');
        self::assertContains('X-Value: trailing: ', $headers);
    }

    public function testHeadWithAnEmptyBodyDoesNotCombineIncompatibleCurlOptions(): void
    {
        $captured = $this->capture($this->service()->toCurl(new HttpRequest(
            'HEAD',
            'https://example.test/status',
            body: ['type' => 'raw', 'content' => ''],
        )));

        self::assertNotEmpty(array_intersect(['--head', '-I'], $captured['arguments']));
        self::assertSame([], $this->optionValues($captured['arguments'], ['--data-raw', '--data-binary', '--data', '-d']));
    }

    #[DataProvider('invalidRequests')]
    public function testItRejectsInvalidRequestsBeforeExport(HttpRequest $request, string $errorType): void
    {
        try {
            $this->service()->toCurl($request);
            self::fail('Invalid input must not produce an executable command.');
        } catch (ApiClientException $exception) {
            self::assertSame($errorType, $exception->errorType);
        }
    }

    public static function invalidRequests(): iterable
    {
        yield 'scheme' => [new HttpRequest('GET', 'file:///etc/passwd'), 'invalid_url'];
        yield 'method' => [new HttpRequest('TRACE', 'https://example.test'), 'invalid_method'];
        yield 'timeout' => [new HttpRequest('GET', 'https://example.test', timeout: 0), 'invalid_timeout'];
        yield 'json' => [new HttpRequest('POST', 'https://example.test', body: ['type' => 'json', 'content' => '{broken']), 'invalid_json'];
        yield 'header' => [new HttpRequest('GET', 'https://example.test', headers: [['key' => 'Cookie', 'value' => "a=b\r\nInjected: value"]]), 'invalid_header'];
        yield 'variable' => [new HttpRequest('GET', '{{missing}}/items'), 'unresolved_variable'];
        yield 'missing file' => [new HttpRequest('POST', 'https://example.test', body: ['type' => 'multipart', 'fields' => [['key' => 'upload', 'type' => 'file']]]), 'missing_upload'];
    }

    private function service(): HttpRequestService
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('stream');

        return new HttpRequestService($client, new VariableResolver());
    }

    /**
     * Run the generated command through a real shell, replacing curl with an
     * argument/stdin recorder so no HTTP request can leave the test process.
     *
     * @return array{arguments: list<string>, stdin: string}
     */
    private function capture(string $command): array
    {
        if (!is_executable('/bin/sh')) {
            self::markTestSkipped('cURL export requires a POSIX shell.');
        }

        $recorder = 'echo json_encode(["arguments" => array_slice($argv, 1), "stdin" => base64_encode(stream_get_contents(STDIN))], JSON_THROW_ON_ERROR);';
        $script = 'curl() { ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($recorder) . ' -- "$@"; }' . "\n" . $command;
        $process = proc_open(['/bin/sh', '-c', $script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $captured = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $captured['stdin'] = base64_decode($captured['stdin'], true);

        return $captured;
    }

    /** @param list<string> $arguments @param list<string> $options @return list<string> */
    private function optionValues(array $arguments, array $options): array
    {
        $values = [];

        foreach ($arguments as $index => $argument) {
            if (in_array($argument, $options, true)) {
                self::assertArrayHasKey($index + 1, $arguments);
                $values[] = $arguments[$index + 1];
            }
        }

        return $values;
    }

    /** @param array{arguments: list<string>, stdin: string} $captured */
    private function body(array $captured): string
    {
        $raw = $this->optionValues($captured['arguments'], ['--data-raw']);

        if ($raw !== []) {
            return implode('&', $raw);
        }

        $binary = $this->optionValues($captured['arguments'], ['--data-binary']);

        if ($binary === ['@-']) {
            return $captured['stdin'];
        }

        foreach ($binary as $value) {
            self::assertStringStartsNotWith('@', $value, 'cURL would read this body from a file.');
        }

        return implode('&', $binary);
    }
}
