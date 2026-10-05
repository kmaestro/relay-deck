<?php

declare(strict_types=1);

namespace ApiClient\Application;

use ApiClient\Boson\ApiBindings;
use ApiClient\Boson\DatabaseBindings;
use ApiClient\Environment\VariableResolver;
use ApiClient\Http\HttpRequestService;
use Boson\Api\Autorun\AutorunExtension;
use Boson\Api\Dialog\DialogApiInterface;
use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\WebView\WebViewCreateInfo;
use Boson\Window\WindowCreateInfo;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Composes the native window, application services and JavaScript bindings.
 */
final readonly class RelayDeckApplication
{
    public function __construct(private string $projectDirectory)
    {
    }

    public function run(): void
    {
        $debug = filter_var(
            getenv('RELAYDECK_DEBUG') ?: getenv('API_CLIENT_DEBUG') ?: 'false',
            FILTER_VALIDATE_BOOLEAN,
        );

        $application = new Application(new ApplicationCreateInfo(
            name: 'RelayDeck',
            debug: $debug,
            extensions: ApplicationCreateInfo::extensions(except: [AutorunExtension::class]),
            window: new WindowCreateInfo(
                title: 'RelayDeck',
                width: 1280,
                height: 820,
                visible: false,
                resizable: true,
                webview: new WebViewCreateInfo(
                    storage: false,
                    contextMenu: true,
                    devTools: $debug,
                ),
            ),
        ));

        $renderer = new HtmlRenderer($this->projectDirectory . '/resources');
        $workspaceHtml = $renderer->render();
        $workspace = new WorkspaceManager($this->projectDirectory);
        $databasePathname = StoragePathResolver::resolve($this->projectDirectory);
        $configuredPathname = StoragePathResolver::configuredPathname($this->projectDirectory);
        $reconnectExistingDatabase = $configuredPathname !== null;
        $hasSettings = StoragePathResolver::hasSettings($this->projectDirectory);
        $storageError = null;

        if ($databasePathname !== null) {
            try {
                $workspace->openInitial($databasePathname);
            } catch (\Throwable $exception) {
                if (StoragePathResolver::usesEnvironmentOverride()) {
                    throw $exception;
                }

                $configuredPathname ??= $databasePathname;
                $reconnectExistingDatabase = true;
                $databasePathname = null;
                $storageError = $exception->getMessage();
            }
        }

        if (
            $databasePathname === null
            && $configuredPathname === null
            && !$hasSettings
            && !StoragePathResolver::usesEnvironmentOverride()
        ) {
            $defaultDirectory = StoragePathResolver::defaultDirectory($this->projectDirectory);

            try {
                $workspace->openDefault($defaultDirectory);
                $databasePathname = $workspace->active()->pathname;
                $configuredPathname = StoragePathResolver::configuredPathname($this->projectDirectory);
            } catch (\Throwable $exception) {
                $configuredPathname = StoragePathResolver::defaultPathname($this->projectDirectory);
                $storageError = $exception->getMessage();
            }
        }

        $httpService = new HttpRequestService(HttpClient::create(), new VariableResolver());
        (new ApiBindings($httpService, $workspace))->register($application->webview);
        (new DatabaseBindings($application, $workspace, $this->projectDirectory))
            ->register($application->webview);

        if ($databasePathname === null) {
            $this->registerStorageSetup(
                $application,
                $workspace,
                $workspaceHtml,
                $configuredPathname,
                $reconnectExistingDatabase,
            );
            $application->webview->html = $renderer->renderStorageSetup(
                $configuredPathname,
                $storageError,
                $reconnectExistingDatabase,
            );
        } else {
            $application->webview->html = $workspaceHtml;
        }

        $application->window->maximize();
        $application->window->show();
        $application->run();
    }

    private function registerStorageSetup(
        Application $application,
        WorkspaceManager $workspace,
        string $workspaceHtml,
        ?string $configuredPathname,
        bool $reconnectExistingDatabase,
    ): void
    {
        $workspaceReady = false;

        $activateWorkspace = function (?string $location, string $mode) use (
            $application,
            $workspace,
            $workspaceHtml,
            &$workspaceReady,
        ): array {
            if ($workspaceReady) {
                return ['ok' => true, 'data' => ['ready' => true]];
            }

            try {
                match ($mode) {
                    'folder' => $location === null
                        ? throw new \RuntimeException('No storage folder was selected.')
                        : $workspace->openOrCreate($location),
                    'database' => $location === null
                        ? throw new \RuntimeException('No database file was selected.')
                        : $workspace->openExisting($location),
                    'retry' => $workspace->retryConfigured(),
                    default => throw new \LogicException('Unknown storage setup mode.'),
                };

                $application->poller->defer(
                    static function (int|string $_) use ($application, $workspaceHtml): void {
                        try {
                            $application->webview->html = $workspaceHtml;
                        } catch (\Throwable $exception) {
                            error_log('RelayDeck could not open its workspace UI: ' . $exception->getMessage());
                        }
                    },
                );
                $workspaceReady = true;

                return [
                    'ok' => true,
                    'data' => ['pathname' => $workspace->active()->pathname],
                ];
            } catch (\Throwable $exception) {
                return [
                    'ok' => false,
                    'error' => [
                        'type' => 'storage_error',
                        'message' => $exception->getMessage() !== ''
                            ? $exception->getMessage()
                            : 'Unable to configure RelayDeck storage.',
                        'details' => [
                            'retryable' => true,
                        ],
                    ],
                ];
            }
        };

        $application->webview->bindings->bind(
            'api.setup.chooseStorage',
            function () use (
                $application,
                $activateWorkspace,
                $reconnectExistingDatabase,
            ): array {
                try {
                    /** @var DialogApiInterface $dialog */
                    $dialog = $application->get(DialogApiInterface::class);
                    $initialDirectory = StoragePathResolver::suggestedDirectory($this->projectDirectory);
                    $selected = $reconnectExistingDatabase
                        ? $dialog->selectFile($initialDirectory, ['*.sqlite'])
                        : $dialog->selectDirectory($initialDirectory);

                    if ($selected === null) {
                        return ['ok' => true, 'data' => null];
                    }

                    return $activateWorkspace(
                        $selected,
                        $reconnectExistingDatabase ? 'database' : 'folder',
                    );
                } catch (\Throwable $exception) {
                    return [
                        'ok' => false,
                        'error' => [
                            'type' => 'storage_error',
                            'message' => $exception->getMessage() !== ''
                                ? $exception->getMessage()
                                : 'Unable to configure RelayDeck storage.',
                            'details' => [
                                'retryable' => true,
                            ],
                        ],
                    ];
                }
            },
        );

        if ($configuredPathname !== null) {
            $application->webview->bindings->bind(
                'api.setup.retryStorage',
                fn (): array => $activateWorkspace(null, 'retry'),
            );
        }
    }
}
