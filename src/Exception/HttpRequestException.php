<?php

declare(strict_types=1);

namespace ApiClient\Exception;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class HttpRequestException extends ApiClientException
{
    public static function fromTransport(TransportExceptionInterface $exception): self
    {
        $message = $exception->getMessage();
        $normalized = strtolower($message);
        $type = match (true) {
            str_contains($normalized, 'timed out'),
            str_contains($normalized, 'timeout'),
            str_contains($normalized, 'idle timeout'),
            str_contains($normalized, 'maximum execution time') => 'timeout',
            str_contains($normalized, 'certificate'),
            str_contains($normalized, 'ssl'),
            str_contains($normalized, 'tls') => 'ssl_error',
            str_contains($normalized, 'could not resolve'),
            str_contains($normalized, 'name resolution'),
            str_contains($normalized, 'getaddrinfo') => 'dns_error',
            str_contains($normalized, 'connection refused'),
            str_contains($normalized, 'failed to connect') => 'connection_refused',
            default => 'connection_error',
        };

        $friendlyMessage = match ($type) {
            'timeout' => 'The request timed out. Increase the timeout or check the server.',
            'ssl_error' => 'SSL/TLS verification failed. Check the certificate and host name.',
            'dns_error' => 'The host name could not be resolved.',
            'connection_refused' => 'The server refused the connection.',
            default => 'A network connection error occurred.',
        };

        return new self(
            $friendlyMessage,
            $type,
            ['transport_message' => $message],
            $exception,
        );
    }
}
