<?php

declare(strict_types=1);

namespace ApiClient\Tests\Storage;

use ApiClient\Database\Database;
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
}
