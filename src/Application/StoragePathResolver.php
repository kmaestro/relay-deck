<?php

declare(strict_types=1);

namespace ApiClient\Application;

/**
 * Resolves and persists the list of RelayDeck SQLite databases.
 *
 * The registry is stored in storage/settings.json beside the application.
 * Settings version 3 identifies each database by its full pathname. Versions
 * 1 and 2 are read transparently as <storage directory>/app.sqlite and are
 * upgraded on the next settings write without renaming the database file.
 */
final class StoragePathResolver
{
    private const int SETTINGS_VERSION = 3;
    private const int MAX_DATABASES = 64;
    private const string SETTINGS_FILE = 'settings.json';
    private const string DEFAULT_DATABASE_FILE = 'app.sqlite';

    public static function resolve(string $projectDirectory): ?string
    {
        $override = self::environmentValue('RELAYDECK_STORAGE_DIR')
            ?? self::environmentValue('API_CLIENT_STORAGE_DIR');

        if ($override !== null) {
            return self::databasePathname($override, self::DEFAULT_DATABASE_FILE);
        }

        $settingsFile = self::settingsSourceFile($projectDirectory);

        if (is_file($settingsFile)) {
            $settings = self::readSettings($settingsFile);
            $active = self::activeDatabase($settings);

            return $active === null
                ? null
                : self::availableDatabasePathname($active['pathname']);
        }

        $legacy = self::availableDatabasePathname(self::defaultPathname($projectDirectory));

        if ($legacy === null) {
            return null;
        }

        return $legacy;
    }

    public static function configuredPathname(string $projectDirectory): ?string
    {
        $active = self::activeDatabase(self::readSettings(self::settingsSourceFile($projectDirectory)));

        return $active['pathname'] ?? null;
    }

    public static function configuredDirectory(string $projectDirectory): ?string
    {
        $pathname = self::configuredPathname($projectDirectory);

        return $pathname === null ? null : dirname($pathname);
    }

    /**
     * Returns storage beside the native executable/PHAR, or project storage
     * when RelayDeck is started from source with `php index.php`.
     */
    public static function defaultDirectory(string $projectDirectory): string
    {
        return self::legacyDirectory($projectDirectory);
    }

    public static function defaultPathname(string $projectDirectory): string
    {
        return self::databasePathname(self::defaultDirectory($projectDirectory), self::DEFAULT_DATABASE_FILE);
    }

    public static function usesEnvironmentOverride(): bool
    {
        return self::environmentValue('RELAYDECK_STORAGE_DIR') !== null
            || self::environmentValue('API_CLIENT_STORAGE_DIR') !== null;
    }

    /**
     * @return array{
     *     databases: list<array{id: string, name: string, directory: string, pathname: string, available: bool, active: bool}>,
     *     activeDatabaseId: string|null,
     *     databaseSwitchingAllowed: bool,
     *     managedByEnvironment: bool
     * }
     */
    public static function databaseState(string $projectDirectory): array
    {
        if (self::usesEnvironmentOverride()) {
            $directory = self::normalizePath(
                self::environmentValue('RELAYDECK_STORAGE_DIR')
                    ?? (string) self::environmentValue('API_CLIENT_STORAGE_DIR'),
            );
            $pathname = self::databasePathname($directory, self::DEFAULT_DATABASE_FILE);

            return [
                'databases' => [[
                    'id' => 'environment-override',
                    'name' => basename($pathname),
                    'directory' => $directory,
                    'pathname' => $pathname,
                    'available' => self::availableDatabasePathname($pathname) !== null,
                    'active' => true,
                ]],
                'activeDatabaseId' => 'environment-override',
                'databaseSwitchingAllowed' => false,
                'managedByEnvironment' => true,
            ];
        }

        $settings = self::readSettings(self::settingsSourceFile($projectDirectory));
        $activeId = $settings['activeDatabaseId'] ?? null;
        $databases = [];

        foreach ($settings['databases'] ?? [] as $database) {
            $databases[] = [
                ...$database,
                'name' => basename($database['pathname']),
                'directory' => dirname($database['pathname']),
                'available' => self::availableDatabasePathname($database['pathname']) !== null,
                'active' => $database['id'] === $activeId,
            ];
        }

        return [
            'databases' => $databases,
            'activeDatabaseId' => is_string($activeId) ? $activeId : null,
            'databaseSwitchingAllowed' => true,
            'managedByEnvironment' => false,
        ];
    }

    public static function databasePathnameForId(string $databaseId, string $projectDirectory): ?string
    {
        if (self::usesEnvironmentOverride()) {
            return null;
        }

        foreach (self::readSettings(self::settingsSourceFile($projectDirectory))['databases'] ?? [] as $database) {
            if ($database['id'] === $databaseId) {
                return $database['pathname'];
            }
        }

        return null;
    }

    public static function databaseDirectory(string $databaseId, string $projectDirectory): ?string
    {
        $pathname = self::databasePathnameForId($databaseId, $projectDirectory);

        return $pathname === null ? null : dirname($pathname);
    }

    public static function isRegisteredDatabasePathname(
        string $pathname,
        string $projectDirectory,
    ): bool {
        $pathKey = self::pathKey($pathname);

        foreach (self::readSettings(self::settingsSourceFile($projectDirectory))['databases'] ?? [] as $database) {
            if (self::pathKey($database['pathname']) === $pathKey) {
                return true;
            }
        }

        return false;
    }

    public static function activateDatabase(string $databaseId, string $projectDirectory): string
    {
        if (self::usesEnvironmentOverride()) {
            throw new \RuntimeException(
                'Database switching is disabled because RELAYDECK_STORAGE_DIR is active.',
            );
        }

        $settingsFile = self::settingsFile($projectDirectory);
        $settingsSource = self::settingsSourceFile($projectDirectory);
        $settings = self::readSettings($settingsSource);
        $matchedIndex = null;

        foreach ($settings['databases'] ?? [] as $index => $database) {
            if ($database['id'] === $databaseId) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            throw new \RuntimeException('The selected RelayDeck database is not registered.');
        }

        $pathname = self::availableDatabasePathname($settings['databases'][$matchedIndex]['pathname']);

        if ($pathname === null) {
            throw new \RuntimeException(
                'The selected database is unavailable. Restore access to its folder and try again.',
            );
        }

        $settings['databases'][$matchedIndex]['pathname'] = $pathname;
        $settings['activeDatabaseId'] = $databaseId;
        self::writeSettings($settings, $settingsFile);
        self::archiveLegacySettings($settingsSource, $settingsFile);

        return $pathname;
    }

    public static function validateDirectory(string $directory): string
    {
        $directory = self::localPath($directory);
        $resolved = realpath($directory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException('Select an existing storage folder.');
        }

        if (!is_readable($resolved) || !is_writable($resolved)) {
            throw new \RuntimeException('The selected storage folder must be readable and writable.');
        }

        $probe = $resolved . DIRECTORY_SEPARATOR . '.relaydeck-write-' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'x+b');

        if ($handle === false) {
            throw new \RuntimeException('RelayDeck cannot write to the selected storage folder.');
        }

        fclose($handle);

        if (!@unlink($probe) && is_file($probe)) {
            throw new \RuntimeException('RelayDeck cannot clean up files in the selected storage folder.');
        }

        return $resolved;
    }

    public static function validateDatabaseFile(string $pathname): string
    {
        $pathname = self::localPath($pathname);
        $resolved = realpath($pathname);

        if (
            $resolved === false
            || !is_file($resolved)
            || !is_readable($resolved)
            || !is_writable($resolved)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'sqlite'
            || pathinfo($resolved, PATHINFO_FILENAME) === ''
        ) {
            throw new \RuntimeException('Select a readable and writable RelayDeck .sqlite file.');
        }

        self::validateDirectory(dirname($resolved));

        return $resolved;
    }

    public static function normalizeDatabaseFilename(string $filename): string
    {
        $filename = trim($filename);

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/u', $filename) === 1
            || str_ends_with($filename, '.')
            || str_ends_with($filename, ' ')
        ) {
            throw new \RuntimeException('Enter a valid database filename.');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '') {
            $filename .= '.sqlite';
        } elseif (strcasecmp($extension, 'sqlite') !== 0) {
            throw new \RuntimeException('Database filename must end with .sqlite.');
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        if ($stem === '' || trim($stem, '. ') === '') {
            throw new \RuntimeException('Enter a valid database filename.');
        }

        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $filename) === 1) {
            throw new \RuntimeException('This database filename is reserved by the operating system.');
        }

        if (mb_strlen($filename) > 120) {
            throw new \RuntimeException('Database filename must not exceed 120 characters.');
        }

        if (strlen($filename) > 240) {
            throw new \RuntimeException('Database filename is too long for this filesystem.');
        }

        return $filename;
    }

    public static function remember(string $pathname, string $projectDirectory): string
    {
        $pathname = self::validateDatabaseFile($pathname);
        $settingsFile = self::settingsFile($projectDirectory);
        $settingsSource = self::settingsSourceFile($projectDirectory);
        $settings = self::readSettings($settingsSource);

        if ($settings === []) {
            $settings = [
                'version' => self::SETTINGS_VERSION,
                'activeDatabaseId' => '',
                'databases' => [],
            ];
        }

        $pathKey = self::pathKey($pathname);
        $matchedIndex = null;

        foreach ($settings['databases'] as $index => $database) {
            if (self::pathKey($database['pathname']) === $pathKey) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            if (count($settings['databases']) >= self::MAX_DATABASES) {
                throw new \RuntimeException('RelayDeck can remember at most 64 databases.');
            }

            $databaseId = self::databaseId($pathname);
            $settings['databases'][] = [
                'id' => $databaseId,
                'pathname' => $pathname,
            ];
        } else {
            $databaseId = $settings['databases'][$matchedIndex]['id'];
            $settings['databases'][$matchedIndex]['pathname'] = $pathname;
        }

        $settings['activeDatabaseId'] = $databaseId;
        if (self::pathKey($settingsSource) === self::pathKey($settingsFile)) {
            self::backupInvalidSettings($settingsSource);
        }

        self::writeSettings($settings, $settingsFile);
        self::archiveLegacySettings($settingsSource, $settingsFile);

        return $pathname;
    }

    public static function suggestedDirectory(string $projectDirectory): ?string
    {
        $configured = self::configuredPathname($projectDirectory);

        if ($configured !== null && ($existing = self::nearestExistingDirectory(dirname($configured))) !== null) {
            return $existing;
        }

        $legacyDirectory = self::legacyDirectory($projectDirectory);

        if (is_file(self::databasePathname($legacyDirectory, self::DEFAULT_DATABASE_FILE))) {
            return self::nearestExistingDirectory($legacyDirectory);
        }

        $home = self::homeDirectory();
        $candidates = [];

        if ($home !== null) {
            $candidates[] = $home;
        }

        $candidates[] = $projectDirectory;
        $candidates[] = getcwd() ?: '';

        foreach ($candidates as $candidate) {
            if ($candidate === '' || !is_dir($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved !== false) {
                return $resolved;
            }
        }

        return null;
    }

    public static function settingsFile(string $projectDirectory): string
    {
        $directory = self::environmentValue('RELAYDECK_CONFIG_DIR')
            ?? self::defaultDirectory($projectDirectory);

        return self::normalizePath($directory) . DIRECTORY_SEPARATOR . self::SETTINGS_FILE;
    }

    public static function hasSettings(string $projectDirectory): bool
    {
        return self::pathEntryExists(self::settingsSourceFile($projectDirectory));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{id: string, pathname: string}|null
     */
    private static function activeDatabase(array $settings): ?array
    {
        $activeId = $settings['activeDatabaseId'] ?? null;

        foreach ($settings['databases'] ?? [] as $database) {
            if ($database['id'] === $activeId) {
                return $database;
            }
        }

        return null;
    }

    private static function availableDatabasePathname(string $pathname): ?string
    {
        $resolved = realpath(self::localPath($pathname));
        $directory = $resolved === false ? null : dirname($resolved);

        if (
            $resolved === false
            || !is_file($resolved)
            || !is_readable($resolved)
            || !is_writable($resolved)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'sqlite'
            || $directory === null
            || !is_dir($directory)
            || !is_readable($directory)
            || !is_writable($directory)
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array{
     *     version: int,
     *     activeDatabaseId: string,
     *     databases: list<array{id: string, pathname: string}>
     * }|array{}
     */
    private static function readSettings(string $settingsFile): array
    {
        if (!is_file($settingsFile)) {
            return [];
        }

        $contents = @file_get_contents($settingsFile);

        if (!is_string($contents)) {
            return [];
        }

        try {
            $settings = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($settings)) {
            return [];
        }

        if (
            ($settings['version'] ?? null) === 1
            && is_string($settings['storageDirectory'] ?? null)
            && trim($settings['storageDirectory']) !== ''
            && !str_contains($settings['storageDirectory'], "\0")
            && self::isAbsolutePath(self::localPath($settings['storageDirectory']))
        ) {
            $pathname = self::databasePathname(
                self::normalizePath($settings['storageDirectory']),
                self::DEFAULT_DATABASE_FILE,
            );
            $id = self::databaseId($pathname);

            return [
                'version' => self::SETTINGS_VERSION,
                'activeDatabaseId' => $id,
                'databases' => [[
                    'id' => $id,
                    'pathname' => $pathname,
                ]],
            ];
        }

        $version = $settings['version'] ?? null;

        if (
            !in_array($version, [2, self::SETTINGS_VERSION], true)
            || !is_string($settings['activeDatabaseId'] ?? null)
            || !is_array($settings['databases'] ?? null)
            || $settings['databases'] === []
            || count($settings['databases']) > self::MAX_DATABASES
        ) {
            return [];
        }

        $databases = [];
        $ids = [];
        $paths = [];

        foreach ($settings['databases'] as $database) {
            if (
                !is_array($database)
                || !is_string($database['id'] ?? null)
                || trim($database['id']) === ''
                || strlen($database['id']) > 128
            ) {
                return [];
            }

            if ($version === 2) {
                if (
                    !is_string($database['name'] ?? null)
                    || trim($database['name']) === ''
                    || mb_strlen(trim($database['name'])) > 120
                    || !is_string($database['directory'] ?? null)
                    || trim($database['directory']) === ''
                    || str_contains($database['directory'], "\0")
                    || !self::isAbsolutePath(self::localPath($database['directory']))
                ) {
                    return [];
                }

                $pathname = self::databasePathname(
                    self::normalizePath($database['directory']),
                    self::DEFAULT_DATABASE_FILE,
                );
            } else {
                if (
                    !is_string($database['pathname'] ?? null)
                    || trim($database['pathname']) === ''
                    || str_contains($database['pathname'], "\0")
                    || !self::isAbsolutePath(self::localPath($database['pathname']))
                ) {
                    return [];
                }

                $pathname = self::normalizePath($database['pathname']);
            }

            $id = trim($database['id']);
            $pathKey = self::pathKey($pathname);

            if (isset($ids[$id]) || isset($paths[$pathKey])) {
                return [];
            }

            $ids[$id] = true;
            $paths[$pathKey] = true;
            $databases[] = [
                'id' => $id,
                'pathname' => $pathname,
            ];
        }

        $activeId = $settings['activeDatabaseId'];

        if (!isset($ids[$activeId])) {
            return [];
        }

        return [
            'version' => self::SETTINGS_VERSION,
            'activeDatabaseId' => $activeId,
            'databases' => $databases,
        ];
    }

    /** @param array{version: int, activeDatabaseId: string, databases: array<int, array{id: string, pathname: string}>} $settings */
    private static function writeSettings(array $settings, string $settingsFile): void
    {
        $settingsDirectory = dirname($settingsFile);

        if (!is_dir($settingsDirectory) && !mkdir($settingsDirectory, 0700, true) && !is_dir($settingsDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create RelayDeck settings directory "%s".', $settingsDirectory));
        }

        if (!is_writable($settingsDirectory)) {
            throw new \RuntimeException(sprintf('RelayDeck settings directory "%s" is not writable.', $settingsDirectory));
        }

        $json = json_encode([
            'version' => self::SETTINGS_VERSION,
            'activeDatabaseId' => $settings['activeDatabaseId'],
            'databases' => array_values($settings['databases']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = $settingsFile . '.tmp-' . bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException('Unable to write RelayDeck storage settings.');
            }

            @chmod($temporary, 0600);

            if (!@rename($temporary, $settingsFile)) {
                $previous = null;

                if (is_file($settingsFile)) {
                    $previous = $settingsFile . '.previous-' . bin2hex(random_bytes(6));

                    if (!@rename($settingsFile, $previous)) {
                        throw new \RuntimeException('Unable to preserve the previous RelayDeck storage settings.');
                    }
                }

                if (!@rename($temporary, $settingsFile)) {
                    if ($previous !== null) {
                        @rename($previous, $settingsFile);
                    }

                    throw new \RuntimeException('Unable to replace RelayDeck storage settings.');
                }

                if ($previous !== null) {
                    @unlink($previous);
                }
            }

            @chmod($settingsFile, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function backupInvalidSettings(string $settingsFile): void
    {
        if (!is_file($settingsFile) || self::readSettings($settingsFile) !== []) {
            return;
        }

        $backup = $settingsFile . '.invalid-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        if (!@copy($settingsFile, $backup)) {
            throw new \RuntimeException('Unable to preserve invalid RelayDeck storage settings.');
        }

        @chmod($backup, 0600);
    }

    private static function archiveLegacySettings(string $source, string $destination): void
    {
        if (self::pathKey($source) === self::pathKey($destination) || !is_file($source)) {
            return;
        }

        $archive = $source . '.migrated';

        if (file_exists($archive) || is_link($archive)) {
            $archive .= '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        }

        if (!@rename($source, $archive)) {
            error_log(sprintf(
                'RelayDeck migrated settings to "%s" but could not archive "%s".',
                $destination,
                $source,
            ));

            return;
        }

        @chmod($archive, 0600);
    }

    private static function databaseId(string $pathname): string
    {
        return 'db_' . substr(hash('sha256', self::pathKey($pathname)), 0, 24);
    }

    private static function pathKey(string $pathname): string
    {
        $normalized = self::normalizePath($pathname);
        $resolved = realpath($normalized);

        if ($resolved !== false) {
            $normalized = $resolved;
        } else {
            $directory = realpath(dirname($normalized));

            if ($directory !== false) {
                $normalized = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . basename($normalized);
            }
        }

        $normalized = str_replace('\\', '/', $normalized);

        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($normalized, 'UTF-8')
            : $normalized;
    }

    private static function databasePathname(string $directory, string $filename): string
    {
        return rtrim(self::normalizePath($directory), '/\\') . DIRECTORY_SEPARATOR . $filename;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}[^\\\\\/])/', $path) === 1;
        }

        return str_starts_with($path, '/');
    }

    private static function settingsSourceFile(string $projectDirectory): string
    {
        $settingsFile = self::settingsFile($projectDirectory);

        if (self::pathEntryExists($settingsFile)) {
            return $settingsFile;
        }

        if (self::environmentValue('RELAYDECK_CONFIG_DIR') !== null) {
            return $settingsFile;
        }

        $legacySettingsFile = self::legacySettingsDirectory($projectDirectory)
            . DIRECTORY_SEPARATOR . self::SETTINGS_FILE;

        return self::pathEntryExists($legacySettingsFile) ? $legacySettingsFile : $settingsFile;
    }

    private static function pathEntryExists(string $pathname): bool
    {
        return file_exists($pathname) || is_link($pathname);
    }

    private static function legacySettingsDirectory(string $projectDirectory): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $base = self::environmentValue('LOCALAPPDATA')
                ?? self::environmentValue('APPDATA');

            if ($base !== null) {
                return self::normalizePath($base) . DIRECTORY_SEPARATOR . 'RelayDeck';
            }
        }

        $home = self::homeDirectory();

        if (PHP_OS_FAMILY === 'Darwin' && $home !== null) {
            return $home . DIRECTORY_SEPARATOR . 'Library'
                . DIRECTORY_SEPARATOR . 'Application Support'
                . DIRECTORY_SEPARATOR . 'RelayDeck';
        }

        $xdg = self::environmentValue('XDG_CONFIG_HOME');

        if ($xdg !== null) {
            return self::normalizePath($xdg) . DIRECTORY_SEPARATOR . 'relay-deck';
        }

        if ($home !== null) {
            return $home . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'relay-deck';
        }

        return self::normalizePath($projectDirectory) . DIRECTORY_SEPARATOR . '.relaydeck';
    }

    private static function legacyDirectory(string $projectDirectory): string
    {
        $pharPath = \Phar::running(false);

        if ($pharPath !== '') {
            return dirname($pharPath) . DIRECTORY_SEPARATOR . 'storage';
        }

        return self::normalizePath($projectDirectory) . DIRECTORY_SEPARATOR . 'storage';
    }

    private static function nearestExistingDirectory(string $directory): ?string
    {
        $candidate = self::localPath($directory);

        while ($candidate !== '' && !is_dir($candidate)) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return null;
            }

            $candidate = $parent;
        }

        $resolved = $candidate === '' ? false : realpath($candidate);

        return $resolved === false ? null : $resolved;
    }

    private static function homeDirectory(): ?string
    {
        return self::environmentValue('HOME')
            ?? self::environmentValue('USERPROFILE');
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function localPath(string $path): string
    {
        if (str_starts_with(strtolower($path), 'file://')) {
            $parts = parse_url($path);

            if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'file') {
                $host = (string) ($parts['host'] ?? '');
                $path = rawurldecode((string) ($parts['path'] ?? ''));

                if ($host !== '' && strtolower($host) !== 'localhost') {
                    $path = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . $host . $path;
                } elseif (PHP_OS_FAMILY === 'Windows' && preg_match('/^\/[A-Za-z]:[\\\\\/]/', $path) === 1) {
                    $path = substr($path, 1);
                }
            }
        }

        return $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = self::localPath(trim($path));

        if ($path === DIRECTORY_SEPARATOR) {
            return $path;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]?$/', $path) === 1) {
            return strtoupper($path[0]) . ':' . DIRECTORY_SEPARATOR;
        }

        return rtrim($path, '/\\');
    }
}
