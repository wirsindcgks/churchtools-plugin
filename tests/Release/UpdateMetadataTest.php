<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Release;

use PHPUnit\Framework\TestCase;

/**
 * update.json ist alles, was eine installierte Kopie über dieses Plugin
 * erfährt: Sie fragt die Datei ab, und was darin steht, füllt das Fenster
 * hinter „Details anzeigen" (siehe Update\GitHubUpdateChecker und
 * bin/make-update-json.php).
 *
 * Bis 1.17.2 stand dort nur der Changelog. Die Doku verwies für die
 * Shortcode-Referenz und die FAQ trotzdem aufs Backend — die readme.txt zeigt
 * WordPress aber nur bei Plugins von wordpress.org an. Aufgefallen ist das erst
 * beim Nachstellen eines echten Updates auf der Testseite (2026-09-07), also
 * lange nach dem Schreiben der Doku; seit 1.17.3 wandern die Abschnitte der
 * readme.txt deshalb in diese Datei.
 *
 * Dieser Test hält den Weg offen. Er prüft die *erzeugte* Datei und nicht den
 * Erzeuger: Ausgeliefert wird die Datei, und ein vergessener Lauf von
 * `php bin/make-update-json.php .` ist genau der Fehler, der hier auffallen
 * soll.
 */
final class UpdateMetadataTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Die Reiter des Detailfensters, in der Reihenfolge, in der sie dort
     * stehen. „changelog" kommt aus CHANGELOG.md, der Rest aus readme.txt.
     */
    private const ABSCHNITTE = ['description', 'installation', 'verwendung', 'faq', 'datenschutz', 'changelog'];

    public function testEveryDocumentedSectionIsInTheUpdateMetadata(): void
    {
        $sections = $this->sections();

        $this->assertSame(
            self::ABSCHNITTE,
            array_keys($sections),
            'update.json fehlen Abschnitte oder hat neue - neu erzeugen mit "php bin/make-update-json.php .".'
        );

        foreach ($sections as $name => $html) {
            $this->assertNotSame('', trim($html), "Der Abschnitt \"{$name}\" in update.json ist leer.");
        }
    }

    /**
     * Die Referenz der Shortcode-Optionen ist der Grund, aus dem die Doku
     * überhaupt aufs Backend verweist - sie muss also wirklich ankommen.
     */
    public function testTheShortcodeReferenceArrivesInTheBackend(): void
    {
        $verwendung = $this->sections()['verwendung'] ?? '';

        foreach (['eventfinder', 'month_dividers', 'paging'] as $option) {
            $this->assertStringContainsString(
                '<code>' . $option . '</code>',
                $verwendung,
                "Die Option \"{$option}\" steht nicht im Abschnitt \"Verwendung\" von update.json."
            );
        }
    }

    /**
     * Der Wandler in bin/make-update-json.php kennt genau die Formen, die
     * readme.txt benutzt. Bleibt eine davon stehen, sieht das Detailfenster
     * die Zeichen selbst - „**fett**" statt fett.
     */
    public function testNoLeftoverMarkupInTheRenderedSections(): void
    {
        foreach ($this->sections() as $name => $html) {
            $this->assertStringNotContainsString('**', $html, "Unverwandeltes \"**\" im Abschnitt \"{$name}\".");
            $this->assertStringNotContainsString('`', $html, "Unverwandelter Backtick im Abschnitt \"{$name}\".");
            $this->assertStringNotContainsString('== ', $html, "Unverwandelte Abschnittsmarke im Abschnitt \"{$name}\".");
        }
    }

    /**
     * @return array<string, string>
     */
    private function sections(): array
    {
        $metadata = json_decode((string) file_get_contents(self::ROOT . '/update.json'), true);

        $this->assertIsArray($metadata, 'update.json ist kein gültiges JSON.');
        $this->assertArrayHasKey('sections', $metadata);

        return $metadata['sections'];
    }
}
