<?php

declare(strict_types=1);

namespace ApiClient\Boson;

use ApiClient\Application\StoragePathResolver;
use ApiClient\Application\WorkspaceManager;
use Boson\Api\Dialog\DialogApiInterface;
use Boson\Application;
use Boson\WebView\WebView;

/**
 * Exposes native database selection and in-process workspace switching.
 */
final readonly class DatabaseBindings
{
    public function __construct(
        private Application $application,
        private WorkspaceManager $workspace,
        private string $projectDirectory,
    ) {
    }

    public function register(WebView $webView): void
    {
        $webView->bindings->bind('api.database.switch', $this->switchDatabase(...));
        $webView->bindings->bind('api.database.open', $this->openExisting(...));
        $webView->bindings->bind('api.database.create', $this->createDatabase(...));
    }

    /** @return array<string, mixed> */
    public function switchDatabase(string $databaseId): array
    {
        return $this->attempt(fn (): array => $this->workspace->switchDatabase($databaseId));
    }

    /** @return array<string, mixed> */
    public function openExisting(): array
    {
        return $this->attempt(function (): ?array {
            $selected = $this->dialog()->selectFile($this->initialDirectory(), ['*.sqlite']);

            return $selected === null ? null : $this->workspace->openExisting($selected);
        });
    }

    /** @return array<string, mixed> */
    public function createDatabase(string $filename): array
    {
        return $this->attempt(function () use ($filename): ?array {
            $filename = StoragePathResolver::normalizeDatabaseFilename($filename);
            $selected = $this->dialog()->selectDirectory($this->initialDirectory());

            return $selected === null ? null : $this->workspace->createDatabase($selected, $filename);
        });
    }

    private function dialog(): DialogApiInterface
    {
        /** @var DialogApiInterface */
        return $this->application->get(DialogApiInterface::class);
    }

    private function initialDirectory(): ?string
    {
        return $this->workspace->hasActive()
            ? $this->workspace->active()->directory
            : StoragePathResolver::suggestedDirectory($this->projectDirectory);
    }

    /** @return array<string, mixed> */
    private function attempt(callable $operation): array
    {
        try {
            return ['ok' => true, 'data' => $operation()];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => [
                    'type' => 'database_error',
                    'message' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Unable to change the active RelayDeck database.',
                    'details' => [],
                ],
            ];
        }
    }
}
