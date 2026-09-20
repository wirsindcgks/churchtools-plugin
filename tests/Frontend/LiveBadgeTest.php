<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\LiveBadge;
use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;

/**
 * Das Kennzeichen „läuft gerade". Geprüft wird hier der Rahmen, den PHP
 * ausgibt - ob er im Browser sichtbar wird, entscheidet assets/js/frontend.js
 * an der Uhr (siehe den Klassen-Docblock von LiveBadge).
 *
 * wp_timezone() steht in tests/bootstrap.php fest auf Europe/Berlin, die
 * Zeitstempel unten tragen deshalb den Versatz dieser Zone.
 */
final class LiveBadgeTest extends TestCase
{
    private function event(array $overrides = []): array
    {
        return array_merge([
            'all_day' => 0,
            'start_date' => '2026-08-30 10:30:00',
            'end_date' => '2026-08-30 12:00:00',
        ], $overrides);
    }

    /**
     * Der Normalfall: eine verborgene Pille mit beiden Zeitpunkten. Das
     * `hidden` ist der eigentliche Gegenstand dieser Pruefung - ohne es
     * stuende das Kennzeichen an jedem Termin, auch am laengst vergangenen,
     * sobald das Skript ausbleibt.
     */
    public function testRendersHiddenBadgeWithBothTimestamps(): void
    {
        $html = LiveBadge::render($this->event(), 'Jetzt');
        $badge = $this->badgeElement($html);

        $this->assertStringContainsString('ctp-events__badge--live', $badge->getAttribute('class'));
        $this->assertSame('2026-08-30T10:30:00+02:00', $badge->getAttribute('data-ctp-live-start'));
        $this->assertSame('2026-08-30T12:00:00+02:00', $badge->getAttribute('data-ctp-live-end'));
        $this->assertStringContainsString('Jetzt', $badge->textContent);

        /*
         * Ueber das Markup gelesen und nicht per assertStringContainsString('hidden'):
         * Der Punkt in der Pille traegt `aria-hidden`, und eine Suche nach der
         * Zeichenfolge findet den - sie ist auch dann gruen, wenn das `hidden`
         * der Pille selbst fehlt. Das war sie hier schon einmal (Gegenprobe
         * beim Bau dieser Funktion), und ausgerechnet dieses Attribut ist
         * das, was die Behauptung „laeuft gerade" ueberhaupt zurueckhaelt.
         */
        $this->assertTrue($badge->hasAttribute('hidden'), 'Die Pille wird nicht verborgen ausgeliefert.');
    }

    /** Die aeusserste Pille des gerenderten Schnipsels, als Element. */
    private function badgeElement(string $html): DOMElement
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $badge = $dom->getElementsByTagName('span')->item(0);
        $this->assertInstanceOf(DOMElement::class, $badge, 'Kein Markup ausgegeben.');

        return $badge;
    }

    /**
     * Der Zeitzonen-Versatz ist kein Beiwerk: Die Uhr im Browser laeuft in
     * der Zone des Besuchers, die gespeicherten Zeiten stehen in der der
     * Website. Ohne „+02:00" laege das Kennzeichen fuer jeden ausserhalb
     * dieser Zone um Stunden daneben.
     */
    public function testTimestampsCarryTheSiteOffset(): void
    {
        $html = LiveBadge::render($this->event(), 'Jetzt');

        $this->assertMatchesRegularExpression('/data-ctp-live-start="[^"]+\+02:00"/', $html);
    }

    /** Das leere Feld ist der Ausschalter - dann steht auch kein Zeitstempel im Quelltext. */
    public function testEmptyLabelRendersNothing(): void
    {
        $this->assertSame('', LiveBadge::render($this->event(), ''));
        $this->assertSame('', LiveBadge::render($this->event(), '   '));
    }

    /**
     * Ganztaegige Termine laufen vom ersten bis zum letzten Augenblick ihrer
     * Tage. Die Uhrzeit, die dabei in der Datenbank steht, ist bedeutungslos
     * (siehe EventFormatter::timeRange(), das sie aus demselben Grund nicht
     * anzeigt) - hier wird sie deshalb ueberschrieben.
     */
    public function testAllDayEventSpansTheWholeDay(): void
    {
        $html = LiveBadge::render(
            $this->event(['all_day' => 1, 'start_date' => '2026-08-30 00:00:00', 'end_date' => '2026-08-30 00:00:00']),
            'Jetzt'
        );

        $this->assertStringContainsString('data-ctp-live-start="2026-08-30T00:00:00+02:00"', $html);
        $this->assertStringContainsString('data-ctp-live-end="2026-08-30T23:59:59+02:00"', $html);
    }

    public function testMultiDayAllDayEventSpansEveryDay(): void
    {
        $html = LiveBadge::render(
            $this->event(['all_day' => 1, 'start_date' => '2026-08-28 00:00:00', 'end_date' => '2026-08-30 18:00:00']),
            'Jetzt'
        );

        $this->assertStringContainsString('data-ctp-live-start="2026-08-28T00:00:00+02:00"', $html);
        $this->assertStringContainsString('data-ctp-live-end="2026-08-30T23:59:59+02:00"', $html);
    }

    /**
     * Die zweite verbreitete Schreibweise fuer ganztaegige Termine: Ende um
     * Mitternacht des *Folgetags*, also als offenes Intervall. Ohne den
     * Rueckschritt liefe eine Freizeit einen vollen Tag zu lang als „laeuft
     * gerade" - und zwar an dem Tag, an dem sie schon vorbei ist.
     */
    public function testAllDayEventEndingAtMidnightStopsTheDayBefore(): void
    {
        $html = LiveBadge::render(
            $this->event(['all_day' => 1, 'start_date' => '2026-08-28 00:00:00', 'end_date' => '2026-08-31 00:00:00']),
            'Jetzt'
        );

        $this->assertStringContainsString('data-ctp-live-end="2026-08-30T23:59:59+02:00"', $html);
    }

    /**
     * Ein Termin mit Uhrzeit, der um Mitternacht endet, endet dort wirklich -
     * der Rueckschritt oben gilt ausschliesslich ganztaegigen Terminen.
     */
    public function testTimedEventEndingAtMidnightKeepsItsEnd(): void
    {
        $html = LiveBadge::render(
            $this->event(['start_date' => '2026-08-30 22:00:00', 'end_date' => '2026-08-31 00:00:00']),
            'Jetzt'
        );

        $this->assertStringContainsString('data-ctp-live-end="2026-08-31T00:00:00+02:00"', $html);
    }

    /** Ohne Ende gilt der Beginn - ein Kennzeichen, das nie wieder verschwindet, waere schlimmer. */
    public function testMissingEndFallsBackToTheStart(): void
    {
        $html = LiveBadge::render($this->event(['end_date' => '']), 'Jetzt');

        $this->assertStringContainsString('data-ctp-live-start="2026-08-30T10:30:00+02:00"', $html);
        $this->assertStringContainsString('data-ctp-live-end="2026-08-30T10:30:00+02:00"', $html);
    }

    /**
     * @dataProvider unusableDates
     */
    public function testUnusableDatesRenderNothing(array $overrides): void
    {
        $this->assertSame('', LiveBadge::render($this->event($overrides), 'Jetzt'));
    }

    public static function unusableDates(): array
    {
        return [
            'leerer Beginn' => [['start_date' => '']],
            'MySQL-Nullwert' => [['start_date' => '0000-00-00 00:00:00']],
            'unlesbar' => [['start_date' => 'gestern irgendwann']],
            'Ende vor Beginn' => [['end_date' => '2026-08-29 12:00:00']],
        ];
    }

    /**
     * Das Wort kommt aus einem Feld, in das jemand alles tippen kann - auch
     * spitze Klammern. In der Kachel steht es dann als Text und nicht als
     * Markup.
     */
    public function testLabelIsEscaped(): void
    {
        $html = LiveBadge::render($this->event(), '<b>Live</b>');

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testSanitizeLabelStripsMarkupAndTrims(): void
    {
        $this->assertSame('Live', LiveBadge::sanitizeLabel('  <b>Live</b>  '));
    }

    /**
     * Die Pille teilt sich die Zeile mit dem Terminnamen. Was laenger ist als
     * ein Etikett, wird gekuerzt statt die Ueberschrift zu sprengen.
     */
    public function testSanitizeLabelCapsTheLength(): void
    {
        $label = LiveBadge::sanitizeLabel(str_repeat('a', 100));

        $this->assertSame(LiveBadge::MAX_LABEL_LENGTH, mb_strlen($label));
    }

    /** Gekuerzt wird nach Zeichen, nicht nach Bytes - Umlaute zaehlen einfach. */
    public function testSanitizeLabelCountsCharactersNotBytes(): void
    {
        $this->assertSame('Läuft gerade', LiveBadge::sanitizeLabel('Läuft gerade'));
    }
}
