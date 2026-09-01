<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Die Überleitung, die EventListRenderer::renderMatches() einem leer
 * ausgegangenen Zeitraum unterstellt (partials/timeframe-outlook.php).
 *
 * Sie ist der ganze Unterschied zwischen „hier sind die nächsten Termine, weil
 * in diesem Monat keine mehr sind" und einem stillschweigend verbreiterten
 * Zeitraum - ohne sie stünden unter „Diesen Monat" Termine aus dem nächsten,
 * ohne dass irgendetwas das sagt. Deshalb wird hier nicht nur geprüft, dass
 * überhaupt etwas dasteht, sondern dass es der Satz zum richtigen Zeitraum ist
 * und dass er die Suche nicht unterschlägt.
 */
final class TimeframeOutlookTest extends TestCase
{
    /**
     * Ein Zeitraum-Schlüssel, sein Satz ohne Suchbegriff, sein Satz mit einem.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function timeframeProvider(): array
    {
        return [
            'Diese Woche' => [
                'week',
                'In dieser Woche stehen keine Termine mehr an.',
                'In dieser Woche gibt es dazu keine Termine mehr.',
            ],
            'Dieses Wochenende' => [
                'weekend',
                'An diesem Wochenende stehen keine Termine mehr an.',
                'An diesem Wochenende gibt es dazu keine Termine mehr.',
            ],
            'Diesen Monat' => [
                'month',
                'In diesem Monat stehen keine Termine mehr an.',
                'In diesem Monat gibt es dazu keine Termine mehr.',
            ],
        ];
    }

    /** @dataProvider timeframeProvider */
    public function testEachTimeframeNamesItselfInTheNotice(
        string $timeframe,
        string $withoutSearch,
        string $withSearch
    ): void {
        $this->assertSame($withoutSearch, $this->noticeText($this->render($timeframe, false)));
        $this->assertSame($withSearch, $this->noticeText($this->render($timeframe, true)));
    }

    /**
     * Mit aktivem Suchbegriff gibt es in dem Zeitraum sehr wohl Termine, nur
     * keine passenden. „Keine Termine" wäre dort schlicht falsch - und zwar
     * genau die Art von Falschaussage, gegen die dieser Block antritt.
     */
    public function testTheNoticeDoesNotDenyAllEventsWhileASearchIsActive(): void
    {
        $text = $this->noticeText($this->render('month', true));

        $this->assertStringNotContainsString('keine Termine mehr an', $text);
        $this->assertStringContainsString('dazu', $text);
    }

    /**
     * Die Ankündigung darunter muss sagen, dass die Kacheln *danach* kommen -
     * ohne sie läse sich der Block wie ein Ergebnis des gefragten Zeitraums.
     */
    public function testTheHeadingAnnouncesWhatComesNext(): void
    {
        $this->assertSame('Die nächsten Termine:', $this->headingText($this->render('month', false)));
        $this->assertSame('Die nächsten passenden Termine:', $this->headingText($this->render('month', true)));
    }

    /**
     * Der Block landet in .ctp-events__list, und das ist ein role="list" (siehe
     * event-list.php und die Begründung in frontend.css, warum es kein <ul>
     * ist). Ein Kind ohne Listenrolle macht diese Liste für Screenreader
     * kaputt - dieselbe Auflage, unter der schon der Monatstrenner steht.
     */
    public function testTheBlockIsAValidChildOfTheListContainer(): void
    {
        $xpath = new DOMXPath($this->render('month', false));
        $block = $xpath->query('//*[contains(@class, "ctp-events__outlook")]')->item(0);

        $this->assertNotNull($block);
        $this->assertSame('listitem', $block->getAttribute('role'));
    }

    /**
     * Der Block ist keine Kachel: assets/js/frontend.js zählt die sichtbaren
     * Termine über [data-ctp-calendar], um zu entscheiden, ob „Keine Termine
     * gefunden." erscheint. Trüge die Überleitung dieses Attribut, gälte sie
     * selbst als Termin - und die Meldung käme nie wieder, auch wenn
     * tatsächlich nichts mehr da ist.
     */
    public function testTheBlockDoesNotCountAsAnEventItem(): void
    {
        $xpath = new DOMXPath($this->render('month', false));

        $this->assertSame(0, $xpath->query('//*[@data-ctp-calendar]')->length);
    }

    private function noticeText(DOMDocument $dom): string
    {
        return $this->textOf($dom, 'ctp-events__outlook-notice');
    }

    private function headingText(DOMDocument $dom): string
    {
        return $this->textOf($dom, 'ctp-events__outlook-heading');
    }

    private function textOf(DOMDocument $dom, string $class): string
    {
        $node = (new DOMXPath($dom))->query('//*[contains(@class, "' . $class . '")]')->item(0);

        $this->assertNotNull($node, $class . ' fehlt im Ausblick.');

        return trim($node->textContent);
    }

    private function render(string $timeframe, bool $isSearch): DOMDocument
    {
        $outlookTimeframe = $timeframe;
        $outlookIsSearch = $isSearch;

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/timeframe-outlook.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }
}
