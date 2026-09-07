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
     * update.json ist die Datei, aus der installierte Kopien erfahren, dass es
     * eine neue Version gibt (siehe Update\GitHubUpdateChecker). Sie liegt im
     * Repo statt im Paket und wird von Hand mit bin/make-update-json.php
     * erzeugt - bleibt sie beim Versionswechsel stehen, bekommt niemand das
     * Update angeboten, und das faellt ohne diese Pruefung erst auf, wenn sich
     * jemand wundert, warum das Backend die alte Version fuer aktuell haelt.
     */
    public function testUpdateMetadataMatchesPluginVersion(): void
    {
        $metadata = json_decode((string) file_get_contents(self::ROOT . '/update.json'), true);

        $this->assertIsArray($metadata, 'update.json is not valid JSON.');
        $this->assertSame($this->pluginVersion(), $metadata['version'] ?? null);

        // Der Dateiname des Release-Assets ergibt sich aus dem Tag (siehe
        // .github/workflows/release.yml) - zeigt der Link woandershin, laedt
        // das Update ins Leere.
        $this->assertSame(
            sprintf(
                'https://github.com/wirsindcgks/churchtools-plugin/releases/download/v%1$s/churchtools-plugin-v%1$s.zip',
                $this->pluginVersion()
            ),
            $metadata['download_url'] ?? null
        );
    }

    /**
     * update.json traegt den Changelog-Abschnitt der ausgelieferten Version
     * mit - das ist der Text, den WordPress im Update-Dialog unter „Details
     * anzeigen" ausgibt. Er wird beim Erzeugen der Datei aus CHANGELOG.md
     * eingebacken, und genau daraus entsteht eine Luecke, die die Pruefungen
     * oben nicht sehen: Wird nach `bin/make-update-json.php` noch eine Zeile im
     * Changelog ergaenzt, stimmt die Version weiter, die Beschreibung aber
     * nicht mehr. Real passiert am 2026-09-07 - der Sitemap-Fix kam nach dem
     * Erzeugen dazu und fehlte in der Datei.
     *
     * Verglichen werden die fett eingeleiteten Eintraege des Abschnitts. Gegen
     * den *entschaerften* Text, nicht gegen das rohe HTML: Die eingebettete
     * Fassung maskiert Anfuehrungszeichen zu `&quot;`, ein woertlicher
     * Vergleich schluege dort falschen Alarm.
     */
    public function testUpdateMetadataCarriesTheCurrentChangelog(): void
    {
        $metadata = json_decode((string) file_get_contents(self::ROOT . '/update.json'), true);
        $embedded = (string) ($metadata['sections']['changelog'] ?? '');
        $this->assertNotSame('', $embedded, 'update.json fuehrt gar keinen Changelog.');

        $text = html_entity_decode(strip_tags($embedded), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $changelog = (string) file_get_contents(self::ROOT . '/CHANGELOG.md');
        $pattern = '/^## \[' . preg_quote($this->pluginVersion(), '/') . '\][^\n]*\n(.*?)(?=\n## \[|\z)/ms';
        $this->assertSame(1, preg_match($pattern, $changelog, $section));

        $this->assertGreaterThan(
            0,
            preg_match_all('/^- \*\*(.+?)\*\*/m', $section[1], $leads),
            'Der Abschnitt hat keine fett eingeleiteten Eintraege.'
        );

        foreach ($leads[1] as $lead) {
            $this->assertStringContainsString(
                $lead,
                $text,
                'update.json ist aelter als CHANGELOG.md - bin/make-update-json.php nach der letzten Changelog-Aenderung erneut laufen lassen.'
            );
        }
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
