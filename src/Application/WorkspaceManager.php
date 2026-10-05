<?php

declare(strict_types=1);

namespace ApiClient\Application;

/**
 * Atomically prepares and swaps the active database-bound service session.
 */
final class WorkspaceManager
{
    private ?WorkspaceSession $active = null;

    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function hasActive(): bool
    {
        return $this->active !== null;
    }

    public function active(): WorkspaceSession
    {
        return $this->active
            ?? throw new \RuntimeException('No RelayDeck database is active.');
    }

    public function openInitial(string $pathname): void
    {
        $environmentOverride = StoragePathResolver::usesEnvironmentOverride();

        if (!$environmentOverride) {
            $pathname = StoragePathResolver::validateDatabaseFile($pathname);
        }

        $candidate = new WorkspaceSession($pathname, $environmentOverride);
        $this->sessionData($candidate);

        $settingsFile = StoragePathResolver::settingsFile($this->projectDirectory);

        if (
            !$environmentOverride
            && !file_exists($settingsFile)
            && !is_link($settingsFile)
        ) {
            StoragePathResolver::remember($pathname, $this->projectDirectory);
        }

        $this->active = $candidate;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return StoragePathResolver::databaseState($this->projectDirectory);
    }

    /** @return array<string, mixed> */
    public function bootstrapState(): array
    {
        return [
            ...$this->sessionData($this->active()),
            ...$this->state(),
        ];
    }

    /** @return array<string, mixed> */
    public function switchDatabase(string $databaseId): array
    {
        $pathname = StoragePathResolver::databasePathnameForId($databaseId, $this->projectDirectory);

        if ($pathname === null) {
            throw new \RuntimeException('The selected RelayDeck database is not registered.');
        }

        try {
            $pathname = StoragePathResolver::validateDatabaseFile($pathname);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                'The selected database is unavailable. Restore access to its .sqlite file and try again.',
                previous: $exception,
            );
        }

        $candidate = new WorkspaceSession($pathname);
        $data = $this->sessionData($candidate);
        StoragePathResolver::activateDatabase($databaseId, $this->projectDirectory);
        $this->active = $candidate;

        return [...$data, ...$this->state()];
    }

    /** @return array<string, mixed> */
    public function openExisting(string $pathname): array
    {
        $this->assertSwitchingAllowed();
        $pathname = StoragePathResolver::validateDatabaseFile($pathname);
        $candidate = new WorkspaceSession($pathname);
        $data = $this->sessionData($candidate);
        StoragePathResolver::remember($pathname, $this->projectDirectory);
        $this->active = $candidate;

        return [...$data, ...$this->state()];
    }

    /** @return array<string, mixed> */
    public function createDatabase(string $directory, string $filename): array
    {
        $this->assertSwitchingAllowed();
        $filename = StoragePathResolver::normalizeDatabaseFilename($filename);
        $directory = StoragePathResolver::validateDirectory($directory);
        $pathname = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($pathname) || is_link($pathname)) {
            throw new \RuntimeException(
                'A file with this name already exists. Choose another database filename.',
            );
        }

        $this->assertMissingRegisteredDatabaseIsNotRecreated($pathname);
        return $this->createAtPathname($pathname);
    }

    /** @return array<string, mixed> */
    public function openDefault(string $directory): array
    {
        $this->assertSwitchingAllowed();

        if (file_exists($directory) && !is_dir($directory)) {
            throw new \RuntimeException('The default storage path is not a directory.');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                'RelayDeck cannot create the storage folder beside the application.',
            );
        }

        return $this->openOrCreate($directory);
    }

    /** @return array<string, mixed> */
    public function openOrCreate(string $directory): array
    {
        $directory = StoragePathResolver::validateDirectory($directory);
        $pathname = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'app.sqlite';

        if (is_link($pathname) && !file_exists($pathname)) {
            throw new \RuntimeException('The default database path is a broken symbolic link.');
        }

        if (!is_file($pathname)) {
            $this->assertMissingRegisteredDatabaseIsNotRecreated($pathname);

            return $this->createAtPathname($pathname);
        }

        $pathname = StoragePathResolver::validateDatabaseFile($pathname);
        $candidate = new WorkspaceSession($pathname);
        $data = $this->sessionData($candidate);
        StoragePathResolver::remember($pathname, $this->projectDirectory);
        $this->active = $candidate;

        return [...$data, ...$this->state()];
    }

    /** @return array<string, mixed> */
    public function retryConfigured(): array
    {
        $pathname = StoragePathResolver::resolve($this->projectDirectory);

        if ($pathname === null) {
            if (
                !StoragePathResolver::usesEnvironmentOverride()
                && !StoragePathResolver::hasSettings($this->projectDirectory)
            ) {
                return $this->openDefault(
                    StoragePathResolver::defaultDirectory($this->projectDirectory),
                );
            }

            throw new \RuntimeException(
                'The saved database is still unavailable. Restore access to its folder and try again.',
            );
        }

        $candidate = new WorkspaceSession(
            $pathname,
            StoragePathResolver::usesEnvironmentOverride(),
        );
        $data = $this->sessionData($candidate);

        if (!StoragePathResolver::usesEnvironmentOverride()) {
            StoragePathResolver::remember($pathname, $this->projectDirectory);
        }

        $this->active = $candidate;

        return [...$data, ...$this->state()];
    }

    /**
     * Read all workspace data before committing a database switch. A malformed
     * candidate therefore cannot replace the current usable session.
     *
     * @return array<string, mixed>
     */
    private function sessionData(WorkspaceSession $session): array
    {
        return [
            'collections' => $session->collections->all(),
            'savedRequests' => $session->requests->savedRequests(),
            'history' => $session->requests->history(),
            'environments' => $session->environments->all(),
            'activeEnvironmentId' => $session->environments->activeId(),
        ];
    }

    private function assertSwitchingAllowed(): void
    {
        if (StoragePathResolver::usesEnvironmentOverride()) {
            throw new \RuntimeException(
                'Database management is disabled because RELAYDECK_STORAGE_DIR is active.',
            );
        }
    }

    private function assertMissingRegisteredDatabaseIsNotRecreated(string $pathname): void
    {
        if (StoragePathResolver::isRegisteredDatabasePathname($pathname, $this->projectDirectory)) {
            throw new \RuntimeException(
                'This registered database is missing. Restore its .sqlite file and try again.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function createAtPathname(string $pathname): array
    {
        foreach (['-journal', '-wal', '-shm'] as $suffix) {
            if (file_exists($pathname . $suffix) || is_link($pathname . $suffix)) {
                throw new \RuntimeException(
                    'SQLite sidecar files already exist for this name. Choose another database filename.',
                );
            }
        }

        $reservation = @fopen($pathname, 'x+b');

        if ($reservation === false) {
            throw new \RuntimeException(
                'A file with this name already exists or cannot be created. Choose another database filename.',
            );
        }

        $identity = @fstat($reservation);
        @chmod($pathname, 0600);

        if (!is_array($identity)) {
            fclose($reservation);
            @unlink($pathname);

            throw new \RuntimeException('RelayDeck could not verify the newly created database file.');
        }

        fclose($reservation);

        try {
            $candidate = new WorkspaceSession($pathname, true);
            $data = $this->sessionData($candidate);
            StoragePathResolver::remember($pathname, $this->projectDirectory);
        } catch (\Throwable $exception) {
            unset($candidate);
            $this->removeReservedDatabase($pathname, $identity);

            throw $exception;
        }

        $this->active = $candidate;

        return [...$data, ...$this->state()];
    }

    /** @param array<string|int, mixed> $identity */
    private function removeReservedDatabase(string $pathname, array $identity): void
    {
        $current = @lstat($pathname);

        if (
            !is_array($current)
            || (int) ($current['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2)
            || (int) ($current['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2)
        ) {
            return;
        }

        @unlink($pathname);
    }
}
