<?php

declare(strict_types=1);

namespace ApiClient\Tests\Application;

use ApiClient\Application\StoragePathResolver;
use ApiClient\Application\WorkspaceManager;
use ApiClient\Database\Database;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

#[CoversClass(WorkspaceManager::class)]
final class WorkspaceManagerTest extends TestCase
{
    private const array ENVIRONMENT_NAMES = [
        'RELAYDECK_STORAGE_DIR',
        'API_CLIENT_STORAGE_DIR',
        'RELAYDECK_CONFIG_DIR',
        'XDG_CONFIG_HOME',
        'LOCALAPPDATA',
        'APPDATA',
        'HOME',
        'USERPROFILE',
    ];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    private string $temporaryDirectory;
    private string $projectDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENVIRONMENT_NAMES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name);
        }

        $temporaryBase = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryBase);

        $this->temporaryDirectory = $temporaryBase
            . DIRECTORY_SEPARATOR . 'relaydeck-workspace-manager-' . bin2hex(random_bytes(8));
        $this->projectDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'project';

        self::assertTrue(mkdir($this->projectDirectory, 0700, true));
        self::assertTrue(mkdir($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home', 0700));
        $this->setEnvironment('HOME', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home');
        $this->setEnvironment('USERPROFILE', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home');
        $this->setEnvironment('XDG_CONFIG_HOME', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'xdg-config');
        $this->setEnvironment('LOCALAPPDATA', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'local-appdata');
        $this->setEnvironment('APPDATA', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'app-data');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $this->setEnvironment($name, $value === false ? null : $value);
        }

        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testSuccessfulSwitchAtomicallyChangesTheActiveDatabaseAndItsData(): void
    {
        $firstDirectory = $this->createDatabaseDirectory('first', 'Only in first database');
        $secondDirectory = $this->createDatabaseDirectory('second', 'Only in second database');
        $state = $this->registerDatabases([$firstDirectory, $secondDirectory], $firstDirectory);
        $firstId = $this->databaseId($state, $firstDirectory);
        $secondId = $this->databaseId($state, $secondDirectory);
        $manager = new WorkspaceManager($this->projectDirectory);
        $manager->openInitial($firstDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');

        self::assertSame($firstDirectory, $manager->active()->directory);
        self::assertContains('Only in first database', $this->collectionNames($manager));
        self::assertNotContains('Only in second database', $this->collectionNames($manager));
        self::assertSame($firstId, $manager->state()['activeDatabaseId']);

        $switchedState = $manager->switchDatabase($secondId);

        self::assertSame($secondDirectory, $manager->active()->directory);
        self::assertContains('Only in second database', $this->collectionNames($manager));
        self::assertNotContains('Only in first database', $this->collectionNames($manager));
        self::assertSame($secondId, $switchedState['activeDatabaseId']);
        self::assertContains(
            'Only in second database',
            array_column($switchedState['collections'], 'name'),
        );
        self::assertSame($secondId, $this->savedSettings()['activeDatabaseId']);
    }

    public function testMissingCandidateLeavesThePreviousSessionAndSettingsActive(): void
    {
        $firstDirectory = $this->createDatabaseDirectory('first', 'Stable database marker');
        $missingDirectory = $this->createDatabaseDirectory('missing', 'Missing database marker');
        $state = $this->registerDatabases([$firstDirectory, $missingDirectory], $firstDirectory);
        $missingId = $this->databaseId($state, $missingDirectory);

        self::assertTrue(unlink($missingDirectory . DIRECTORY_SEPARATOR . 'app.sqlite'));

        $this->assertFailedSwitchKeepsActiveDatabase($firstDirectory, $missingId);
    }

    public function testCorruptCandidateLeavesThePreviousSessionAndSettingsActive(): void
    {
        $firstDirectory = $this->createDatabaseDirectory('first', 'Stable database marker');
        $corruptDirectory = $this->createDirectory('corrupt');
        self::assertNotFalse(file_put_contents(
            $corruptDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            'This is not a SQLite database.',
        ));
        $state = $this->registerDatabases([$firstDirectory, $corruptDirectory], $firstDirectory);
        $corruptId = $this->databaseId($state, $corruptDirectory);

        $this->assertFailedSwitchKeepsActiveDatabase($firstDirectory, $corruptId);
    }

    public function testMalformedWorkspaceDataIsRejectedBeforeTheSessionIsSwapped(): void
    {
        $firstDirectory = $this->createDatabaseDirectory('first', 'Stable database marker');
        $malformedDirectory = $this->createDatabaseDirectory('malformed', 'Malformed database marker');
        $database = new Database($malformedDirectory . DIRECTORY_SEPARATOR . 'app.sqlite', false);
        $collectionId = (int) $database->connection()
            ->query('SELECT id FROM collections ORDER BY id LIMIT 1')
            ->fetchColumn();
        $statement = $database->connection()->prepare(
            'INSERT INTO saved_requests (collection_id, name, method, url, request_json) '
            . 'VALUES (:collection_id, :name, :method, :url, :request_json)',
        );
        $statement->execute([
            'collection_id' => $collectionId,
            'name' => 'Broken payload',
            'method' => 'GET',
            'url' => 'https://example.test',
            'request_json' => '{not-json',
        ]);
        unset($statement, $database);

        $state = $this->registerDatabases([$firstDirectory, $malformedDirectory], $firstDirectory);
        $malformedId = $this->databaseId($state, $malformedDirectory);

        $this->assertFailedSwitchKeepsActiveDatabase($firstDirectory, $malformedId);
    }

    public function testOpeningAnEmptySqliteFileDoesNotInitializeOrActivateIt(): void
    {
        $activeDirectory = $this->createDatabaseDirectory('active', 'Stable database marker');
        $this->registerDatabases([$activeDirectory], $activeDirectory);
        $manager = new WorkspaceManager($this->projectDirectory);
        $manager->openInitial($activeDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');
        $activeSession = $manager->active();
        $settingsBefore = (string) file_get_contents(
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        $emptyDirectory = $this->createDirectory('empty-existing');
        $emptyPathname = $emptyDirectory . DIRECTORY_SEPARATOR . 'empty.sqlite';
        self::assertTrue(touch($emptyPathname));
        $hashBefore = hash_file('sha256', $emptyPathname);

        try {
            $manager->openExisting($emptyPathname);
            self::fail('The open-existing flow initialized an empty SQLite file.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The selected SQLite file is not a RelayDeck database.', $exception->getMessage());
        }

        self::assertSame(0, filesize($emptyPathname));
        self::assertSame($hashBefore, hash_file('sha256', $emptyPathname));
        self::assertSame($activeSession, $manager->active());
        self::assertSame(
            $settingsBefore,
            file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
        );
    }

    public function testItCreatesANewDatabaseAndOpensAnExistingDatabase(): void
    {
        $createdDirectory = $this->createDirectory('created');
        $manager = new WorkspaceManager($this->projectDirectory);

        $createdState = $manager->createDatabase($createdDirectory, '  Team API  ');

        $createdPathname = $createdDirectory . DIRECTORY_SEPARATOR . 'Team API.sqlite';
        self::assertFileExists($createdPathname);
        self::assertFileDoesNotExist($createdDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');
        self::assertSame($createdDirectory, $manager->active()->directory);
        self::assertSame($createdPathname, $manager->active()->pathname);
        self::assertCount(1, $createdState['databases']);
        self::assertSame($createdState['databases'][0]['id'], $createdState['activeDatabaseId']);
        self::assertSame('Team API.sqlite', $createdState['databases'][0]['name']);
        self::assertSame($createdPathname, $this->savedSettings()['databases'][0]['pathname']);
        self::assertSame(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'settings.json',
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        self::assertFileExists(StoragePathResolver::settingsFile($this->projectDirectory));
        self::assertFileDoesNotExist($createdDirectory . DIRECTORY_SEPARATOR . 'settings.json');
        $this->assertEmptyWorkspaceState($createdState);

        $manager->active()->collections->createCollection([
            'name' => 'Created database marker',
            'description' => '',
        ]);
        $existingFilename = 'Imported API.sqlite';
        $existingDirectory = $this->createDatabaseDirectory(
            'existing',
            'Existing database marker',
            $existingFilename,
        );

        $openedState = $manager->openExisting(
            $existingDirectory . DIRECTORY_SEPARATOR . $existingFilename,
        );

        self::assertSame($existingDirectory, $manager->active()->directory);
        self::assertContains('Existing database marker', $this->collectionNames($manager));
        self::assertNotContains('Created database marker', $this->collectionNames($manager));
        self::assertCount(2, $openedState['databases']);
        self::assertSame('Imported API.sqlite', $openedState['databases'][1]['name']);
        self::assertSame(
            $this->databaseId($openedState, $existingDirectory),
            $openedState['activeDatabaseId'],
        );
        self::assertSame(3, $this->savedSettings()['version']);
    }

    public function testInvalidNewDatabaseFilenameDoesNotCreateOrActivateDatabase(): void
    {
        $manager = new WorkspaceManager($this->projectDirectory);

        foreach (['   ', str_repeat('x', 121)] as $index => $name) {
            $directory = $this->createDirectory('invalid-name-' . $index);

            try {
                $manager->createDatabase($directory, $name);
                self::fail('An invalid database filename was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('database filename', strtolower($exception->getMessage()));
            }

            self::assertSame(0, iterator_count(new FilesystemIterator($directory)));
        }

        self::assertFalse($manager->hasActive());
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));
    }

    public function testNamedDatabasesInTheSameDirectoryStayIndependentAndFilenameCollisionIsAtomic(): void
    {
        $directory = $this->createDirectory('shared-databases');
        $manager = new WorkspaceManager($this->projectDirectory);

        $firstState = $manager->createDatabase($directory, 'First API');
        $firstPathname = $directory . DIRECTORY_SEPARATOR . 'First API.sqlite';
        $firstId = $firstState['activeDatabaseId'];
        self::assertIsString($firstId);
        $manager->active()->collections->createCollection([
            'name' => 'Only in first named database',
            'description' => '',
        ]);

        $secondState = $manager->createDatabase($directory, 'Second API.sqlite');
        $secondPathname = $directory . DIRECTORY_SEPARATOR . 'Second API.sqlite';
        $secondId = $secondState['activeDatabaseId'];
        self::assertIsString($secondId);
        self::assertNotSame($firstId, $secondId);
        self::assertSame(
            [$firstPathname, $secondPathname],
            array_column($secondState['databases'], 'pathname'),
        );
        self::assertSame(
            ['First API.sqlite', 'Second API.sqlite'],
            array_column($secondState['databases'], 'name'),
        );
        $manager->active()->collections->createCollection([
            'name' => 'Only in second named database',
            'description' => '',
        ]);

        $activeSessionBeforeCollision = $manager->active();
        $settingsBeforeCollision = (string) file_get_contents(
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        $firstHashBeforeCollision = hash_file('sha256', $firstPathname);
        $secondHashBeforeCollision = hash_file('sha256', $secondPathname);
        self::assertIsString($firstHashBeforeCollision);
        self::assertIsString($secondHashBeforeCollision);
        $collisionRejected = false;

        try {
            $manager->createDatabase($directory, 'First API');
        } catch (\RuntimeException $exception) {
            $collisionRejected = true;
            self::assertSame(
                'A file with this name already exists. Choose another database filename.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($collisionRejected, 'An existing named database must not be overwritten.');
        self::assertSame($activeSessionBeforeCollision, $manager->active());
        self::assertSame($secondPathname, $manager->active()->pathname);
        self::assertSame($secondId, $manager->state()['activeDatabaseId']);
        self::assertSame(
            $settingsBeforeCollision,
            file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
        );
        self::assertSame($firstHashBeforeCollision, hash_file('sha256', $firstPathname));
        self::assertSame($secondHashBeforeCollision, hash_file('sha256', $secondPathname));

        $manager->switchDatabase($firstId);
        self::assertSame($firstPathname, $manager->active()->pathname);
        self::assertContains('Only in first named database', $this->collectionNames($manager));
        self::assertNotContains('Only in second named database', $this->collectionNames($manager));

        $manager->switchDatabase($secondId);
        self::assertSame($secondPathname, $manager->active()->pathname);
        self::assertContains('Only in second named database', $this->collectionNames($manager));
        self::assertNotContains('Only in first named database', $this->collectionNames($manager));
    }

    public function testMissingNamedDatabaseNeverFallsBackToAppSqliteInTheSameDirectory(): void
    {
        $directory = $this->createDirectory('exact-path-switch');
        $manager = new WorkspaceManager($this->projectDirectory);

        $namedState = $manager->createDatabase($directory, 'Named API');
        $namedPathname = $directory . DIRECTORY_SEPARATOR . 'Named API.sqlite';
        $namedId = $namedState['activeDatabaseId'];
        self::assertIsString($namedId);

        $manager->createDatabase($directory, 'app.sqlite');
        $defaultPathname = $directory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $manager->active()->collections->createCollection([
            'name' => 'Only in app.sqlite',
            'description' => '',
        ]);
        $activeSession = $manager->active();
        $activeId = $manager->state()['activeDatabaseId'];
        $settingsBefore = (string) file_get_contents(
            StoragePathResolver::settingsFile($this->projectDirectory),
        );

        self::assertTrue(unlink($namedPathname));

        try {
            $manager->switchDatabase($namedId);
            self::fail('A missing named database was silently replaced with app.sqlite.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unavailable', strtolower($exception->getMessage()));
        }

        self::assertSame($activeSession, $manager->active());
        self::assertSame($defaultPathname, $manager->active()->pathname);
        self::assertSame($activeId, $manager->state()['activeDatabaseId']);
        self::assertContains('Only in app.sqlite', $this->collectionNames($manager));
        self::assertSame(
            $settingsBefore,
            file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
        );
    }

    public function testItCreatesAndRegistersTheDefaultDatabaseBesideTheApplication(): void
    {
        $defaultDirectory = StoragePathResolver::defaultDirectory($this->projectDirectory);
        $manager = new WorkspaceManager($this->projectDirectory);

        self::assertDirectoryDoesNotExist($defaultDirectory);
        $state = $manager->openDefault($defaultDirectory);

        self::assertDirectoryExists($defaultDirectory);
        self::assertFileExists($defaultDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');
        self::assertSame(
            $defaultDirectory . DIRECTORY_SEPARATOR . 'settings.json',
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        self::assertFileExists($defaultDirectory . DIRECTORY_SEPARATOR . 'settings.json');
        self::assertSame($defaultDirectory, $manager->active()->directory);
        self::assertSame(
            $defaultDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            StoragePathResolver::resolve($this->projectDirectory),
        );
        self::assertCount(1, $state['databases']);
        self::assertSame($state['databases'][0]['id'], $state['activeDatabaseId']);
        self::assertSame($defaultDirectory, $state['databases'][0]['directory']);
        $this->assertEmptyWorkspaceState($state);
        self::assertSame(3, $this->savedSettings()['version']);
    }

    public function testInvalidDefaultPathDoesNotCreateSettingsOrAnActiveSession(): void
    {
        $defaultDirectory = StoragePathResolver::defaultDirectory($this->projectDirectory);
        self::assertSame(1, file_put_contents($defaultDirectory, 'x'));
        $manager = new WorkspaceManager($this->projectDirectory);
        $failed = false;

        try {
            $manager->openDefault($defaultDirectory);
        } catch (\RuntimeException $exception) {
            $failed = true;
            self::assertSame('The default storage path is not a directory.', $exception->getMessage());
        }

        self::assertTrue($failed);
        self::assertFalse($manager->hasActive());
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));
    }

    public function testRetryCreatesTheAdjacentDefaultWhenNoSettingsExist(): void
    {
        $manager = new WorkspaceManager($this->projectDirectory);
        $state = $manager->retryConfigured();
        $defaultDirectory = StoragePathResolver::defaultDirectory($this->projectDirectory);

        self::assertSame($defaultDirectory, $manager->active()->directory);
        self::assertFileExists($defaultDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');
        self::assertSame($state['databases'][0]['id'], $state['activeDatabaseId']);
        self::assertSame($defaultDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));
    }

    public function testRetryDoesNotCreateDefaultDatabaseWhenLegacySettingsPointToMissingDatabase(): void
    {
        $missingPathname = $this->temporaryDirectory
            . DIRECTORY_SEPARATOR . 'missing-database'
            . DIRECTORY_SEPARATOR . 'app.sqlite';
        $legacySettingsFile = $this->legacySettingsFile();
        self::assertTrue(mkdir(dirname($legacySettingsFile), 0700, true));
        self::assertNotFalse(file_put_contents(
            $legacySettingsFile,
            json_encode([
                'version' => 3,
                'activeDatabaseId' => 'db_missing',
                'databases' => [[
                    'id' => 'db_missing',
                    'pathname' => $missingPathname,
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        $manager = new WorkspaceManager($this->projectDirectory);
        $failed = false;

        try {
            $manager->retryConfigured();
        } catch (\RuntimeException $exception) {
            $failed = true;
            self::assertSame(
                'The saved database is still unavailable. Restore access to its folder and try again.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($failed, 'Missing legacy database settings must not be treated as a first run.');
        self::assertFalse($manager->hasActive());
        self::assertFileExists($legacySettingsFile);
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));
        self::assertFileDoesNotExist(StoragePathResolver::defaultPathname($this->projectDirectory));
    }

    /**
     * @param list<string> $directories
     * @return array<string, mixed>
     */
    private function registerDatabases(array $directories, string $activeDirectory): array
    {
        foreach ($directories as $directory) {
            StoragePathResolver::remember(
                $directory . DIRECTORY_SEPARATOR . 'app.sqlite',
                $this->projectDirectory,
            );
        }

        StoragePathResolver::remember(
            $activeDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            $this->projectDirectory,
        );

        return StoragePathResolver::databaseState($this->projectDirectory);
    }

    /** @param array<string, mixed> $state */
    private function databaseId(array $state, string $directory): string
    {
        $directory = realpath($directory);
        self::assertIsString($directory);

        foreach ($state['databases'] as $database) {
            if (realpath($database['directory']) === $directory) {
                return $database['id'];
            }
        }

        self::fail(sprintf('No registered database points to "%s".', $directory));
    }

    private function assertFailedSwitchKeepsActiveDatabase(
        string $activeDirectory,
        string $candidateId,
    ): void {
        $manager = new WorkspaceManager($this->projectDirectory);
        $manager->openInitial($activeDirectory . DIRECTORY_SEPARATOR . 'app.sqlite');
        $activeSession = $manager->active();
        $settingsBefore = (string) file_get_contents(
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        $activeIdBefore = $manager->state()['activeDatabaseId'];
        $failed = false;

        try {
            $manager->switchDatabase($candidateId);
        } catch (Throwable $exception) {
            $failed = true;
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertTrue($failed, 'Switching to an invalid candidate must fail.');
        self::assertSame($activeSession, $manager->active());
        self::assertSame($activeDirectory, $manager->active()->directory);
        self::assertContains('Stable database marker', $this->collectionNames($manager));
        self::assertSame($activeIdBefore, $manager->state()['activeDatabaseId']);
        self::assertSame(
            $settingsBefore,
            file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
        );
    }

    /** @param array<string, mixed> $state */
    private function assertEmptyWorkspaceState(array $state): void
    {
        self::assertSame([], $state['collections']);
        self::assertSame([], $state['savedRequests']);
        self::assertSame([], $state['history']);
        self::assertSame([], $state['environments']);
        self::assertNull($state['activeEnvironmentId']);
    }

    /** @return list<string> */
    private function collectionNames(WorkspaceManager $manager): array
    {
        return array_column($manager->active()->collections->all(), 'name');
    }

    /** @return array<string, mixed> */
    private function savedSettings(): array
    {
        return json_decode(
            (string) file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function createDatabaseDirectory(
        string $name,
        string $marker,
        string $filename = 'app.sqlite',
    ): string
    {
        $directory = $this->createDirectory($name);
        $database = new Database($directory . DIRECTORY_SEPARATOR . $filename, true);
        $statement = $database->connection()->prepare(
            'INSERT INTO collections (name, description) VALUES (:name, :description)',
        );
        $statement->execute(['name' => $marker, 'description' => 'Workspace manager test marker.']);
        unset($statement, $database);

        return $directory;
    }

    private function createDirectory(string $name): string
    {
        $directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(mkdir($directory, 0700));
        $resolved = realpath($directory);
        self::assertIsString($resolved);

        return $resolved;
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        self::assertTrue(putenv($value === null ? $name : $name . '=' . $value));
    }

    private function legacySettingsFile(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => $this->temporaryDirectory
                . DIRECTORY_SEPARATOR . 'local-appdata'
                . DIRECTORY_SEPARATOR . 'RelayDeck'
                . DIRECTORY_SEPARATOR . 'settings.json',
            'Darwin' => $this->temporaryDirectory
                . DIRECTORY_SEPARATOR . 'home'
                . DIRECTORY_SEPARATOR . 'Library'
                . DIRECTORY_SEPARATOR . 'Application Support'
                . DIRECTORY_SEPARATOR . 'RelayDeck'
                . DIRECTORY_SEPARATOR . 'settings.json',
            default => $this->temporaryDirectory
                . DIRECTORY_SEPARATOR . 'xdg-config'
                . DIRECTORY_SEPARATOR . 'relay-deck'
                . DIRECTORY_SEPARATOR . 'settings.json',
        };
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
            || !str_starts_with(basename($this->temporaryDirectory), 'relaydeck-workspace-manager-')
        ) {
            throw new \LogicException('Refusing to clean an unexpected workspace manager test directory.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->temporaryDirectory);
    }
}
