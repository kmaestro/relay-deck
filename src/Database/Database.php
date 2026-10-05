<?php

declare(strict_types=1);

namespace ApiClient\Database;

use PDO;

/**
 * Owns the SQLite connection and schema migrations.
 */
final class Database
{
    private const int APPLICATION_ID = 1380207409;
    private const int SCHEMA_VERSION = 2;

    private PDO $connection;

    public function __construct(
        private readonly string $pathname,
        private readonly bool $initializeEmpty = false,
    )
    {
        $directory = dirname($this->pathname);
        $createNew = $this->initializeEmpty && !file_exists($this->pathname);

        if ($createNew && is_link($this->pathname)) {
            throw new \RuntimeException('The database path is a broken symbolic link.');
        }

        if (!$this->initializeEmpty && !is_file($this->pathname)) {
            throw new \RuntimeException('The selected RelayDeck database file does not exist.');
        }

        $size = $this->initializeEmpty ? null : @filesize($this->pathname);

        if (!$this->initializeEmpty && ($size === false || $size === 0)) {
            throw new \RuntimeException('The selected SQLite file is not a RelayDeck database.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create storage directory "%s".', $directory));
        }

        $this->connection = new PDO('sqlite:' . $this->pathname, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($createNew) {
            @chmod($this->pathname, 0600);
        }

        $this->assertCompatibleDatabase();
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->exec('PRAGMA busy_timeout = 5000');
        $journalMode = strtolower((string) $this->connection
            ->query('PRAGMA journal_mode = DELETE')
            ->fetchColumn());

        if ($journalMode !== 'delete') {
            throw new \RuntimeException(
                'Unable to open storage safely. Close other RelayDeck instances and verify that the folder is writable.',
            );
        }

        $this->connection->exec('PRAGMA synchronous = FULL');

        $this->migrate();
        $this->connection->exec('PRAGMA application_id = ' . self::APPLICATION_ID);
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    private function migrate(): void
    {
        $version = (int) $this->connection->query('PRAGMA user_version')->fetchColumn();

        if ($version < 1) {
            $this->migrateInitialSchema();
            $version = 1;
        }

        if ($version < 2) {
            $this->migrateRelayDeckBranding();
        }
    }

    private function assertCompatibleDatabase(): void
    {
        $applicationId = (int) $this->connection->query('PRAGMA application_id')->fetchColumn();
        $version = (int) $this->connection->query('PRAGMA user_version')->fetchColumn();

        if ($applicationId !== 0 && $applicationId !== self::APPLICATION_ID) {
            throw new \RuntimeException('The selected SQLite file belongs to another application.');
        }

        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'This RelayDeck database was created by a newer application version.',
            );
        }

        $tables = $this->connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($tables === []) {
            if (!$this->initializeEmpty || $version !== 0 || $applicationId !== 0) {
                throw new \RuntimeException('The selected SQLite file is not a valid RelayDeck database.');
            }

            return;
        }

        $requiredTables = [
            'collections',
            'folders',
            'saved_requests',
            'history',
            'environments',
            'environment_variables',
        ];

        if (array_diff($requiredTables, $tables) !== []) {
            throw new \RuntimeException('The selected SQLite file is not a RelayDeck database.');
        }
    }

    private function migrateInitialSchema(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->exec(<<<'SQL'
                CREATE TABLE collections (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE folders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    collection_id INTEGER NOT NULL,
                    parent_id INTEGER NULL,
                    name TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
                    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE SET NULL
                );

                CREATE TABLE saved_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    collection_id INTEGER NOT NULL,
                    folder_id INTEGER NULL,
                    name TEXT NOT NULL,
                    method TEXT NOT NULL,
                    url TEXT NOT NULL,
                    request_json TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
                    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
                );

                CREATE TABLE history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    method TEXT NOT NULL,
                    url TEXT NOT NULL,
                    status_code INTEGER NULL,
                    duration_ms REAL NULL,
                    request_json TEXT NOT NULL,
                    error_type TEXT NULL,
                    error_message TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE environments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE environment_variables (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    environment_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    value TEXT NOT NULL DEFAULT '',
                    is_secret INTEGER NOT NULL DEFAULT 0,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
                    UNIQUE (environment_id, name)
                );

                CREATE INDEX folders_collection_idx ON folders(collection_id);
                CREATE INDEX requests_collection_idx ON saved_requests(collection_id);
                CREATE INDEX requests_folder_idx ON saved_requests(folder_id);
                CREATE INDEX history_created_idx ON history(created_at DESC, id DESC);
                CREATE INDEX variables_environment_idx ON environment_variables(environment_id);
                SQL);

            $this->connection->exec('PRAGMA user_version = 1');
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Updates only untouched starter content while preserving all user data.
     */
    private function migrateRelayDeckBranding(): void
    {
        $this->connection->beginTransaction();

        try {
            $statement = $this->connection->prepare(
                'UPDATE collections SET description = :new_description '
                . 'WHERE name = :collection_name AND description = :old_description',
            );
            $statement->execute([
                'new_description' => 'Examples included with RelayDeck.',
                'collection_name' => 'Starter collection',
                'old_description' => 'Examples included with API Client.',
            ]);

            $statement = $this->connection->prepare(
                'UPDATE saved_requests SET request_json = replace(request_json, :old_name, :new_name) '
                . 'WHERE name = :request_name AND collection_id IN '
                . '(SELECT id FROM collections WHERE name = :collection_name)',
            );
            $statement->execute([
                'old_name' => 'API Client',
                'new_name' => 'RelayDeck',
                'request_name' => 'Create JSON resource',
                'collection_name' => 'Starter collection',
            ]);

            $this->connection->exec('PRAGMA user_version = 2');
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

}
