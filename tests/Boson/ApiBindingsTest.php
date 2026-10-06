<?php

declare(strict_types=1);

namespace ApiClient\Tests\Boson;

use ApiClient\Application\WorkspaceManager;
use ApiClient\Boson\ApiBindings;
use ApiClient\DTO\HttpRequest;
use ApiClient\Environment\VariableResolver;
use ApiClient\Http\HttpRequestService;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(ApiBindings::class)]
final class ApiBindingsTest extends TestCase
{
    private string $temporaryDirectory;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/relaydeck-curl-bindings-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700));

        foreach (['RELAYDECK_STORAGE_DIR', 'RELAYDECK_CONFIG_DIR'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name . '=' . $this->temporaryDirectory);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            foreach (new FilesystemIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
                self::assertTrue(unlink($item->getPathname()));
            }

            self::assertTrue(rmdir($this->temporaryDirectory));
        }

        parent::tearDown();
    }

    public function testCurlExportUsesActiveEnvironmentAndNeverRecordsHistory(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');
        $service = new HttpRequestService($client, new VariableResolver());
        $workspace = new WorkspaceManager($this->temporaryDirectory);
        $workspace->openInitial($this->temporaryDirectory . '/app.sqlite');
        $workspace->active()->environments->save([
            'name' => 'Local',
            'variables' => [
                ['name' => 'base_url', 'value' => 'https://local.example.test'],
                ['name' => 'session', 'value' => 'demo-session', 'is_secret' => true],
                ['name' => 'disabled', 'value' => 'unavailable', 'enabled' => false],
            ],
        ]);
        $bindings = new ApiBindings($service, $workspace);
        $request = new HttpRequest('GET', '{{base_url}}/items', headers: [
            ['key' => 'Cookie', 'value' => 'session={{session}}'],
        ]);

        $result = $bindings->exportCurl($request->toArray());

        self::assertSame([
            'ok' => true,
            'data' => ['command' => $service->toCurl($request, [
                'base_url' => 'https://local.example.test',
                'session' => 'demo-session',
            ])],
        ], $result);
        self::assertSame([], $workspace->active()->requests->history());

        $invalid = $bindings->exportCurl(['url' => 'file:///tmp/example']);
        self::assertFalse($invalid['ok']);
        self::assertSame('invalid_url', $invalid['error']['type']);

        $unresolved = $bindings->exportCurl(['url' => 'https://example.test/{{disabled}}']);
        self::assertFalse($unresolved['ok']);
        self::assertSame('unresolved_variable', $unresolved['error']['type']);
        self::assertSame([], $workspace->active()->requests->history());
    }

    public function testHistorySearchUsesCurrentDatabaseAndReturnsNormalSuccessData(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');
        $workspace = new WorkspaceManager($this->temporaryDirectory);
        $workspace->openInitial($this->temporaryDirectory . '/first.sqlite');
        $bindings = new ApiBindings(new HttpRequestService($client, new VariableResolver()), $workspace);
        $workspace->active()->requests->addHistory(new HttpRequest('POST', 'https://first.example.test/target'));
        $workspace->active()->requests->addHistory(new HttpRequest('GET', 'https://first.example.test/other'));

        self::assertSame([
            'ok' => true,
            'data' => $workspace->active()->requests->history(search: 'target'),
        ], $bindings->searchHistory('TARGET'));
        self::assertSame([
            'ok' => true,
            'data' => $workspace->active()->requests->history(),
        ], $bindings->searchHistory());
        self::assertSame(['ok' => true, 'data' => []], $bindings->searchHistory('missing'));

        $workspace->openInitial($this->temporaryDirectory . '/second.sqlite');
        self::assertSame(['ok' => true, 'data' => []], $bindings->searchHistory('target'));
        $workspace->active()->requests->addHistory(new HttpRequest('PATCH', 'https://second.example.test/target'));

        $result = $bindings->searchHistory('target');
        self::assertTrue($result['ok']);
        self::assertCount(1, $result['data']);
        self::assertSame('https://second.example.test/target', $result['data'][0]['url']);
    }

    public function testHistorySearchWrapsAnUnavailableWorkspaceError(): void
    {
        $workspace = new WorkspaceManager($this->temporaryDirectory);
        $bindings = new ApiBindings(
            new HttpRequestService($this->createStub(HttpClientInterface::class), new VariableResolver()),
            $workspace,
        );

        self::assertSame([
            'ok' => false,
            'error' => [
                'type' => 'application_error',
                'message' => 'No RelayDeck database is active.',
                'details' => [],
            ],
        ], $bindings->searchHistory('target'));
    }

    public function testHistorySearchWrapsStorageErrors(): void
    {
        $workspace = new WorkspaceManager($this->temporaryDirectory);
        $workspace->openInitial($this->temporaryDirectory . '/broken.sqlite');
        $workspace->active()->database->connection()->exec('DROP TABLE history');
        $bindings = new ApiBindings(
            new HttpRequestService($this->createStub(HttpClientInterface::class), new VariableResolver()),
            $workspace,
        );

        $result = $bindings->searchHistory('target');

        self::assertFalse($result['ok']);
        self::assertSame('application_error', $result['error']['type']);
        self::assertStringContainsString('no such table: history', $result['error']['message']);
    }
}
