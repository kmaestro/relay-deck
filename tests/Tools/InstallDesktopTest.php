<?php

declare(strict_types=1);

namespace ApiClient\Tests\Tools;

use PHPUnit\Framework\TestCase;

final class InstallDesktopTest extends TestCase
{
    private string $temporaryDirectory;
    private string $projectDirectory;
    private string $dataDirectory;
    private string $libraryName;
    private string $architecture;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Desktop installation requires Linux.');
        }

        [$this->architecture, $this->libraryName] = match (php_uname('m')) {
            'x86_64', 'amd64' => ['amd64', 'libboson-linux-x86_64.so'],
            'aarch64', 'arm64' => ['aarch64', 'libboson-linux-aarch64.so'],
            default => self::markTestSkipped('Unsupported desktop installation architecture.'),
        };

        $this->temporaryDirectory = sys_get_temp_dir() . '/relaydeck-installer-' . bin2hex(random_bytes(8));
        $this->projectDirectory = $this->temporaryDirectory . '/project checkout';
        $this->dataDirectory = $this->temporaryDirectory . '/desktop data';

        foreach (['tools', 'resources/icons', 'vendor/bin'] as $directory) {
            self::assertTrue(mkdir($this->projectDirectory . '/' . $directory, 0755, true));
        }

        $repositoryDirectory = dirname(__DIR__, 2);

        foreach (['tools/install-desktop.php', 'tools/build-desktop.php', 'resources/icons/app.svg', 'boson.json'] as $file) {
            self::assertTrue(copy($repositoryDirectory . '/' . $file, $this->projectDirectory . '/' . $file));
        }

        file_put_contents($this->projectDirectory . '/source-marker.txt', 'first');
        file_put_contents($this->projectDirectory . '/vendor/bin/boson', $this->compilerStub());
        chmod($this->projectDirectory . '/vendor/bin/boson', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeDirectory($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testDefaultInstallationRunsAfterTheProjectIsRemoved(): void
    {
        $result = $this->install();

        self::assertSame(0, $result['status'], $result['output']);
        $this->assertInstalledLauncher();
        self::assertFileExists($this->installationDirectory() . '/' . $this->libraryName);
        self::assertSame(
            file_get_contents($this->projectDirectory . '/resources/icons/app.svg'),
            file_get_contents($this->dataDirectory . '/icons/hicolor/scalable/apps/relay-deck.svg'),
        );

        $configuration = json_decode(
            (string) file_get_contents($this->projectDirectory . '/compiler-config.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('relay-deck', $configuration['name']);
        self::assertSame([
            ['type' => 'linux', 'arch' => $this->architecture, 'output' => 'app'],
        ], $configuration['target']);

        $this->removeDirectory($this->projectDirectory);

        $run = $this->runProcess([$this->installationDirectory() . '/relay-deck']);
        self::assertSame(0, $run['status'], $run['output']);
        self::assertSame("first\n", $run['output']);
    }

    public function testReinstallationBuildsCurrentSourcesAndPreservesInstalledStorage(): void
    {
        $first = $this->install();
        self::assertSame(0, $first['status'], $first['output']);

        $storageDirectory = $this->installationDirectory() . '/storage';

        if (!is_dir($storageDirectory)) {
            self::assertTrue(mkdir($storageDirectory, 0700));
        }

        $storageFiles = [
            'settings.json' => '{"version":3,"activeDatabaseId":"existing","databases":[]}',
            'app.sqlite' => "existing database\0marker",
            'app.sqlite-wal' => 'existing write-ahead log',
        ];

        foreach ($storageFiles as $filename => $contents) {
            file_put_contents($storageDirectory . '/' . $filename, $contents);
        }

        file_put_contents($this->projectDirectory . '/source-marker.txt', 'second');
        $second = $this->install();
        self::assertSame(0, $second['status'], $second['output']);

        $run = $this->runProcess([$this->installationDirectory() . '/relay-deck']);
        self::assertSame(0, $run['status'], $run['output']);
        self::assertSame("second\n", $run['output']);
        self::assertSame("first\nsecond\n", file_get_contents($this->projectDirectory . '/compiler-invocations.txt'));

        foreach ($storageFiles as $filename => $contents) {
            self::assertSame($contents, file_get_contents($storageDirectory . '/' . $filename));
        }

        $this->assertInstalledLauncher();
    }

    public function testAnExplicitExecutableAndItsLibraryAreCopiedIntoTheInstallation(): void
    {
        $buildDirectory = $this->projectDirectory . '/provided build';
        self::assertTrue(mkdir($buildDirectory));
        $this->writeExecutable($buildDirectory . '/custom-relay-deck', 'provided');
        file_put_contents($buildDirectory . '/' . $this->libraryName, 'provided library');

        $result = $this->install($buildDirectory . '/custom-relay-deck');

        self::assertSame(0, $result['status'], $result['output']);
        $this->assertInstalledLauncher();
        self::assertSame('provided library', file_get_contents($this->installationDirectory() . '/' . $this->libraryName));
        self::assertFileDoesNotExist($this->projectDirectory . '/compiler-invocations.txt');
        $this->removeDirectory($buildDirectory);

        $run = $this->runProcess([$this->installationDirectory() . '/relay-deck']);
        self::assertSame(0, $run['status'], $run['output']);
        self::assertSame("provided\n", $run['output']);
    }

    public function testAMissingLibraryLeavesThePreviousInstallationIntact(): void
    {
        $first = $this->install();
        self::assertSame(0, $first['status'], $first['output']);
        $originalLauncher = file_get_contents($this->launcherPathname());
        $originalExecutable = file_get_contents($this->installationDirectory() . '/relay-deck');
        $originalLibrary = file_get_contents($this->installationDirectory() . '/' . $this->libraryName);

        $buildDirectory = $this->projectDirectory . '/incomplete build';
        self::assertTrue(mkdir($buildDirectory));
        $this->writeExecutable($buildDirectory . '/relay-deck', 'incomplete');

        $result = $this->install($buildDirectory . '/relay-deck');

        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString($this->libraryName, $result['output']);
        self::assertSame($originalLauncher, file_get_contents($this->launcherPathname()));
        self::assertSame($originalExecutable, file_get_contents($this->installationDirectory() . '/relay-deck'));
        self::assertSame($originalLibrary, file_get_contents($this->installationDirectory() . '/' . $this->libraryName));
    }

    /** @return array{status: int, output: string} */
    private function install(?string $executable = null): array
    {
        $command = [PHP_BINARY, $this->projectDirectory . '/tools/install-desktop.php'];

        if ($executable !== null) {
            $command[] = $executable;
        }

        return $this->runProcess($command);
    }

    /** @param list<string> $command
     *  @return array{status: int, output: string}
     */
    private function runProcess(array $command): array
    {
        $environment = getenv();
        $environment['XDG_DATA_HOME'] = $this->dataDirectory;
        $environment['HOME'] = $this->temporaryDirectory . '/home';
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->temporaryDirectory,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['status' => proc_close($process), 'output' => (string) $output];
    }

    private function assertInstalledLauncher(): void
    {
        $launcher = (string) file_get_contents($this->launcherPathname());

        self::assertStringContainsString('Exec="' . $this->installationDirectory() . '/relay-deck"' . "\n", $launcher);
        self::assertStringContainsString('Path=' . $this->installationDirectory() . "\n", $launcher);
        self::assertStringNotContainsString($this->projectDirectory, $launcher);
        self::assertStringNotContainsString(PHP_BINARY, $launcher);
        self::assertStringNotContainsString('index.php', $launcher);
        self::assertTrue(is_executable($this->installationDirectory() . '/relay-deck'));
        self::assertFalse(is_link($this->installationDirectory() . '/relay-deck'));
    }

    private function installationDirectory(): string
    {
        return $this->dataDirectory . '/relay-deck';
    }

    private function launcherPathname(): string
    {
        return $this->dataDirectory . '/applications/app.saucer.relaydeck.desktop';
    }

    private function writeExecutable(string $pathname, string $marker): void
    {
        file_put_contents(
            $pathname,
            "#!/bin/sh\n"
            . 'test -f "$(dirname "$0")/' . $this->libraryName . '" || exit 5' . "\n"
            . "printf '%s\\n' '" . $marker . "'\n",
        );
        chmod($pathname, 0755);
    }

    private function compilerStub(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

$configurationPath = null;

foreach ($argv as $index => $argument) {
    if ($argument === '--config' || $argument === '-c') {
        $configurationPath = $argv[$index + 1] ?? null;
    } elseif (str_starts_with($argument, '--config=')) {
        $configurationPath = substr($argument, strlen('--config='));
    }
}

if ($configurationPath === null) {
    fwrite(STDERR, "A compile configuration is required.\n");
    exit(2);
}

$configuration = json_decode(file_get_contents($configurationPath), true, flags: JSON_THROW_ON_ERROR);
$root = $configuration['root'] ?? getcwd();
$output = $configuration['output'];

if (!str_starts_with($output, '/')) {
    $output = $root . '/' . $output;
}

$output .= '/' . ($configuration['target'][0]['output'] ?? 'app');
if (!is_dir($output)) {
    mkdir($output, 0755, true);
}
$marker = file_get_contents($root . '/source-marker.txt');
$libraryName = $configuration['target'][0]['arch'] === 'amd64'
    ? 'libboson-linux-x86_64.so'
    : 'libboson-linux-aarch64.so';
$executable = $output . '/' . $configuration['name'];
file_put_contents(
    $executable,
    "#!/bin/sh\n"
    . 'test -f "$(dirname "$0")/' . $libraryName . '" || exit 5' . "\n"
    . "printf '%s\\n' '" . $marker . "'\n",
);
chmod($executable, 0755);
file_put_contents($output . '/' . $libraryName, 'library for ' . $marker);
file_put_contents($root . '/compiler-config.json', json_encode($configuration, JSON_THROW_ON_ERROR));
file_put_contents($root . '/compiler-invocations.txt', $marker . "\n", FILE_APPEND);
PHP;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
