<?php

declare(strict_types=1);

namespace ApiClient\Application;

/**
 * Builds the local WebView document without a web server or remote assets.
 */
final readonly class HtmlRenderer
{
    public function __construct(private string $resourcesDirectory)
    {
    }

    public function render(): string
    {
        $html = $this->read('index.html');
        $styles = $this->read('css/app.css');
        $script = $this->read('js/app.js');
        $icon = $this->read('icons/app.svg');

        return strtr($html, [
            '<!-- APP_STYLES -->' => '<style>' . $styles . '</style>',
            '<!-- APP_SCRIPT -->' => '<script>' . $script . '</script>',
            '<!-- APP_ICON -->' => $icon,
            '<!-- APP_FAVICON -->' => $this->renderFavicon(),
        ]);
    }

    public function renderStorageSetup(
        ?string $unavailablePathname = null,
        ?string $storageError = null,
        bool $reconnectExistingDatabase = false,
    ): string
    {
        $html = $this->read('storage-setup.html');
        $isUnavailable = $unavailablePathname !== null;

        return strtr($html, [
            '<!-- APP_STYLES -->' => '<style>' . $this->read('css/app.css') . '</style>',
            '<!-- APP_SCRIPT -->' => '<script>' . $this->read('js/storage-setup.js') . '</script>',
            '<!-- APP_ICON -->' => $this->read('icons/app.svg'),
            '<!-- APP_FAVICON -->' => $this->renderFavicon(),
            '<!-- STORAGE_SETUP_EYEBROW -->' => $isUnavailable ? 'Storage unavailable' : 'First launch',
            '<!-- STORAGE_SETUP_TITLE -->' => $isUnavailable
                ? 'Reconnect your workspace storage'
                : 'Choose where to store your workspace',
            '<!-- STORAGE_SETUP_DESCRIPTION -->' => $isUnavailable
                ? 'RelayDeck will not create a replacement database elsewhere without your choice.'
                : 'Requests, collections, history and environments are kept together in one local SQLite database.',
            '<!-- STORAGE_UNAVAILABLE -->' => $isUnavailable
                ? $this->renderUnavailableStorage($unavailablePathname, $storageError)
                : '',
            '<!-- STORAGE_LOCATION_TITLE -->' => $reconnectExistingDatabase
                ? 'Database file'
                : 'Storage folder',
            '<!-- STORAGE_LOCATION_DESCRIPTION -->' => $reconnectExistingDatabase
                ? 'Select an existing RelayDeck file with the .sqlite extension.'
                : 'Choose any existing writable folder on this computer.',
            '<!-- STORAGE_CHOOSE_LABEL -->' => $isUnavailable
                ? ($reconnectExistingDatabase ? 'Choose another database file' : 'Choose another storage folder')
                : 'Choose storage folder',
            '<!-- STORAGE_SETUP_STATUS -->' => $isUnavailable
                ? 'This database stays registered until another location opens successfully.'
                : 'No data will be created until you choose a folder.',
            '<!-- STORAGE_SETUP_FOOTNOTE -->' => $isUnavailable
                ? 'Closing this window does not delete or move the existing database.'
                : 'Closing this window cancels setup. RelayDeck will ask again next time.',
        ]);
    }

    private function renderFavicon(): string
    {
        // Boson uses the document favicon as the native window icon on Windows.
        return '<link rel="icon" type="image/png" sizes="256x256" href="data:image/png;base64,'
            . base64_encode($this->read('icons/app.png'))
            . '">';
    }

    private function renderUnavailableStorage(string $pathname, ?string $storageError): string
    {
        $pathname = htmlspecialchars($pathname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details = '';

        if ($storageError !== null && trim($storageError) !== '') {
            $details = '<span class="storage-error-details">'
                . htmlspecialchars($storageError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';
        }

        return '<aside class="storage-unavailable-note">'
            . '<strong>Database could not be opened</strong>'
            . '<p><code>' . $pathname . '</code></p>'
            . '<p>Restore access to the database file or its folder, then retry. '
            . 'Choosing another location leaves the old database untouched.</p>'
            . $details
            . '<button class="small-button storage-retry-button" id="retryStorageButton" type="button">'
            . 'Retry database</button>'
            . '</aside>';
    }

    private function read(string $relativePath): string
    {
        $pathname = $this->resourcesDirectory . '/' . $relativePath;
        $contents = @file_get_contents($pathname);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read UI resource "%s".', $pathname));
        }

        return $contents;
    }
}
