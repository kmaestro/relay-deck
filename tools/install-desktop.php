<?php

declare(strict_types=1);

// Install the native application and a launcher matching Boson's application ID.
$stagedFiles = [];
$exitCode = 0;

try {
    if (PHP_OS_FAMILY !== 'Linux') {
        throw new RuntimeException('The desktop launcher installer requires Linux.');
    }

    if ($argc > 2) {
        throw new InvalidArgumentException('Usage: php tools/install-desktop.php [path/to/relay-deck]');
    }

    $projectDirectory = dirname(__DIR__);
    $dataDirectory = getenv('XDG_DATA_HOME');

    if ($dataDirectory === false || $dataDirectory === '') {
        $userDirectory = getenv('HOME');

        if ($userDirectory === false || $userDirectory === '') {
            throw new RuntimeException('Set XDG_DATA_HOME or HOME before installing the launcher.');
        }

        $dataDirectory = $userDirectory . '/.local/share';
    }

    if (!str_starts_with($dataDirectory, '/')) {
        throw new InvalidArgumentException('The desktop data directory must be an absolute path.');
    }

    $dataDirectory = rtrim($dataDirectory, '/');
    $installationDirectory = $dataDirectory . '/relay-deck';
    $executable = $installationDirectory . '/relay-deck';

    foreach ([$projectDirectory, $installationDirectory] as $pathname) {
        if (preg_match('/[\x00-\x1f\x7f]/', $pathname) === 1) {
            throw new InvalidArgumentException('Launcher paths cannot contain line breaks or control characters.');
        }
    }

    if (str_contains($executable, '=')) {
        throw new InvalidArgumentException('Desktop launcher executable paths cannot contain an equals sign.');
    }

    [$architecture, $libraryName] = match (strtolower(php_uname('m'))) {
        'x86_64', 'amd64' => ['amd64', 'libboson-linux-x86_64.so'],
        'aarch64', 'arm64' => ['aarch64', 'libboson-linux-aarch64.so'],
        default => throw new RuntimeException('Unsupported Linux architecture: ' . php_uname('m')),
    };

    if (isset($argv[1])) {
        $sourceExecutable = realpath($argv[1]);
    } else {
        require __DIR__ . '/build-desktop.php';
        $sourceExecutable = buildDesktopApplication($projectDirectory, $architecture);
    }

    if ($sourceExecutable === false || !is_file($sourceExecutable) || !is_executable($sourceExecutable)) {
        throw new InvalidArgumentException('The application executable must exist and be executable.');
    }

    $sourceLibrary = dirname($sourceExecutable) . '/' . $libraryName;

    if (!is_file($sourceLibrary) || !is_readable($sourceLibrary)) {
        throw new RuntimeException('The native Boson library is missing beside the executable: ' . $sourceLibrary);
    }

    $arguments = [$executable];

    // GIO checks the first Exec argument before expanding literal percent signs.
    if (str_contains($executable, '%')) {
        if (!is_executable('/usr/bin/env')) {
            throw new RuntimeException('This executable path requires /usr/bin/env.');
        }

        array_unshift($arguments, '/usr/bin/env', '--');
    }

    // Exec quoting is decoded after desktop entry string escaping.
    $escapeValue = static fn (string $value): string => str_replace('\\', '\\\\', $value);
    $quoteArgument = static fn (string $value): string => '"' . strtr($value, [
        '\\' => '\\\\', '"' => '\\"', '$' => '\\$', '`' => '\\`', '%' => '%%',
    ]) . '"';
    $command = $escapeValue(implode(' ', array_map($quoteArgument, $arguments)));
    $desktop = "[Desktop Entry]\nType=Application\nName=RelayDeck\n"
        . "Comment=Local HTTP API client\nExec={$command}\n"
        . 'Path=' . $escapeValue($installationDirectory) . "\nIcon=relay-deck\n"
        . "Terminal=false\nStartupWMClass=app.saucer.relaydeck\nCategories=Development;Network;\n";
    $launcherDirectory = $dataDirectory . '/applications';
    $iconDirectory = $dataDirectory . '/icons/hicolor/scalable/apps';

    foreach ([$installationDirectory, $launcherDirectory, $iconDirectory] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create desktop integration directory: ' . $directory);
        }
    }

    $iconPathname = $iconDirectory . '/relay-deck.svg';
    $launcherPathname = $launcherDirectory . '/app.saucer.relaydeck.desktop';

    // Stage every file before replacing an existing installation. Renaming also
    // allows updating a running executable and leaves its storage untouched.
    foreach ([
        [$sourceExecutable, $executable, 0755],
        [$sourceLibrary, $installationDirectory . '/' . $libraryName, 0644],
        [$projectDirectory . '/resources/icons/app.svg', $iconPathname, 0644],
    ] as [$source, $destination, $permissions]) {
        $temporaryPathname = tempnam(dirname($destination), '.relay-deck-');

        if ($temporaryPathname === false) {
            throw new RuntimeException('Cannot stage the application file: ' . $destination);
        }

        $stagedFiles[$destination] = $temporaryPathname;

        if (!copy($source, $temporaryPathname) || !chmod($temporaryPathname, $permissions)) {
            throw new RuntimeException('Cannot stage the application file: ' . $destination);
        }
    }

    $temporaryPathname = tempnam($launcherDirectory, '.relay-deck-');

    if ($temporaryPathname === false) {
        throw new RuntimeException('Cannot stage the desktop launcher.');
    }

    $stagedFiles[$launcherPathname] = $temporaryPathname;

    if (file_put_contents($temporaryPathname, $desktop) === false || !chmod($temporaryPathname, 0644)) {
        throw new RuntimeException('Cannot stage the desktop launcher.');
    }

    foreach ($stagedFiles as $destination => $temporaryPathname) {
        if (!rename($temporaryPathname, $destination)) {
            throw new RuntimeException('Cannot install the application file: ' . $destination);
        }
    }

    fwrite(STDOUT, "Installed application: {$executable}\nInstalled launcher: {$launcherPathname}\nInstalled icon: {$iconPathname}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    foreach ($stagedFiles as $temporaryPathname) {
        if (is_file($temporaryPathname)) {
            unlink($temporaryPathname);
        }
    }
}

exit($exitCode);
