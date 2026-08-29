<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Der Tab „Updates“ und die Uebersicht lesen ihre Eintraege direkt aus der
 * mitgelieferten CHANGELOG.md. Die Datei ist damit ein Eingabeformat wie jedes
 * andere - und die einzige Stelle, an der ein Formatfehler auffiele, waere
 * eine Admin-Seite, die ploetzlich Sternchen anzeigt oder einen Eintrag
 * verschluckt.
 */
final class ChangelogParserTest extends TestCase
{
    /**
     * @return array{lead: string, text: string}
     */
    private function split(string $line): array
    {
        $method = new ReflectionMethod(SettingsPage::class, 'splitChangelogLine');

        return $method->invoke(null, $line);
    }

    /**
     * Die haeufigste Schreibweise in der Datei: der Punkt steht innerhalb der
     * Fettung.
     */
    public function testSplitsBoldLeadFromExplanation(): void
    {
        $result = $this->split('**Der Token ist keine Voraussetzung mehr.** Das Repository ist öffentlich.');

        $this->assertSame('Der Token ist keine Voraussetzung mehr.', $result['lead']);
        $this->assertSame('Das Repository ist öffentlich.', $result['text']);
    }

    /**
     * Die zweite Schreibweise: der Doppelpunkt steht hinter der Fettung und
     * gehoert zur Naht, nicht zum Folgesatz.
     */
    public function testDropsTheSeparatorThatFollowsTheBoldLead(): void
    {
        $result = $this->split('**Der Button war funktionslos**: Der Klick-Handler las das falsche Feld.');

        $this->assertSame('Der Button war funktionslos.', $result['lead']);
        $this->assertSame('Der Klick-Handler las das falsche Feld.', $result['text']);
    }

    /**
     * Backticks zeichnen im Backend nichts aus, sie stuenden dort nur als
     * Zeichen herum - und zwar in beiden Haelften.
     */
    public function testStripsInlineCodeMarkersFromBothHalves(): void
    {
        $result = $this->split('**`getLastError()` prüft die Form.** `is_array()` sagt nichts über die Schlüssel.');

        $this->assertSame('getLastError() prüft die Form.', $result['lead']);
        $this->assertSame('is_array() sagt nichts über die Schlüssel.', $result['text']);
    }

    /** Weitere Fettungen mitten im Text tragen im Backend nichts bei. */
    public function testStripsFurtherBoldMarkersInsideTheExplanation(): void
    {
        $result = $this->split('**Kurzfassung.** Der Wert ist **immer** gesetzt.');

        $this->assertSame('Der Wert ist immer gesetzt.', $result['text']);
    }

    /**
     * Ein Eintrag ohne Fettung ist kein Fehler - er hat dann eben keine
     * Erklaerung, und die Zeile selbst ist die Kurzfassung.
     */
    public function testLineWithoutABoldLeadBecomesTheLeadItself(): void
    {
        $result = $this->split('Automatische Plugin-Updates über GitHub Releases');

        $this->assertSame('Automatische Plugin-Updates über GitHub Releases', $result['lead']);
        $this->assertSame('', $result['text']);
    }

    /**
     * Ohne abschliessendes Satzzeichen wird eines ergaenzt, damit die
     * Kurzfassungen untereinander gleich aussehen.
     */
    public function testAddsAFullStopToAnUnpunctuatedLead(): void
    {
        $this->assertSame('Ohne Punkt geschrieben.', $this->split('**Ohne Punkt geschrieben** Erklärung.')['lead']);
    }

    /**
     * Die echte CHANGELOG.md des Repos, nicht ein Ausschnitt: sie ist das,
     * was im Release-Zip landet und was die beiden Admin-Ansichten lesen.
     */
    public function testReadsTheShippedChangelog(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'changelogReleases');
        $releases = $method->invoke(null, 3, 6);

        $this->assertCount(3, $releases, 'Der Tab „Updates“ zeigt die letzten drei Versionen.');

        foreach ($releases as $release) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $release['version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $release['date']);
            $this->assertNotEmpty($release['items']);

            foreach ($release['items'] as $item) {
                $this->assertNotSame('', $item['lead']);
                $this->assertStringNotContainsString('**', $item['lead'] . $item['text']);
                $this->assertStringNotContainsString('`', $item['lead'] . $item['text']);
            }
        }
    }
}
