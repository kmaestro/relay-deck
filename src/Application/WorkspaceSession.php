<?php

declare(strict_types=1);

namespace ApiClient\Application;

use ApiClient\Database\Database;
use ApiClient\Storage\CollectionStorage;
use ApiClient\Storage\EnvironmentStorage;
use ApiClient\Storage\RequestStorage;

/**
 * Owns every database-bound service for one RelayDeck workspace.
 */
final readonly class WorkspaceSession
{
    public string $directory;
    public Database $database;
    public RequestStorage $requests;
    public CollectionStorage $collections;
    public EnvironmentStorage $environments;

    public function __construct(
        public string $pathname,
        bool $initializeEmpty = false,
    )
    {
        $this->directory = dirname($pathname);
        $this->database = new Database($pathname, $initializeEmpty);
        $connection = $this->database->connection();
        $this->requests = new RequestStorage($connection);
        $this->collections = new CollectionStorage($connection);
        $this->environments = new EnvironmentStorage($connection);
    }
}
