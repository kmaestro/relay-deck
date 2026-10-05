<?php

declare(strict_types=1);

namespace ApiClient\Http;

use ApiClient\DTO\HttpRequest;
use ApiClient\DTO\HttpResponse;
use ApiClient\Environment\VariableResolver;
use ApiClient\Exception\HttpRequestException;
use ApiClient\Exception\InvalidRequestException;
use JsonException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Executes CORS-independent HTTP requests in PHP with safe response limits.
 */
final readonly class HttpRequestService
{
    public const int DEFAULT_MAX_RESPONSE_BYTES = 2_097_152;
    public const int DEFAULT_MAX_UPLOAD_BYTES = 26_214_400;

    public function __construct(
        private HttpClientInterface $client,
        private VariableResolver $variableResolver,
        private int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private int $maxUploadBytes = self::DEFAULT_MAX_UPLOAD_BYTES,
    ) {
        if ($this->maxResponseBytes < 1 || $this->maxUploadBytes < 1) {
            throw new \InvalidArgumentException('HTTP size limits must be positive.');
        }
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    public function send(HttpRequest $request, array $variables = []): HttpResponse
    {
        $request = $this->variableResolver->resolveRequest($request, $variables);
        $this->validate($request);

        [$url, $options] = $this->prepare($request);
        $startedAt = hrtime(true);
        $response = null;

        try {
            $response = $this->client->request($request->method, $url, $options);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = '';
            $receivedBytes = 0;
            $truncated = false;

            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();

                    throw new HttpRequestException(
                        'The request timed out. Increase the timeout or check the server.',
                        'timeout',
                    );
                }

                $content = $chunk->getContent();

                if ($content === '') {
                    continue;
                }

                $contentLength = strlen($content);
                $receivedBytes += $contentLength;
                $remaining = $this->maxResponseBytes - strlen($body);

                if ($remaining <= 0) {
                    $truncated = true;
                    $response->cancel();
                    break;
                }

                if ($contentLength > $remaining) {
                    $body .= substr($content, 0, $remaining);
                    $truncated = true;
                    $response->cancel();
                    break;
                }

                $body .= $content;
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $declaredSize = $this->contentLength($headers);
            $displayedSize = strlen($body);
            $sizeBytes = $truncated
                ? max($receivedBytes, $declaredSize ?? 0, $displayedSize)
                : ($request->method === 'HEAD' ? ($declaredSize ?? 0) : $receivedBytes);
            $bodyEncoding = 'utf8';

            if (!mb_check_encoding($body, 'UTF-8')) {
                $body = base64_encode($body);
                $bodyEncoding = 'base64';
            }

            $finalUrl = $response->getInfo('url');
            $redirectCount = $response->getInfo('redirect_count');

            return new HttpResponse(
                statusCode: $statusCode,
                headers: $this->normalizeResponseHeaders($headers),
                body: $body,
                bodyEncoding: $bodyEncoding,
                durationMs: $durationMs,
                sizeBytes: $sizeBytes,
                displayedSizeBytes: $displayedSize,
                truncated: $truncated,
                url: is_string($finalUrl) && $finalUrl !== '' ? $finalUrl : $url,
                redirectCount: is_int($redirectCount) ? $redirectCount : (int) $redirectCount,
            );
        } catch (TransportExceptionInterface $exception) {
            $response?->cancel();

            throw HttpRequestException::fromTransport($exception);
        }
    }

    /**
     * Builds a POSIX shell command without sending or saving the request.
     *
     * @param array<string, scalar|null> $variables
     */
    public function toCurl(HttpRequest $request, array $variables = []): string
    {
        $request = $this->variableResolver->resolveRequest($request, $variables);
        $this->validate($request);
        [$url, $options] = $this->prepare($request);

        // Let the data option select POST, so 301/302 redirects can switch to GET.
        if ($request->method === 'POST' && !array_key_exists('body', $options)) {
            $options['body'] = '';
        }

        $headOnly = $request->method === 'HEAD'
            && (!array_key_exists('body', $options) || $options['body'] === '');
        $lines = [
            'curl --disable --globoff',
            '--location',
            '--max-redirs ' . $options['max_redirects'],
            '--max-time ' . $options['max_duration'],
            '--url ' . self::shellQuote($url),
        ];

        if ($headOnly) {
            $lines[] = '--head';
        } elseif ($request->method !== 'POST') {
            $lines[] = '--request ' . self::shellQuote($request->method);
        }

        foreach ($options['headers'] as $header) {
            // curl uses a semicolon to send an explicitly empty header.
            [$name, $value] = explode(':', $header, 2);
            $value = ltrim($value);
            $header = $value === '' ? $name . ';' : $name . ': ' . $value;
            $lines[] = '--header ' . self::shellQuote($header);
        }

        $prefix = '';

        if (!$headOnly && array_key_exists('body', $options)) {
            $body = $options['body'];
            $binary = !is_string($body);

            if ($binary) {
                $contents = '';

                foreach ($body as $chunk) {
                    $contents .= $chunk;
                }

                $body = $contents;
            }

            if ($binary || strlen($body) > 65_536 || str_contains($body, "\0") || !mb_check_encoding($body, 'UTF-8')) {
                // Preserve uploaded/binary bytes and avoid OS limits on argument length.
                $prefix = "printf '%s' " . self::shellQuote(base64_encode($body))
                    . " | base64 -d | \\\n";
                $lines[] = "--data-binary '@-'";
            } else {
                // --data-raw keeps leading @ characters and line breaks literal.
                $lines[] = '--data-raw ' . self::shellQuote($body);
            }
        }

        return $prefix . implode(" \\\n  ", $lines);
    }

    private static function shellQuote(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidRequestException('A cURL argument cannot contain a null byte.', 'invalid_curl_argument');
        }

        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function validate(HttpRequest $request): void
    {
        if (!in_array($request->method, HttpRequest::METHODS, true)) {
            throw new InvalidRequestException('Unsupported HTTP method.', 'invalid_method');
        }

        if ($request->timeout < 1 || $request->timeout > 120) {
            throw new InvalidRequestException('Timeout must be between 1 and 120 seconds.', 'invalid_timeout');
        }

        if ($request->url === '' || preg_match('/[\x00-\x20]/', $request->url) === 1) {
            throw new InvalidRequestException('Enter a valid HTTP or HTTPS URL.', 'invalid_url');
        }

        $parts = parse_url($request->url);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new InvalidRequestException('Only valid HTTP and HTTPS URLs are supported.', 'invalid_url');
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prepare(HttpRequest $request): array
    {
        $headers = $this->headersFromRows($request->headers);
        $query = $request->query;
        $this->applyAuthorization($request->auth, $headers, $query);
        $url = $this->appendQuery($request->url, $query);
        $bodyOptions = $this->bodyOptions($request->body, $headers);

        if (!$this->hasHeader($headers, 'user-agent')) {
            $headers[] = 'User-Agent: RelayDeck/1.0';
        }

        return [
            $url,
            [
                'headers' => $headers,
                'timeout' => $request->timeout,
                'max_duration' => $request->timeout,
                'max_redirects' => 10,
                ...$bodyOptions,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function headersFromRows(array $rows): array
    {
        $headers = [];

        foreach ($rows as $row) {
            if (($row['enabled'] ?? true) === false) {
                continue;
            }

            $name = trim((string) ($row['key'] ?? ''));
            $value = (string) ($row['value'] ?? '');

            if ($name === '') {
                continue;
            }

            $this->assertHeader($name, $value);
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $auth
     * @param list<string> $headers
     * @param list<array<string, mixed>> $query
     */
    private function applyAuthorization(array $auth, array &$headers, array &$query): void
    {
        $type = strtolower((string) ($auth['type'] ?? 'none'));

        if ($type === 'none') {
            return;
        }

        if ($type === 'bearer') {
            $token = (string) ($auth['token'] ?? '');

            if ($token === '') {
                throw new InvalidRequestException('Bearer token cannot be empty.', 'invalid_auth');
            }

            $this->setHeader($headers, 'Authorization', 'Bearer ' . $token);

            return;
        }

        if ($type === 'basic') {
            $username = (string) ($auth['username'] ?? '');
            $password = (string) ($auth['password'] ?? '');
            $this->setHeader($headers, 'Authorization', 'Basic ' . base64_encode($username . ':' . $password));

            return;
        }

        if ($type === 'api_key') {
            $name = trim((string) ($auth['key'] ?? ''));
            $value = (string) ($auth['value'] ?? '');
            $location = strtolower((string) ($auth['location'] ?? 'header'));

            if ($name === '') {
                throw new InvalidRequestException('API key name cannot be empty.', 'invalid_auth');
            }

            if ($location === 'query') {
                $query[] = ['enabled' => true, 'key' => $name, 'value' => $value];

                return;
            }

            if ($location !== 'header') {
                throw new InvalidRequestException('API key location must be header or query.', 'invalid_auth');
            }

            $this->assertHeader($name, $value);
            $this->setHeader($headers, $name, $value);

            return;
        }

        throw new InvalidRequestException('Unsupported authorization type.', 'invalid_auth');
    }

    /**
     * @param list<array<string, mixed>> $query
     */
    private function appendQuery(string $url, array $query): string
    {
        $pairs = [];

        foreach ($query as $row) {
            if (($row['enabled'] ?? true) === false) {
                continue;
            }

            $key = (string) ($row['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) ($row['value'] ?? ''));
        }

        if ($pairs === []) {
            return $url;
        }

        $fragment = '';
        $fragmentPosition = strpos($url, '#');

        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        if (str_ends_with($url, '?') || str_ends_with($url, '&')) {
            $separator = '';
        }

        return $url . $separator . implode('&', $pairs) . $fragment;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function bodyOptions(array $body, array &$headers): array
    {
        $type = strtolower((string) ($body['type'] ?? 'none'));

        if ($type === 'none') {
            return [];
        }

        if ($type === 'json') {
            $content = (string) ($body['content'] ?? '');

            if (trim($content) !== '') {
                try {
                    json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidRequestException(
                        'Request body contains invalid JSON: ' . $exception->getMessage(),
                        'invalid_json',
                    );
                }
            }

            $this->ensureHeader($headers, 'Content-Type', 'application/json');

            return ['body' => $content];
        }

        if ($type === 'raw') {
            $this->ensureHeader($headers, 'Content-Type', 'text/plain; charset=utf-8');

            return ['body' => (string) ($body['content'] ?? '')];
        }

        if ($type === 'form') {
            $this->ensureHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');

            return ['body' => $this->formEncodedBody($body['fields'] ?? [])];
        }

        if ($type === 'multipart') {
            return $this->multipartBody($body['fields'] ?? [], $headers);
        }

        throw new InvalidRequestException('Unsupported request body type.', 'invalid_body');
    }

    private function formEncodedBody(mixed $fields): string
    {
        if (!is_array($fields)) {
            throw new InvalidRequestException('Form fields must be an array.', 'invalid_body');
        }

        $pairs = [];

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['enabled'] ?? true) === false) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) ($field['value'] ?? ''));
        }

        return implode('&', $pairs);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function multipartBody(mixed $fields, array &$headers): array
    {
        if (!is_array($fields)) {
            throw new InvalidRequestException('Multipart fields must be an array.', 'invalid_body');
        }

        $parts = [];
        $totalUploadBytes = 0;

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['enabled'] ?? true) === false) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            if (($field['type'] ?? 'text') !== 'file') {
                $parts[$key] = (string) ($field['value'] ?? '');
                continue;
            }

            $file = $field['file'] ?? null;

            if (!is_array($file) || !is_string($file['contentBase64'] ?? null)) {
                throw new InvalidRequestException(
                    sprintf('Choose the file for multipart field "%s" again.', $key),
                    'missing_upload',
                );
            }

            $contents = base64_decode($file['contentBase64'], true);

            if ($contents === false) {
                throw new InvalidRequestException('An uploaded file contains invalid data.', 'invalid_upload');
            }

            $totalUploadBytes += strlen($contents);

            if ($totalUploadBytes > $this->maxUploadBytes) {
                throw new InvalidRequestException(
                    sprintf('Total upload size exceeds the %d MB limit.', intdiv($this->maxUploadBytes, 1_048_576)),
                    'upload_too_large',
                );
            }

            $filename = trim((string) ($file['name'] ?? 'upload.bin')) ?: 'upload.bin';
            $contentType = trim((string) ($file['type'] ?? 'application/octet-stream'))
                ?: 'application/octet-stream';

            if (str_contains($filename, "\r") || str_contains($filename, "\n")) {
                throw new InvalidRequestException('The upload filename is invalid.', 'invalid_upload');
            }

            $parts[$key] = new DataPart($contents, $filename, $contentType);
        }

        $formData = new FormDataPart($parts);

        foreach ($formData->getPreparedHeaders()->toArray() as $preparedHeader) {
            $separator = strpos($preparedHeader, ':');

            if ($separator === false) {
                continue;
            }

            $name = substr($preparedHeader, 0, $separator);
            $value = ltrim(substr($preparedHeader, $separator + 1));
            $this->setHeader($headers, $name, $value);
        }

        return ['body' => $formData->bodyToIterable()];
    }

    /**
     * @param list<string> $headers
     */
    private function ensureHeader(array &$headers, string $name, string $value): void
    {
        if (!$this->hasHeader($headers, $name)) {
            $headers[] = $name . ': ' . $value;
        }
    }

    /**
     * @param list<string> $headers
     */
    private function setHeader(array &$headers, string $name, string $value): void
    {
        $this->assertHeader($name, $value);
        $prefix = strtolower($name) . ':';
        $headers = array_values(array_filter(
            $headers,
            static fn (string $header): bool => !str_starts_with(strtolower($header), $prefix),
        ));
        $headers[] = $name . ': ' . $value;
    }

    /**
     * @param list<string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $prefix = strtolower($name) . ':';

        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function assertHeader(string $name, string $value): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
            throw new InvalidRequestException(sprintf('Invalid HTTP header name "%s".', $name), 'invalid_header');
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidRequestException('HTTP header values cannot contain line breaks.', 'invalid_header');
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function contentLength(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'content-length' || !isset($values[0])) {
                continue;
            }

            return ctype_digit($values[0]) ? (int) $values[0] : null;
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private function normalizeResponseHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[(string) $name] = array_values(array_map('strval', $values));
        }

        return $normalized;
    }
}
