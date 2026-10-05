<?php

declare(strict_types=1);

namespace ApiClient\Tests\Application;

use ApiClient\Application\StoragePathResolver;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(StoragePathResolver::class)]
final class StoragePathResolverTest extends TestCase
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
            . DIRECTORY_SEPARATOR . 'relaydeck-storage-resolver-' . bin2hex(random_bytes(8));
        $this->projectDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'project';

        self::assertTrue(mkdir($this->projectDirectory, 0700, true));
        self::assertTrue(mkdir($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home', 0700));
        $this->setEnvironment(
            'XDG_CONFIG_HOME',
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-xdg',
        );
        $this->setEnvironment('LOCALAPPDATA', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-local');
        $this->setEnvironment('APPDATA', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-roaming');
        $this->setEnvironment('HOME', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home');
        $this->setEnvironment('USERPROFILE', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $this->setEnvironment($name, $value === false ? null : $value);
        }

        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testFirstRunExposesTheAdjacentDefaultWithoutPersistingItEarly(): void
    {
        self::assertNull(StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage',
            StoragePathResolver::defaultDirectory($this->projectDirectory),
        );
        self::assertSame(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app.sqlite',
            StoragePathResolver::defaultPathname($this->projectDirectory),
        );
        self::assertSame(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'settings.json',
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        self::assertNull(StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertNull(StoragePathResolver::configuredDirectory($this->projectDirectory));
        self::assertFalse(StoragePathResolver::usesEnvironmentOverride());
        self::assertFalse(StoragePathResolver::hasSettings($this->projectDirectory));
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));
        self::assertDirectoryDoesNotExist($this->projectDirectory . DIRECTORY_SEPARATOR . 'storage');
    }

    public function testItRemembersAndResolvesADatabasePathname(): void
    {
        $storageDirectory = $this->createStorageDirectory('remembered');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';

        self::assertSame(
            $pathname,
            StoragePathResolver::remember($pathname, $this->projectDirectory),
        );
        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($storageDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));
        self::assertFileExists(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'settings.json',
        );
        self::assertFileDoesNotExist($storageDirectory . DIRECTORY_SEPARATOR . 'settings.json');

        $settings = $this->readSettings();

        self::assertSame(3, $settings['version']);
        self::assertCount(1, $settings['databases']);
        self::assertSame($settings['databases'][0]['id'], $settings['activeDatabaseId']);
        self::assertSame($pathname, $settings['databases'][0]['pathname']);
    }

    public function testLegacyOperatingSystemSettingsAreReadAndArchivedAfterMigration(): void
    {
        $storageDirectory = $this->createStorageDirectory('legacy-config-database');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $databaseId = 'db_from_legacy_config';
        $legacySettingsFile = $this->legacySettingsFile();
        $this->writeSettingsTo($legacySettingsFile, [
            'version' => 3,
            'activeDatabaseId' => $databaseId,
            'databases' => [[
                'id' => $databaseId,
                'pathname' => $pathname,
            ]],
        ]);

        $canonicalSettingsFile = StoragePathResolver::settingsFile($this->projectDirectory);
        self::assertFileDoesNotExist($canonicalSettingsFile);
        self::assertTrue(StoragePathResolver::hasSettings($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertFileDoesNotExist($canonicalSettingsFile);

        StoragePathResolver::remember($pathname, $this->projectDirectory);

        self::assertFileExists($canonicalSettingsFile);
        self::assertFileDoesNotExist($legacySettingsFile);
        self::assertFileExists($legacySettingsFile . '.migrated');
        $settings = $this->readSettings();
        self::assertSame($databaseId, $settings['activeDatabaseId']);
        self::assertSame([[
            'id' => $databaseId,
            'pathname' => $pathname,
        ]], $settings['databases']);
    }

    public function testCanonicalSettingsTakePriorityOverLegacyOperatingSystemSettings(): void
    {
        $canonicalDirectory = $this->createStorageDirectory('canonical-config-database');
        $canonicalPathname = $canonicalDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $legacyDirectory = $this->createStorageDirectory('stale-legacy-config-database');
        $legacyPathname = $legacyDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $this->writeSettings([
            'version' => 3,
            'activeDatabaseId' => 'db_canonical',
            'databases' => [[
                'id' => 'db_canonical',
                'pathname' => $canonicalPathname,
            ]],
        ]);
        $legacySettingsFile = $this->legacySettingsFile();
        $this->writeSettingsTo($legacySettingsFile, [
            'version' => 3,
            'activeDatabaseId' => 'db_stale_legacy',
            'databases' => [[
                'id' => 'db_stale_legacy',
                'pathname' => $legacyPathname,
            ]],
        ]);

        self::assertSame($canonicalPathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($canonicalPathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertFileExists($legacySettingsFile);
        self::assertFileDoesNotExist($legacySettingsFile . '.migrated');
    }

    public function testInvalidCanonicalSettingsDoNotFallBackToStaleLegacySettings(): void
    {
        $legacyDirectory = $this->createStorageDirectory('legacy-config-behind-invalid-canonical');
        $legacyPathname = $legacyDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $legacySettingsFile = $this->legacySettingsFile();
        $this->writeSettingsTo($legacySettingsFile, [
            'version' => 3,
            'activeDatabaseId' => 'db_stale_legacy',
            'databases' => [[
                'id' => 'db_stale_legacy',
                'pathname' => $legacyPathname,
            ]],
        ]);
        $canonicalSettingsFile = StoragePathResolver::settingsFile($this->projectDirectory);
        $canonicalSettingsDirectory = dirname($canonicalSettingsFile);
        self::assertTrue(mkdir($canonicalSettingsDirectory, 0700, true));
        self::assertSame(9, file_put_contents($canonicalSettingsFile, "{invalid\n"));

        self::assertTrue(StoragePathResolver::hasSettings($this->projectDirectory));
        self::assertNull(StoragePathResolver::resolve($this->projectDirectory));
        self::assertNull(StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertFileExists($legacySettingsFile);
    }

    public function testNonFileCanonicalSettingsDoNotFallBackToStaleLegacySettings(): void
    {
        $legacyDirectory = $this->createStorageDirectory('legacy-config-behind-canonical-directory');
        $legacyPathname = $legacyDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $this->writeSettingsTo($this->legacySettingsFile(), [
            'version' => 3,
            'activeDatabaseId' => 'db_stale_legacy',
            'databases' => [[
                'id' => 'db_stale_legacy',
                'pathname' => $legacyPathname,
            ]],
        ]);
        $canonicalSettingsFile = StoragePathResolver::settingsFile($this->projectDirectory);
        self::assertTrue(mkdir($canonicalSettingsFile, 0700, true));

        self::assertTrue(StoragePathResolver::hasSettings($this->projectDirectory));
        self::assertNull(StoragePathResolver::resolve($this->projectDirectory));
        self::assertNull(StoragePathResolver::configuredPathname($this->projectDirectory));
    }

    public function testVersionOneSettingsAreReadAndMigratedOnTheNextWrite(): void
    {
        $storageDirectory = $this->createStorageDirectory('legacy-settings');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $this->writeSettings([
            'version' => 1,
            'storageDirectory' => $storageDirectory,
        ]);

        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($storageDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));

        $state = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertCount(1, $state['databases']);
        self::assertSame($pathname, $state['databases'][0]['pathname']);
        self::assertSame($storageDirectory, $state['databases'][0]['directory']);
        self::assertTrue($state['databases'][0]['active']);
        self::assertSame(1, $this->readSettings()['version']);

        StoragePathResolver::remember($pathname, $this->projectDirectory);
        $settings = $this->readSettings();

        self::assertSame(3, $settings['version']);
        self::assertCount(1, $settings['databases']);
        self::assertSame($settings['databases'][0]['id'], $settings['activeDatabaseId']);
        self::assertSame($pathname, $settings['databases'][0]['pathname']);
    }

    public function testVersionTwoSettingsAreReadAndMigratedToPathnamesOnTheNextWrite(): void
    {
        $storageDirectory = $this->createStorageDirectory('version-two-settings');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $databaseId = 'db_legacy_version_two';
        $this->writeSettings([
            'version' => 2,
            'activeDatabaseId' => $databaseId,
            'databases' => [[
                'id' => $databaseId,
                'name' => 'Legacy display name',
                'directory' => $storageDirectory,
            ]],
        ]);

        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($storageDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));

        $state = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertSame($databaseId, $state['activeDatabaseId']);
        self::assertCount(1, $state['databases']);
        self::assertSame($databaseId, $state['databases'][0]['id']);
        self::assertSame('app.sqlite', $state['databases'][0]['name']);
        self::assertSame($pathname, $state['databases'][0]['pathname']);
        self::assertSame($storageDirectory, $state['databases'][0]['directory']);
        self::assertTrue($state['databases'][0]['available']);
        self::assertTrue($state['databases'][0]['active']);
        self::assertSame(2, $this->readSettings()['version']);

        StoragePathResolver::remember($pathname, $this->projectDirectory);
        $settings = $this->readSettings();

        self::assertSame(3, $settings['version']);
        self::assertSame($databaseId, $settings['activeDatabaseId']);
        self::assertSame([[
            'id' => $databaseId,
            'pathname' => $pathname,
        ]], $settings['databases']);
    }

    public function testExplicitEnvironmentDirectoryHasPriorityOverRememberedSettings(): void
    {
        $rememberedDirectory = $this->createStorageDirectory('remembered');
        StoragePathResolver::remember(
            $rememberedDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            $this->projectDirectory,
        );

        $legacyEnvironmentDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'api-client';
        $overrideDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'override';
        $this->setEnvironment('API_CLIENT_STORAGE_DIR', $legacyEnvironmentDirectory);
        $this->setEnvironment('RELAYDECK_STORAGE_DIR', $overrideDirectory . DIRECTORY_SEPARATOR);

        self::assertTrue(StoragePathResolver::usesEnvironmentOverride());
        self::assertSame(
            $overrideDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            StoragePathResolver::resolve($this->projectDirectory),
        );
    }

    public function testStorageEnvironmentOverrideDoesNotCreateSettings(): void
    {
        $overrideDirectory = $this->createStorageDirectory('environment-only');
        $this->setEnvironment('RELAYDECK_STORAGE_DIR', $overrideDirectory);

        self::assertSame(
            $overrideDirectory . DIRECTORY_SEPARATOR . 'app.sqlite',
            StoragePathResolver::resolve($this->projectDirectory),
        );
        self::assertSame('environment-override', StoragePathResolver::databaseState(
            $this->projectDirectory,
        )['activeDatabaseId']);
        self::assertFalse(StoragePathResolver::hasSettings($this->projectDirectory));
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));
        self::assertFileDoesNotExist($this->legacySettingsFile());
    }

    public function testExplicitConfigDirectoryRemainsAnOptInSettingsLocation(): void
    {
        $configDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'explicit-config';
        $this->setEnvironment('RELAYDECK_CONFIG_DIR', $configDirectory);
        $storageDirectory = $this->createStorageDirectory('configured-with-explicit-config');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';

        StoragePathResolver::remember($pathname, $this->projectDirectory);

        self::assertSame(
            $configDirectory . DIRECTORY_SEPARATOR . 'settings.json',
            StoragePathResolver::settingsFile($this->projectDirectory),
        );
        self::assertFileExists($configDirectory . DIRECTORY_SEPARATOR . 'settings.json');
        self::assertFileDoesNotExist(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'settings.json',
        );
    }

    public function testItFindsAnExistingLegacyDatabaseWithoutPersistingItBeforeValidation(): void
    {
        $legacyDirectory = $this->projectDirectory . DIRECTORY_SEPARATOR . 'storage';
        self::assertTrue(mkdir($legacyDirectory, 0700));
        $pathname = $legacyDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        self::assertTrue(touch($pathname));

        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertFileDoesNotExist(StoragePathResolver::settingsFile($this->projectDirectory));

        StoragePathResolver::remember($pathname, $this->projectDirectory);

        $settings = $this->readSettings();

        self::assertSame(3, $settings['version']);
        self::assertCount(1, $settings['databases']);
        self::assertSame($settings['databases'][0]['id'], $settings['activeDatabaseId']);
        self::assertSame($pathname, $settings['databases'][0]['pathname']);
    }

    public function testSavedDirectoryWithoutDatabaseReturnsNull(): void
    {
        $storageDirectory = $this->createStorageDirectory('missing-database');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($pathname, $this->projectDirectory);
        self::assertTrue(unlink($pathname));

        self::assertNull(StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($pathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($storageDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));
    }

    public function testItRemembersTwoDatabasesAndMakesTheLatestOneActive(): void
    {
        $firstDirectory = $this->createStorageDirectory('first');
        $secondDirectory = $this->createStorageDirectory('second');
        $firstPathname = $firstDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $secondPathname = $secondDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($firstPathname, $this->projectDirectory);
        StoragePathResolver::remember($secondPathname, $this->projectDirectory);

        self::assertSame($secondPathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($secondDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));

        $state = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertCount(2, $state['databases']);
        self::assertSame(
            [$firstDirectory, $secondDirectory],
            array_column($state['databases'], 'directory'),
        );
        self::assertSame([$firstPathname, $secondPathname], array_column($state['databases'], 'pathname'));
        self::assertSame(['app.sqlite', 'app.sqlite'], array_column($state['databases'], 'name'));
        self::assertSame([false, true], array_column($state['databases'], 'active'));
        self::assertSame($state['databases'][1]['id'], $state['activeDatabaseId']);

        self::assertSame(
            ['settings.json'],
            array_values(array_diff(scandir(dirname(StoragePathResolver::settingsFile($this->projectDirectory))), ['.', '..'])),
        );
    }

    public function testItActivatesARegisteredDatabase(): void
    {
        $firstDirectory = $this->createStorageDirectory('first');
        $secondDirectory = $this->createStorageDirectory('second');
        $firstPathname = $firstDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        $secondPathname = $secondDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($firstPathname, $this->projectDirectory);
        StoragePathResolver::remember($secondPathname, $this->projectDirectory);

        $before = StoragePathResolver::databaseState($this->projectDirectory);
        $firstDatabaseId = $before['databases'][0]['id'];

        self::assertSame(
            $firstPathname,
            StoragePathResolver::activateDatabase($firstDatabaseId, $this->projectDirectory),
        );
        self::assertSame($firstPathname, StoragePathResolver::resolve($this->projectDirectory));
        self::assertSame($firstPathname, StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame($firstDirectory, StoragePathResolver::configuredDirectory($this->projectDirectory));
        self::assertSame($firstPathname, StoragePathResolver::databasePathnameForId(
            $firstDatabaseId,
            $this->projectDirectory,
        ));
        self::assertSame($firstDirectory, StoragePathResolver::databaseDirectory(
            $firstDatabaseId,
            $this->projectDirectory,
        ));

        $after = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertSame($firstDatabaseId, $after['activeDatabaseId']);
        self::assertSame([true, false], array_column($after['databases'], 'active'));
    }

    public function testItPreservesAnOfflineDatabaseWhenAnotherDatabaseIsRemembered(): void
    {
        $offlineDirectory = $this->createStorageDirectory('offline');
        $offlinePathname = $offlineDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($offlinePathname, $this->projectDirectory);
        $offlineDatabaseId = StoragePathResolver::databaseState($this->projectDirectory)['activeDatabaseId'];
        self::assertIsString($offlineDatabaseId);
        self::assertTrue(unlink($offlinePathname));

        $availableDirectory = $this->createStorageDirectory('available');
        $availablePathname = $availableDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($availablePathname, $this->projectDirectory);

        $state = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertCount(2, $state['databases']);
        self::assertSame(
            [$offlineDirectory, $availableDirectory],
            array_column($state['databases'], 'directory'),
        );
        self::assertSame([false, true], array_column($state['databases'], 'available'));
        self::assertSame([false, true], array_column($state['databases'], 'active'));
        self::assertSame($offlinePathname, StoragePathResolver::databasePathnameForId(
            $offlineDatabaseId,
            $this->projectDirectory,
        ));
        self::assertSame($offlineDirectory, StoragePathResolver::databaseDirectory(
            $offlineDatabaseId,
            $this->projectDirectory,
        ));
    }

    public function testItDoesNotDuplicateTheSameCanonicalDatabasePathname(): void
    {
        $storageDirectory = $this->createStorageDirectory('canonical database');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';
        StoragePathResolver::remember($pathname, $this->projectDirectory);
        $originalState = StoragePathResolver::databaseState($this->projectDirectory);
        $databaseId = $originalState['activeDatabaseId'];

        StoragePathResolver::remember(
            $this->fileUrl($pathname),
            $this->projectDirectory,
        );

        $state = StoragePathResolver::databaseState($this->projectDirectory);
        self::assertCount(1, $state['databases']);
        self::assertSame($databaseId, $state['activeDatabaseId']);
        self::assertSame($databaseId, $state['databases'][0]['id']);
        self::assertSame('app.sqlite', $state['databases'][0]['name']);
        self::assertSame($storageDirectory, $state['databases'][0]['directory']);
        self::assertSame($pathname, $state['databases'][0]['pathname']);
    }

    public function testItNormalizesAFileUrlFromTheNativeDirectoryPicker(): void
    {
        $storageDirectory = $this->createStorageDirectory('User records #1');
        $pathname = $storageDirectory . DIRECTORY_SEPARATOR . 'app.sqlite';

        self::assertSame(
            $pathname,
            StoragePathResolver::remember($this->fileUrl($pathname), $this->projectDirectory),
        );
        self::assertSame($pathname, StoragePathResolver::resolve($this->projectDirectory));
    }

    public function testItAcceptsAnySqliteFilenameAndRejectsOtherExtensions(): void
    {
        $directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'wrong-database-name';
        self::assertTrue(mkdir($directory, 0700));
        $pathname = $directory . DIRECTORY_SEPARATOR . 'Project API.SQLITE';
        self::assertTrue(touch($pathname));
        self::assertSame($pathname, StoragePathResolver::validateDatabaseFile($pathname));

        $wrongPathname = $directory . DIRECTORY_SEPARATOR . 'project.db';
        self::assertTrue(touch($wrongPathname));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Select a readable and writable RelayDeck .sqlite file.');

        StoragePathResolver::validateDatabaseFile($wrongPathname);
    }

    public function testASettingsPathWithANullByteIsRejectedWithoutFilesystemErrors(): void
    {
        $this->writeSettings([
            'version' => 3,
            'activeDatabaseId' => 'db_invalid_path',
            'databases' => [[
                'id' => 'db_invalid_path',
                'pathname' => $this->temporaryDirectory . DIRECTORY_SEPARATOR . "bad\0path.sqlite",
            ]],
        ]);
        $settingsBefore = (string) file_get_contents(
            StoragePathResolver::settingsFile($this->projectDirectory),
        );

        self::assertNull(StoragePathResolver::resolve($this->projectDirectory));
        self::assertNull(StoragePathResolver::configuredPathname($this->projectDirectory));
        self::assertSame([], StoragePathResolver::databaseState($this->projectDirectory)['databases']);
        self::assertSame(
            $settingsBefore,
            file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
        );
    }

    public function testItNormalizesDatabaseFilenames(): void
    {
        self::assertSame(
            'Team API.sqlite',
            StoragePathResolver::normalizeDatabaseFilename('  Team API  '),
        );
        self::assertSame(
            'Team API.sqlite',
            StoragePathResolver::normalizeDatabaseFilename('Team API.sqlite'),
        );
        self::assertSame(
            'Team API.SQLITE',
            StoragePathResolver::normalizeDatabaseFilename('Team API.SQLITE'),
        );
        self::assertSame(
            'Команда.sqlite',
            StoragePathResolver::normalizeDatabaseFilename('Команда'),
        );
        self::assertSame(
            str_repeat('x', 113) . '.sqlite',
            StoragePathResolver::normalizeDatabaseFilename(str_repeat('x', 113)),
        );
    }

    public function testItRejectsUnsafeOrInvalidDatabaseFilenames(): void
    {
        $invalidFilenames = [
            '',
            '   ',
            '.',
            '..',
            '.sqlite',
            '..sqlite',
            '../outside',
            'folder/database',
            'folder\\database',
            'database.db',
            "database\0name",
            'CON',
            'con.sqlite',
            'LPT9.sqlite',
            str_repeat('x', 114),
            str_repeat('😀', 60),
        ];

        foreach ($invalidFilenames as $filename) {
            try {
                StoragePathResolver::normalizeDatabaseFilename($filename);
                self::fail(sprintf('Invalid database filename "%s" was accepted.', addcslashes($filename, "\0..\37")));
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('filename', strtolower($exception->getMessage()));
            }
        }
    }

    private function createStorageDirectory(string $name): string
    {
        $directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $name;

        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(touch($directory . DIRECTORY_SEPARATOR . 'app.sqlite'));

        $resolved = realpath($directory);
        self::assertIsString($resolved);

        return $resolved;
    }

    private function fileUrl(string $directory): string
    {
        $path = str_replace('\\', '/', $directory);
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        if (PHP_OS_FAMILY === 'Windows') {
            $encoded = '/' . str_replace('%3A', ':', $encoded);
        }

        return 'file://' . $encoded;
    }

    /** @return array<string, mixed> */
    private function readSettings(): array
    {
        $settings = json_decode(
            (string) file_get_contents(StoragePathResolver::settingsFile($this->projectDirectory)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($settings);

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function writeSettings(array $settings): void
    {
        $this->writeSettingsTo(StoragePathResolver::settingsFile($this->projectDirectory), $settings);
    }

    /** @param array<string, mixed> $settings */
    private function writeSettingsTo(string $settingsFile, array $settings): void
    {
        $settingsDirectory = dirname($settingsFile);

        if (!is_dir($settingsDirectory)) {
            self::assertTrue(mkdir($settingsDirectory, 0700, true));
        }

        $json = json_encode(
            $settings,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;

        self::assertSame(strlen($json), file_put_contents($settingsFile, $json));
    }

    private function legacySettingsFile(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-local'
                . DIRECTORY_SEPARATOR . 'RelayDeck' . DIRECTORY_SEPARATOR . 'settings.json';
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'home'
                . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'Application Support'
                . DIRECTORY_SEPARATOR . 'RelayDeck' . DIRECTORY_SEPARATOR . 'settings.json';
        }

        return $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-xdg'
            . DIRECTORY_SEPARATOR . 'relay-deck' . DIRECTORY_SEPARATOR . 'settings.json';
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        self::assertTrue(putenv($value === null ? $name : $name . '=' . $value));
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
            || !str_starts_with(basename($this->temporaryDirectory), 'relaydeck-storage-resolver-')
        ) {
            throw new \LogicException('Refusing to clean an unexpected test directory.');
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
