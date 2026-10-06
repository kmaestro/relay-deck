<?php

declare(strict_types=1);

namespace ApiClient\Tests\Storage;

use ApiClient\Database\Database;
use ApiClient\DTO\HttpRequest;
use ApiClient\DTO\HttpResponse;
use ApiClient\Exception\ApiClientException;
use ApiClient\Storage\CollectionStorage;
use ApiClient\Storage\RequestStorage;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestStorage::class)]
final class RequestStorageTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryBase = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryBase);
        $this->temporaryDirectory = $temporaryBase
            . DIRECTORY_SEPARATOR . 'relaydeck-request-storage-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            foreach (new FilesystemIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
                self::assertTrue(unlink($item->getPathname()));
            }

            self::assertTrue(rmdir($this->temporaryDirectory));
        }

        parent::tearDown();
    }

    public function testItRenamesOnlyTheSelectedSavedRequest(): void
    {
        $database = new Database(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'requests.sqlite',
            true,
        );
        $connection = $database->connection();
        $collection = (new CollectionStorage($connection))->createCollection([
            'name' => 'Links',
            'description' => '',
        ]);
        $storage = new RequestStorage($connection);
        $created = $storage->save([
            'name' => 'New request',
            'collectionId' => $collection['id'],
            'folderId' => null,
            'request' => [
                'method' => 'POST',
                'url' => 'https://api.example.test/links',
                'query' => [['enabled' => true, 'key' => 'page', 'value' => '1']],
                'headers' => [['enabled' => true, 'key' => 'Accept', 'value' => 'application/json']],
                'body' => ['type' => 'json', 'content' => '{"name":"Ada"}', 'fields' => []],
                'auth' => ['type' => 'bearer', 'token' => 'secret'],
                'timeout' => 15,
            ],
        ]);
        $requestJsonBefore = $connection->query(
            'SELECT request_json FROM saved_requests WHERE id = ' . (int) $created['id'],
        )->fetchColumn();

        $renamed = $storage->rename($created['id'], '  Create link  ');

        self::assertSame($created['id'], $renamed['id']);
        self::assertSame('Create link', $renamed['name']);
        self::assertSame($created['collection_id'], $renamed['collection_id']);
        self::assertSame($created['folder_id'], $renamed['folder_id']);
        self::assertSame($created['method'], $renamed['method']);
        self::assertSame($created['url'], $renamed['url']);
        self::assertSame($created['request'], $renamed['request']);
        self::assertSame($requestJsonBefore, $connection->query(
            'SELECT request_json FROM saved_requests WHERE id = ' . (int) $created['id'],
        )->fetchColumn());
        self::assertCount(1, $storage->savedRequests());
        self::assertSame('Create link', $storage->savedRequests()[0]['name']);

        try {
            $storage->rename($created['id'], '   ');
            self::fail('An empty request name was accepted.');
        } catch (ApiClientException $exception) {
            self::assertSame('invalid_name', $exception->errorType);
        }

        try {
            $storage->rename(999999, 'Missing request');
            self::fail('A missing request was renamed.');
        } catch (ApiClientException $exception) {
            self::assertSame('not_found', $exception->errorType);
        }

        self::assertSame('Create link', $storage->savedRequests()[0]['name']);
    }

    public function testHistorySearchFindsRetainedRowsOutsideTheLatestHundred(): void
    {
        $storage = $this->historyStorage();

        for ($index = 1; $index <= 205; $index++) {
            $path = match ($index) {
                1 => 'expired-target',
                6 => 'retained-target',
                default => 'recent-' . $index,
            };
            $storage->addHistory(new HttpRequest('GET', 'https://example.test/' . $path));
        }

        self::assertCount(100, $storage->history());
        self::assertNotContains(6, array_column($storage->history(), 'id'));
        self::assertSame([6], array_column($storage->history(search: 'retained-target'), 'id'));
        self::assertSame([], $storage->history(search: 'expired-target'));
        self::assertCount(200, $storage->history(limit: 1000));
    }

    public function testHistorySearchKeepsNewestMatchesAndExistingLimitBounds(): void
    {
        $storage = $this->historyStorage();

        for ($index = 1; $index <= 205; $index++) {
            $storage->addHistory(new HttpRequest('GET', 'https://example.test/match/' . $index));
        }

        self::assertSame(range(205, 106), array_column($storage->history(search: 'match'), 'id'));
        self::assertSame([205, 204], array_column($storage->history(limit: 2, search: 'match'), 'id'));
        self::assertSame([205], array_column($storage->history(limit: 0, search: 'match'), 'id'));
        self::assertSame([205], array_column($storage->history(limit: -5, search: 'match'), 'id'));
        self::assertSame(range(205, 6), array_column($storage->history(limit: 1000, search: 'match'), 'id'));
        self::assertSame($storage->history(), $storage->history(search: " \t\n "));
    }

    public function testHistorySearchMatchesUrlMethodAndDisplayedStatusWithoutCaseSensitivity(): void
    {
        $storage = $this->historyStorage();
        $get = new HttpRequest('GET', 'https://example.test/CamelCase');
        $patch = new HttpRequest(
            'PATCH',
            'https://example.test/ПРИВЕТ/ÉCOLE',
            query: [['key' => 'page', 'value' => '2', 'enabled' => true]],
            headers: [['key' => 'Accept', 'value' => 'application/json', 'enabled' => true]],
            body: ['type' => 'json', 'content' => '{"name":"Ada"}', 'fields' => []],
            auth: ['type' => 'bearer', 'token' => 'secret'],
            timeout: 12.5,
        );
        $storage->addHistory($get, $this->response(200, $get->url));
        $storage->addHistory($patch, $this->response(404, $patch->url));
        $storage->addHistory(
            new HttpRequest('DELETE', 'https://example.test/failed'),
            error: new ApiClientException('hidden-detail', 'connection_error'),
        );
        $storage->addHistory(new HttpRequest('HEAD', 'https://example.test/pending'));

        self::assertSame([1], array_column($storage->history(search: " \tCaMeLcAsE\n"), 'id'));
        self::assertSame([2], array_column($storage->history(search: 'patch'), 'id'));
        self::assertSame([2], array_column($storage->history(search: 'привет/école'), 'id'));
        self::assertSame([1], array_column($storage->history(search: '200'), 'id'));
        self::assertSame([2], array_column($storage->history(search: '04'), 'id'));
        self::assertSame([3], array_column($storage->history(search: 'eRr'), 'id'));
        self::assertSame([4], array_column($storage->history(search: '—'), 'id'));
        self::assertSame([], $storage->history(search: 'connection_error'));
        self::assertSame([], $storage->history(search: 'hidden-detail'));

        $row = $storage->history(search: 'привет')[0];
        self::assertSame(2, $row['id']);
        self::assertSame(404, $row['status_code']);
        self::assertSame(12.5, $row['duration_ms']);
        self::assertNull($row['error_type']);
        self::assertArrayNotHasKey('request_json', $row);
        self::assertEquals($patch, HttpRequest::fromArray($row['request']));
    }

    public function testHistorySearchTreatsSqlSpecialCharactersAsLiteralText(): void
    {
        $storage = $this->historyStorage();
        $storage->addHistory(new HttpRequest('GET', 'https://example.test/100%/under_score/back\\slash'));
        $storage->addHistory(new HttpRequest('GET', 'https://example.test/1000/underXscore/backslash'));
        $storage->addHistory(new HttpRequest('GET', "https://example.test/' OR 1=1 --"));

        self::assertSame([1], array_column($storage->history(search: '%'), 'id'));
        self::assertSame([1], array_column($storage->history(search: '_'), 'id'));
        self::assertSame([1], array_column($storage->history(search: '\\'), 'id'));
        self::assertSame([3], array_column($storage->history(search: "' OR 1=1 --"), 'id'));
        self::assertSame([], $storage->history(search: "' OR 2=2 --"));
        self::assertCount(3, $storage->history());
    }

    private function historyStorage(): RequestStorage
    {
        $database = new Database($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'history.sqlite', true);

        return new RequestStorage($database->connection());
    }

    private function response(int $statusCode, string $url): HttpResponse
    {
        return new HttpResponse(
            statusCode: $statusCode,
            headers: [],
            body: '',
            bodyEncoding: 'utf-8',
            durationMs: 12.5,
            sizeBytes: 0,
            displayedSizeBytes: 0,
            truncated: false,
            url: $url,
        );
    }
}
