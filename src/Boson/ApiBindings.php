<?php

declare(strict_types=1);

namespace ApiClient\Boson;

use ApiClient\Application\WorkspaceManager;
use ApiClient\DTO\HttpRequest;
use ApiClient\Exception\ApiClientException;
use ApiClient\Http\HttpRequestService;
use Boson\WebView\WebView;
use Throwable;

/**
 * The only boundary exposed to JavaScript through Boson Function Bindings.
 */
final readonly class ApiBindings
{
    public function __construct(
        private HttpRequestService $http,
        private WorkspaceManager $workspace,
    ) {
    }

    public function register(WebView $webView): void
    {
        $webView->bindings->bind('api.bootstrap', $this->bootstrap(...));
        $webView->bindings->bind('api.send', $this->send(...));
        $webView->bindings->bind('api.request.curl', $this->exportCurl(...));
        $webView->bindings->bind('api.request.save', $this->saveRequest(...));
        $webView->bindings->bind('api.request.rename', $this->renameRequest(...));
        $webView->bindings->bind('api.request.delete', $this->deleteRequest(...));
        $webView->bindings->bind('api.collection.create', $this->createCollection(...));
        $webView->bindings->bind('api.collection.update', $this->updateCollection(...));
        $webView->bindings->bind('api.collection.delete', $this->deleteCollection(...));
        $webView->bindings->bind('api.folder.create', $this->createFolder(...));
        $webView->bindings->bind('api.folder.update', $this->updateFolder(...));
        $webView->bindings->bind('api.folder.delete', $this->deleteFolder(...));
        $webView->bindings->bind('api.history.search', $this->searchHistory(...));
        $webView->bindings->bind('api.history.clear', $this->clearHistory(...));
        $webView->bindings->bind('api.environment.save', $this->saveEnvironment(...));
        $webView->bindings->bind('api.environment.activate', $this->activateEnvironment(...));
        $webView->bindings->bind('api.environment.delete', $this->deleteEnvironment(...));
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        return $this->attempt(function (): array {
            return [
                ...$this->workspace->bootstrapState(),
                'methods' => HttpRequest::METHODS,
                'limits' => [
                    'responseBytes' => HttpRequestService::DEFAULT_MAX_RESPONSE_BYTES,
                    'uploadBytes' => HttpRequestService::DEFAULT_MAX_UPLOAD_BYTES,
                ],
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $request = null;
        $session = $this->workspace->active();

        try {
            $request = HttpRequest::fromArray($payload);
            $response = $this->http->send($request, $session->environments->activeVariables());
            $session->requests->addHistory($request, $response);

            return $this->success($response);
        } catch (Throwable $exception) {
            if ($request !== null) {
                try {
                    $session->requests->addHistory($request, error: $exception);
                } catch (Throwable) {
                    // A storage failure must not hide the useful transport error.
                }
            }

            return $this->failure($exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function exportCurl(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return [
                'command' => $this->http->toCurl(
                    HttpRequest::fromArray($payload),
                    $session->environments->activeVariables(),
                ),
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveRequest(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->requests->save($payload);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function renameRequest(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->requests->rename(
                $payload['id'] ?? null,
                $payload['name'] ?? null,
            );
        });
    }

    /** @return array<string, mixed> */
    public function deleteRequest(int $id): array
    {
        return $this->attempt(function () use ($id): array {
            $session = $this->workspace->active();
            $session->requests->delete($id);

            return ['id' => $id];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createCollection(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->collections->createCollection($payload);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateCollection(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->collections->updateCollection($payload);
        });
    }

    /** @return array<string, mixed> */
    public function deleteCollection(int $id): array
    {
        return $this->attempt(function () use ($id): array {
            $session = $this->workspace->active();
            $session->collections->deleteCollection($id);

            return ['id' => $id];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createFolder(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->collections->createFolder($payload);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateFolder(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->collections->updateFolder($payload);
        });
    }

    /** @return array<string, mixed> */
    public function deleteFolder(int $id): array
    {
        return $this->attempt(function () use ($id): array {
            $session = $this->workspace->active();
            $session->collections->deleteFolder($id);

            return ['id' => $id];
        });
    }

    /** @return array<string, mixed> */
    public function searchHistory(string $search = ''): array
    {
        return $this->attempt(function () use ($search): array {
            return $this->workspace->active()->requests->history(search: $search);
        });
    }

    /** @return array<string, mixed> */
    public function clearHistory(): array
    {
        return $this->attempt(function (): array {
            $session = $this->workspace->active();
            $session->requests->clearHistory();

            return [];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveEnvironment(array $payload): array
    {
        return $this->attempt(function () use ($payload): array {
            $session = $this->workspace->active();

            return $session->environments->save($payload);
        });
    }

    /** @return array<string, mixed> */
    public function activateEnvironment(int $id): array
    {
        return $this->attempt(function () use ($id): array {
            $session = $this->workspace->active();
            $session->environments->activate($id);

            return ['id' => $id];
        });
    }

    /** @return array<string, mixed> */
    public function deleteEnvironment(int $id): array
    {
        return $this->attempt(function () use ($id): array {
            $session = $this->workspace->active();
            $session->environments->delete($id);

            return ['id' => $id];
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     *
     * @return array<string, mixed>
     */
    private function attempt(callable $operation): array
    {
        try {
            return $this->success($operation());
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * @return array{ok: true, data: mixed}
     */
    private function success(mixed $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    /**
     * @return array{ok: false, error: array<string, mixed>}
     */
    private function failure(Throwable $exception): array
    {
        $type = $exception instanceof ApiClientException
            ? $exception->errorType
            : 'application_error';
        $details = $exception instanceof ApiClientException
            ? $exception->details
            : [];

        return [
            'ok' => false,
            'error' => [
                'type' => $type,
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'An unexpected application error occurred.',
                'details' => $details,
            ],
        ];
    }
}
