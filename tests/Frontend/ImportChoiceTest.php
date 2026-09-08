<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Die Rückfrage hinter „Importieren": Gehört ein Termin zu einer Serie, klappt
 * der Knopf auf und lässt zwischen dem einen Termin und allen wählen.
 *
 * Drei Zusagen stehen hier, und alle drei sind unsichtbar, solange man nur
 * hinsieht:
 *
 *   1. **Ein Einzeltermin bekommt keine Rückfrage.** Rund ein Viertel der
 *      Termine ist gar keine Serie; dort wäre die Wahl ein Klick ohne
 *      Alternative. Diese Fallunterscheidung wieder zu verlieren würde
 *      niemandem auffallen — es sähe nur nach einem Schritt mehr aus.
 *   2. **Ohne JavaScript.** Der Import war von Anfang an ein blanker
 *      `<a download>`. Ein Skript-Menü hätte ihn für jeden unbrauchbar
 *      gemacht, der keins ausführt, und das sieht man einer laufenden Seite
 *      mit Skript nicht an.
 *   3. **Die beiden Ziele sind verschieden.** Zeigten beide auf dieselbe
 *      Adresse, wäre die Wahl eine Attrappe — und auch das sähe völlig normal
 *      aus, bis jemand die Datei öffnet.
 */
final class ImportChoiceTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
        ctp_test_set_option('permalink_structure', '');
        ctp_test_set_option('ctp_settings', ['detail_page_id' => 0, 'calendars' => []]);
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public function testASingleEventKeepsTheDirectLinkWithoutAnyQuestion(): void
    {
        $xpath = $this->render(1);

        $this->assertCount(0, $this->nodes($xpath, '//details'), 'Ein Einzeltermin soll nichts zum Aufklappen haben.');
        $this->assertCount(1, $this->nodes($xpath, '//a[@download]'));
    }

    public function testASeriesFoldsTheChoiceUnderOneButton(): void
    {
        $xpath = $this->render(15);

        $details = $this->nodes($xpath, '//details');
        $this->assertCount(1, $details, 'Genau ein aufklappbarer Knopf.');

        $summary = $this->nodes($xpath, '//details/summary');
        $this->assertCount(1, $summary);
        $this->assertStringContainsString('Importieren', $summary[0]->textContent);
    }

    /**
     * Eingeklappt ist im Bild nichts hinzugekommen: ein Chip, wie beim
     * Einzeltermin. Genau darum ging es beim Umbau — drei Chips nebeneinander
     * waren gemessen 401px Knopfleiste im 640px breiten Popup.
     */
    public function testNothingExtraIsVisibleUntilTheButtonIsOpened(): void
    {
        $offen = $this->nodes($this->render(15), '//details[@open]');

        $this->assertCount(0, $offen, 'Die Auswahl darf nicht schon aufgeklappt ankommen.');
    }

    public function testTheChoiceOffersThisDateAndTheWholeSeries(): void
    {
        $wahl = $this->nodes($this->render(15), '//details//a[@download]');

        $this->assertCount(2, $wahl);
        $this->assertStringContainsString('Nur dieser Termin', $wahl[0]->textContent);
        $this->assertStringContainsString('Alle 15 Termine', $wahl[1]->textContent);
    }

    /**
     * Die Zahl ist keine Verzierung: „Ganze Serie" wäre eine Zusage, die das
     * Plugin nicht halten kann (gezählt wird über ChurchTools' Basistermin,
     * und der Abgleich reicht nur `sync_days_ahead` weit voraus). Eine Zahl
     * ist gegen die Liste daneben prüfbar.
     */
    public function testTheLabelNamesHowManyDatesTheFileWillContain(): void
    {
        $wahl = $this->nodes($this->render(4), '//details//a[@download]');

        $this->assertStringContainsString('Alle 4 Termine', $wahl[1]->textContent);
    }

    public function testTheTwoChoicesLeadToDifferentFiles(): void
    {
        $wahl = $this->nodes($this->render(15), '//details//a[@download]');

        $einzeln = $wahl[0]->getAttribute('href');
        $serie = $wahl[1]->getAttribute('href');

        $this->assertNotSame($einzeln, $serie, 'Beide Wahlmöglichkeiten liefern dieselbe Datei.');
        $this->assertStringContainsString('ctp_ics=1', htmlspecialchars_decode($einzeln));
        $this->assertStringContainsString('ctp_ics=serie', htmlspecialchars_decode($serie));
    }

    /**
     * Kein `onclick`, kein `data-`-Haken, auf den ein Skript hört: Was hier
     * aufklappt, klappt der Browser auf. Ein Verweis mit `download`
     * funktioniert auch dann noch, wenn nichts ausgeführt wird.
     */
    public function testTheWholeThingWorksWithoutJavaScript(): void
    {
        $xpath = $this->render(15);

        $this->assertCount(0, $this->nodes($xpath, '//details//*[@onclick]'));
        $this->assertCount(0, $this->nodes($xpath, '//details//button'));

        foreach ($this->nodes($xpath, '//details//a') as $verweis) {
            $this->assertNotSame('', $verweis->getAttribute('href'), 'Ein Verweis ohne Ziel braucht ein Skript.');
            $this->assertTrue($verweis->hasAttribute('download'));
        }
    }

    /**
     * Sichtbar steht die kurze Fassung, vorgelesen die ganze — und die lange
     * muss die sichtbare *enthalten*, sonst nennt eine Sprachsteuerung ein
     * Wort, auf das die Seite nicht hört (WCAG 2.5.3).
     */
    public function testWhatIsReadOutContainsWhatIsWritten(): void
    {
        $verweise = array_merge(
            $this->nodes($this->render(15), '//a[@download]'),
            // Der Einzeltermin steht unter demselben Vertrag, auch wenn er
            // keine Rückfrage hat.
            $this->nodes($this->render(1), '//a[@download]')
        );

        foreach ($verweise as $verweis) {
            $sichtbar = trim($verweis->textContent);
            $vorgelesen = $verweis->getAttribute('aria-label');

            $this->assertNotSame('', $vorgelesen);
            $this->assertStringContainsStringIgnoringCase($sichtbar, $vorgelesen);
        }
    }

    /**
     * Die Auswahl schwebt, sie schiebt nicht.
     *
     * Der erste Bau hatte sie im Fluss, und das war der Nutzerbefund vom
     * 2026-09-08: „Beim Klick auf den Button wandert der Button an eine andere
     * Stelle." Die Ursache liegt im Flex-Kasten darum — die aufgeklappte
     * Auswahl ist breiter als der Knopf (gemessen 292 gegen 131px), also
     * rechnet `.ctp-events__actions` die ganze Zeile neu und schiebt die
     * Gruppe. `position: absolute` nimmt sie aus dem Fluss; fällt die Zeile
     * weg, wandert der Knopf wieder, und das sieht man nur im Betrieb.
     */
    public function testTheCardFloatsInsteadOfPushingTheButtonAside(): void
    {
        $regel = $this->cssRule('.ctp-events__import-choices');

        $this->assertStringContainsString('position: absolute', $regel);
    }

    /**
     * Nach *oben*, und das ist keine Geschmacksfrage: Der Rumpf des Popups ist
     * ein eigener Scroll-Container (die Lehre aus 1.18.0). Eine Fläche, die
     * nach unten aus der letzten Zeile herausragt, verlängert dessen
     * Scrollbereich — man müsste zu einem Menü scrollen, das man gerade
     * geöffnet hat. Am Testsystem nachgemessen: Überhang 0 im zu- wie im
     * aufgeklappten Zustand.
     */
    public function testTheCardOpensUpwardsSoThePopupNeverGrowsAScrollbar(): void
    {
        $regel = $this->cssRule('.ctp-events__import-choices');

        $this->assertStringContainsString('bottom: calc(100% + 0.4rem)', $regel);
        $this->assertStringNotContainsString('top:', $regel);
    }

    /**
     * Ohne einen Bezugspunkt fiele die absolut gesetzte Karte an das nächste
     * positionierte Element weiter oben — irgendwohin in die Detailansicht,
     * nicht an ihren Knopf.
     */
    public function testTheCardIsAnchoredToItsOwnButton(): void
    {
        $this->assertStringContainsString('position: relative', $this->cssRule('.ctp-events__import'));
    }

    /**
     * Die Wahlmöglichkeiten bekommen keine eigene Breite — die Breite macht
     * `align-items: stretch`, der Vorgabewert der Flex-Spalte.
     *
     * Hier stand einmal `width: 100%`, und das war falsch: Diese Elemente
     * rechnen in `content-box`, die 100% gelten also dem Inhalt, Polsterung
     * und Rahmen kommen obendrauf. Nachgemessen war die Karte innen 156px
     * breit und der Chip 177px — **21px Überstand, auf 0,2px genau die
     * Polsterung plus die beiden Rahmen**. Der Nutzer hat es im Bild sofort
     * gesehen (2026-09-08: „Leider passen die Buttons noch nicht vollständig
     * in den Overlay").
     *
     * **Dieselbe Fehlerklasse zum dritten Mal in diesem Projekt** — die Panels
     * in 1.7.0 und der Popup-Rumpf in 1.18.0 waren beide content-box gegen
     * border-box, und beide Male war der Überstand exakt die Polsterung.
     * Deshalb steht sie jetzt in einem Test und nicht nur in einem Kommentar.
     */
    public function testTheChoicesGetTheirWidthFromTheLayoutAndNotFromADeclaration(): void
    {
        $regel = $this->cssRule('.ctp-events__import-choices .ctp-events__import-choice');

        $this->assertStringNotContainsString(
            'width:',
            $regel,
            'Eine feste Breite neben einer Polsterung laeuft in content-box ueber - siehe Docblock.'
        );
    }

    private function cssRule(string $selector): string
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');
        $treffer = [];
        preg_match_all('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $treffer);

        $this->assertNotSame([], $treffer[1], "Keine Regel für {$selector} gefunden.");

        return implode("\n", $treffer[1]);
    }

    /**
     * @return DOMElement[]
     */
    private function nodes(DOMXPath $xpath, string $query): array
    {
        $liste = $xpath->query($query);
        $this->assertInstanceOf(DOMNodeList::class, $liste);

        return iterator_to_array($liste);
    }

    private function render(int $seriesCount): DOMXPath
    {
        $event = [
            'id' => 12,
            'ct_event_id' => 900,
            'ct_calendar_id' => 7,
            'title' => 'Offener Hauskreis',
            'start_date' => '2026-09-10 19:30:00',
            'end_date' => '2026-09-10 21:00:00',
            'all_day' => 0,
            'detail_url' => 'https://example.test/termine/offener-hauskreis-10-09-2026/',
            'series_count' => $seriesCount,
        ];
        $key = 'ics';
        $detailContext = 'page';

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-element.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // <details>/<summary> liegen jenseits von libxmls HTML-Vokabular; es
        // behält sie als allgemeine Elemente mit intakter Verschachtelung, und
        // mehr wird hier nicht behauptet.
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
