<?php

declare(strict_types=1);

namespace ApiClient\Tests\Database;

use ApiClient\Database\Database;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryBase = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryBase);

        $this->temporaryDirectory = $temporaryBase
            . DIRECTORY_SEPARATOR . 'relaydeck-database-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($this->temporaryDirectory, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testItUsesDeleteJournalModeAndFullSynchronousWrites(): void
    {
        $database = new Database(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            true,
        );
        $connection = $database->connection();

        self::assertSame(
            'delete',
            strtolower((string) $connection->query('PRAGMA journal_mode')->fetchColumn()),
        );
        self::assertSame(2, (int) $connection->query('PRAGMA synchronous')->fetchColumn());
        self::assertSame(1380207409, (int) $connection->query('PRAGMA application_id')->fetchColumn());
    }

    public function testNewDatabaseContainsOnlyTheEmptySchema(): void
    {
        $database = new Database(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'empty-workspace.sqlite',
            true,
        );
        $connection = $database->connection();

        foreach (
            [
                'collections',
                'folders',
                'saved_requests',
                'history',
                'environments',
                'environment_variables',
            ] as $table
        ) {
            self::assertSame(
                0,
                (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
                sprintf('A new database unexpectedly contains rows in "%s".', $table),
            );
        }

        self::assertFalse(
            $connection->query('SELECT id FROM environments WHERE is_active = 1 LIMIT 1')->fetchColumn(),
        );
    }

    public function testMigratingAnExistingDatabaseDoesNotRemoveOrAddWorkspaceData(): void
    {
        $pathname = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'existing.sqlite';
        $database = new Database($pathname, true);
        $connection = $database->connection();

        $connection->exec(<<<'SQL'
            INSERT INTO environments (id, name, is_active) VALUES (10, 'Team environment', 1);
            INSERT INTO environment_variables
                (id, environment_id, name, value, is_secret, enabled, sort_order)
                VALUES (11, 10, 'api_host', 'https://api.example.test', 0, 1, 0);
            INSERT INTO collections (id, name, description)
                VALUES (20, 'Existing collection', 'Must survive migration.');
            INSERT INTO folders (id, collection_id, parent_id, name)
                VALUES (21, 20, NULL, 'Existing folder');
            INSERT INTO saved_requests
                (id, collection_id, folder_id, name, method, url, request_json)
                VALUES (
                    22,
                    20,
                    21,
                    'Existing request',
                    'GET',
                    'https://api.example.test/items',
                    '{"method":"GET","url":"https://api.example.test/items"}'
                );
            INSERT INTO history
                (id, method, url, status_code, duration_ms, request_json)
                VALUES (
                    23,
                    'GET',
                    'https://api.example.test/items',
                    200,
                    12.5,
                    '{"method":"GET","url":"https://api.example.test/items"}'
                );
            PRAGMA user_version = 1;
            SQL);

        $before = $this->workspaceRows($connection);
        unset($connection, $database);

        $migrated = new Database($pathname);
        $connection = $migrated->connection();

        self::assertSame(2, (int) $connection->query('PRAGMA user_version')->fetchColumn());
        self::assertSame($before, $this->workspaceRows($connection));
    }

    public function testItRejectsAnUnrelatedSqliteDatabaseWithoutChangingIt(): void
    {
        $pathname = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $connection = new PDO('sqlite:' . $pathname);
        $connection->exec('CREATE TABLE unrelated_records (id INTEGER PRIMARY KEY, value TEXT)');
        $connection = null;
        $before = hash_file('sha256', $pathname);

        try {
            new Database($pathname);
            self::fail('An unrelated SQLite database must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The selected SQLite file is not a RelayDeck database.', $exception->getMessage());
        }

        self::assertSame($before, hash_file('sha256', $pathname));
    }

    public function testOpeningAnEmptyExistingSqliteFileDoesNotInitializeIt(): void
    {
        $pathname = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'empty.sqlite';
        self::assertTrue(touch($pathname));
        $before = hash_file('sha256', $pathname);

        try {
            new Database($pathname);
            self::fail('An empty existing SQLite file must not be initialized by the open flow.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The selected SQLite file is not a RelayDeck database.', $exception->getMessage());
        }

        self::assertSame(0, filesize($pathname));
        self::assertSame($before, hash_file('sha256', $pathname));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function workspaceRows(PDO $connection): array
    {
        $rows = [];

        foreach (
            [
                'collections',
                'folders',
                'saved_requests',
                'history',
                'environments',
                'environment_variables',
            ] as $table
        ) {
            $rows[$table] = $connection
                ->query('SELECT * FROM ' . $table . ' ORDER BY id')
                ->fetchAll();
        }

        return $rows;
    }

    private function removeTemporaryDirectory(): void
    {
        if (!isset($this->temporaryDirectory) || !is_dir($this->temporaryDirectory)) {
            return;
        }

        $temporaryBase = realpath(sys_get_temp_dir());

        if (
            !is_string($temporaryBase)
            || dirname($this->temporaryDirectory) !== $temporaryBase
            || !str_starts_with(basename($this->temporaryDirectory), 'relaydeck-database-')
        ) {
            throw new \LogicException('Refusing to clean an unexpected test directory.');
        }

        foreach (new FilesystemIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isFile() && !$item->isLink()) {
                throw new \LogicException('Unexpected directory in the database test fixture.');
            }

            unlink($item->getPathname());
        }

        rmdir($this->temporaryDirectory);
    }
}
