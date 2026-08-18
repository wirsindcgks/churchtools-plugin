<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Release;

use PHPUnit\Framework\TestCase;

/**
 * The plugin's version number is written down in four independent places, and
 * nothing but discipline used to keep them in sync — which failed: CTP_VERSION
 * sat at 0.2.0 while the plugin header already read 0.5.0, so every asset URL
 * kept its 0.2.0 cache buster across three releases (browsers served a stale
 * frontend.css/admin.css after an update) and the Übersicht tab reported the
 * wrong installed version, including in its "ist ein Update verfügbar?"
 * comparison.
 *
 * Cheap to assert and a release-blocking mistake to get wrong, so it is a test
 * rather than a checklist item.
 */
final class VersionConsistencyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testPluginHeaderAndConstantAgree(): void
    {
        $bootstrap = (string) file_get_contents(self::ROOT . '/churchtools-plugin.php');

        $this->assertSame(
            $this->headerVersion($bootstrap),
            $this->constantVersion($bootstrap),
            'CTP_VERSION must match the "Version:" plugin header - it is what cache-busts the CSS/JS assets.'
        );
    }

    public function testReadmeStableTagMatchesPluginVersion(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/readme.txt');
        preg_match('/^Stable tag:\s*(.+)$/m', $readme, $matches);

        $this->assertNotEmpty($matches, 'readme.txt has no "Stable tag" line.');
        $this->assertSame($this->pluginVersion(), trim($matches[1]));
    }

    public function testChangelogDocumentsCurrentVersion(): void
    {
        $changelog = (string) file_get_contents(self::ROOT . '/CHANGELOG.md');
        preg_match('/^## \[([^\]]+)\]/m', $changelog, $matches);

        $this->assertNotEmpty($matches, 'CHANGELOG.md has no "## [x.y.z]" release heading.');
        $this->assertSame(
            $this->pluginVersion(),
            $matches[1],
            'The topmost CHANGELOG.md release must be the version being shipped.'
        );
    }

    /**
     * Semantic versioning, since the GitHub update checker compares releases
     * with version_compare() - a tag like "v1.0" or "1.0.0-final" would sort in
     * ways nobody expects.
     */
    public function testVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->pluginVersion());
    }

    private function pluginVersion(): string
    {
        return $this->headerVersion((string) file_get_contents(self::ROOT . '/churchtools-plugin.php'));
    }

    private function headerVersion(string $bootstrap): string
    {
        preg_match('/^\s*\*\s*Version:\s*(.+)$/m', $bootstrap, $matches);
        $this->assertNotEmpty($matches, 'churchtools-plugin.php has no "Version:" header.');

        return trim($matches[1]);
    }

    private function constantVersion(string $bootstrap): string
    {
        preg_match("/define\('CTP_VERSION',\s*'([^']+)'\)/", $bootstrap, $matches);
        $this->assertNotEmpty($matches, 'churchtools-plugin.php does not define CTP_VERSION.');

        return $matches[1];
    }
}
