<?php

declare(strict_types=1);

namespace ApiClient\DTO;

use JsonSerializable;

/**
 * Transport response capped to a UI-safe body size.
 */
final readonly class HttpResponse implements JsonSerializable
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $bodyEncoding,
        public float $durationMs,
        public int $sizeBytes,
        public int $displayedSizeBytes,
        public bool $truncated,
        public string $url,
        public int $redirectCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
            'bodyEncoding' => $this->bodyEncoding,
            'durationMs' => round($this->durationMs, 2),
            'sizeBytes' => $this->sizeBytes,
            'displayedSizeBytes' => $this->displayedSizeBytes,
            'truncated' => $this->truncated,
            'url' => $this->url,
            'redirectCount' => $this->redirectCount,
        ];
    }
}
