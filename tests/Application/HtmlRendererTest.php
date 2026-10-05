<?php

declare(strict_types=1);

namespace ApiClient\Tests\Application;

use ApiClient\Application\HtmlRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRenderer::class)]
final class HtmlRendererTest extends TestCase
{
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new HtmlRenderer(dirname(__DIR__, 2) . '/resources');
    }

    public function testItRendersTheFirstLaunchDocument(): void
    {
        $html = $this->renderer->renderStorageSetup();

        self::assertStringContainsString('<h1 id="storageSetupTitle">Choose where to store your workspace</h1>', $html);
        self::assertStringNotContainsString('<aside class="storage-unavailable-note">', $html);
        $this->assertNoTemplatePlaceholders($html);
    }

    public function testItRendersAndEscapesAnUnavailableStorageLocation(): void
    {
        $html = $this->renderer->renderStorageSetup(
            '/tmp/Storage/<script>alert(1)</script>',
            'Unable to open <database>',
            true,
        );

        self::assertStringContainsString('Reconnect your workspace storage', $html);
        self::assertStringContainsString('<aside class="storage-unavailable-note">', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Unable to open &lt;database&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Restore access to the database file or its folder, then retry.', $html);
        self::assertStringContainsString('Choose another database file', $html);
        self::assertStringContainsString('Select an existing RelayDeck file with the .sqlite extension.', $html);
        $this->assertNoTemplatePlaceholders($html);
    }

    public function testItRendersTheWorkspaceDocument(): void
    {
        $html = $this->renderer->render();

        self::assertStringContainsString('<title>RelayDeck</title>', $html);
        self::assertStringContainsString('id="databaseSelect"', $html);
        self::assertStringContainsString('id="databaseModal"', $html);
        self::assertStringContainsString('id="openExistingDatabaseButton"', $html);
        self::assertStringContainsString('id="createDatabaseButton"', $html);
        self::assertStringContainsString('Enter a filename, then select its folder.', $html);
        self::assertStringContainsString('id="entityNameLabel"', $html);
        self::assertStringContainsString('my-database.sqlite', $html);
        self::assertStringContainsString('id="variableSuggestions"', $html);
        self::assertStringContainsString('aria-autocomplete="list"', $html);
        self::assertStringContainsString('Current environment variables', $html);
        $this->assertNoTemplatePlaceholders($html);
    }

    private function assertNoTemplatePlaceholders(string $html): void
    {
        self::assertStringNotContainsString('<!-- APP_', $html);
        self::assertStringNotContainsString('<!-- STORAGE_', $html);
    }
}
