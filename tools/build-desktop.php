<?php

declare(strict_types=1);

/** Build the current sources for the host Linux architecture. */
function buildDesktopApplication(string $projectDirectory, string $architecture): string
{
    $compiler = $projectDirectory . '/vendor/bin/boson';

    if (!is_file($compiler)) {
        throw new RuntimeException('Boson Compiler is missing. Run composer install with development dependencies first.');
    }

    $contents = file_get_contents($projectDirectory . '/boson.json');

    if ($contents === false) {
        throw new RuntimeException('Cannot read boson.json.');
    }

    $configuration = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    $configuration['name'] = 'relay-deck';
    $configuration['root'] = $projectDirectory;
    $configuration['output'] = $projectDirectory . '/build/.desktop-install';
    $configuration['target'] = [['type' => 'linux', 'arch' => $architecture, 'output' => 'app']];
    $configurationPathname = tempnam(sys_get_temp_dir(), 'relay-deck-build-');

    if ($configurationPathname === false) {
        throw new RuntimeException('Cannot create the desktop build configuration.');
    }

    try {
        if (file_put_contents($configurationPathname, json_encode($configuration, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write the desktop build configuration.');
        }

        fwrite(STDOUT, "Building RelayDeck for Linux {$architecture}...\n");
        $process = proc_open(
            [PHP_BINARY, $compiler, 'compile', '--config=' . $configurationPathname, '--no-interaction', '--no-ansi'],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $projectDirectory,
        );

        if ($process === false || proc_close($process) !== 0) {
            throw new RuntimeException('The desktop build failed; the installed application was not changed.');
        }
    } finally {
        unlink($configurationPathname);
    }

    return $configuration['output'] . '/app/relay-deck';
}
