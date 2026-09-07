<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DetailDesign;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * partials/event-detail-content.php baut dieselben Felder zweimal: flach fürs
 * Popup, für die eigene Seite in .ctp-events__detail-text gefasst — die linke
 * Spalte des zweispaltigen Layouts. Fehlt diese Hülle, fällt nicht etwa eine
 * Regel aus, sondern die Seite fällt auf die einspaltige Anordnung zurück und
 * sieht dabei völlig intakt aus. Genau solche Fehler findet kein Blick auf die
 * Seite, sondern nur eine Behauptung über das Markup.
 */
final class DetailLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public function testThePagePutsEverythingButImageAndDescriptionInOneColumn(): void
    {
        $xpath = $this->render('page');

        $this->assertSame(
            [
                'ctp-events__eyebrow',
                'ctp-events__detail-heading',
                'ctp-events__subtitle',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
            ],
            $this->childClasses($xpath, 'ctp-events__detail-text')
        );

        // Das Bild gehört nicht in die Hülle: Es steht in der zweiten Spalte,
        // neben dem ganzen Block, und das kann es nur als direktes Kind des
        // Rasters.
        $this->assertCount(
            1,
            $xpath->query('//*[contains(@class, "ctp-events__detail")]/div[@class="ctp-events__detail-media"]')
        );
    }

    /**
     * Der Teilen-Knopf steht fest in DetailDesign::ELEMENT_KEYS, gibt aber nur
     * etwas aus, wenn die Einstellung ihn einschaltet. Ausgeschaltet fällt sein
     * Schlüssel aus der Reihenfolge, statt ein leeres Element zu hinterlassen —
     * das bekäme in der flachen Popup-Anordnung über `.ctp-events__detail > *`
     * trotzdem seine volle Zeile zugeteilt.
     *
     * @dataProvider detailContextProvider
     */
    public function testTheShareButtonOnlyAppearsWhenItIsSwitchedOn(string $detailContext): void
    {
        $off = $this->render($detailContext);
        $this->assertCount(0, $off->query('//*[contains(@class, "ctp-events__share")]'), 'ausgeschaltet: kein Knopf');
        $this->assertCount(0, $off->query('//div[@data-key="share"]'), 'und auch keine leere Hülle');

        $on = $this->render($detailContext, null, 'https://example.test/flyer.jpg', true);
        $buttons = $on->query('//button[contains(@class, "ctp-events__share-btn")]');

        $this->assertCount(1, $buttons);
        $this->assertInstanceOf(DOMElement::class, $buttons[0]);
        $this->assertSame(
            'https://example.test/termin/gottesdienst',
            $buttons[0]->getAttribute('data-ctp-share-url'),
            'geteilt wird die Adresse des Termins, nicht die der Seite'
        );
    }

    /**
     * Auf der eigenen Seite gehört der Knopf *nicht* in die Textspalte, sondern
     * zu dem Teil, der unter dem Ganzen über die volle Breite läuft — wie die
     * Beschreibung, und hinter ihr (Nutzerwunsch 2026-09-07: „rechts unterhalb
     * dem Beschreibungstext", in beiden Ansichten).
     *
     * In der Textspalte stand er zwischen den Eckdaten und damit *neben* der
     * Beschreibung statt unter ihr, während er im Popup längst hinter ihr lag:
     * dieselbe eingestellte Reihenfolge, zwei verschiedene Bilder.
     */
    public function testThePagePutsTheShareButtonBelowTheDescriptionInsteadOfIntoTheTextColumn(): void
    {
        $xpath = $this->render('page', null, 'https://example.test/flyer.jpg', true);

        $this->assertSame(
            [
                'ctp-events__eyebrow',
                'ctp-events__detail-heading',
                'ctp-events__subtitle',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
            ],
            $this->childClasses($xpath, 'ctp-events__detail-text'),
            'Der Teilen-Knopf gehört nicht mehr in die linke Spalte.'
        );

        // Direkte Kinder des Rasters, in Quelltext-Reihenfolge: die Textspalte,
        // dann Bild und Knopf. „description" fehlt in dieser Fixture bewusst
        // (siehe render()), der Knopf muss trotzdem hinter dem Bild stehen.
        $this->assertSame(
            ['ctp-events__detail-text', 'ctp-events__detail-media', 'ctp-events__share'],
            $this->childClasses($xpath, 'ctp-events__detail')
        );
    }

    /**
     * Auf der eigenen Seite steht der Knopf links, im Popup rechts — und das
     * ist keine Inkonsequenz, sondern folgt derselben Frage: An welcher Kante
     * hängt alles andere?
     *
     * Auf der Seite beginnen Etikett, Titel, Eckdaten und der
     * Beschreibungsabsatz alle an der linken Rasterkante (nachgemessen: alle
     * fünf bei 217px). Rechts ausgerichtet hätte der Knopf als einziges
     * Element eine eigene Fluchtlinie — und weil der Absatz beim Lesemaß von
     * 42rem aufhört, der Block aber breiter ist, nicht einmal die des Textes
     * über ihm. Im Popup fallen Text- und Rasterkante zusammen, dort stellt
     * sich die Frage nicht.
     *
     * Die Grundregel setzt fürs Popup `margin-inline-start: auto` und
     * `justify-content: flex-end`; auf der Seite müssen deshalb *beide* zurück-
     * genommen werden — die Außenkante schöbe den Kasten nach rechts, die
     * Füllrichtung seinen Inhalt darin.
     */
    public function testTheShareButtonAlignsLeftOnThePageAndKeepsItsOwnRow(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');

        preg_match(
            '/\.ctp-events--detail \.ctp-events__detail > \.ctp-events__share \{([^}]*)\}/',
            $css,
            $treffer
        );
        $this->assertNotEmpty($treffer, 'Keine Seiten-Regel für den Teilen-Knopf gefunden.');
        $regel = $treffer[1];

        $this->assertStringContainsString('justify-content: flex-start;', $regel);
        $this->assertStringContainsString('margin-inline-start: 0;', $regel);

        // Eigene Zeile über die volle Rasterbreite: Für die Position des
        // Knopfes ist das gleichgültig (links ist links), aber die Rückmeldung
        // daneben kann die Adresse selbst tragen, wenn die Zwischenablage
        // fehlschlägt — die soll über die ganze Breite umbrechen dürfen und
        // nicht in der schmaleren linken Spalte.
        $this->assertStringContainsString('grid-column: 1 / -1;', $regel);
    }

    /**
     * Der Knopf ist verschiebbar wie jedes andere Feld — die Reihenfolge aus
     * dem Design-Tab entscheidet, nicht seine Stelle im Schlüsselsatz.
     */
    public function testTheShareButtonFollowsTheConfiguredOrder(): void
    {
        $order = ['share', 'media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description'];
        $xpath = $this->render('popup', $order, 'https://example.test/flyer.jpg', true);

        $children = $xpath->query('//*[@class="ctp-events__detail"]/*');
        $this->assertInstanceOf(DOMNodeList::class, $children);
        $this->assertInstanceOf(DOMElement::class, $children[0]);
        $this->assertStringContainsString('ctp-events__share', $children[0]->getAttribute('class'));
    }

    public function detailContextProvider(): array
    {
        return ['popup' => ['popup'], 'page' => ['page']];
    }

    /**
     * Die Gegenprobe: Im Popup bleibt alles direktes Kind von
     * .ctp-events__detail. Dort ist der Platz zu knapp für zwei Spalten, und
     * die Reihenfolge aus dem Design-Tab gilt dort ohne Einschränkung.
     */
    public function testThePopupStaysFlat(): void
    {
        $xpath = $this->render('popup');

        $this->assertCount(0, $xpath->query('//*[contains(@class, "ctp-events__detail-text")]'));
        $this->assertCount(
            1,
            $xpath->query('//*[contains(@class, "ctp-events__detail")]/span[@class="ctp-events__eyebrow"]')
        );
    }

    /**
     * Auf der eigenen Seite ist der Termin der Gegenstand der Seite, im Popup
     * ein Ausschnitt aus einer Seite, die schon eine Überschrift hat.
     */
    public function testTheTitleIsAnH1OnThePageAndStaysAnH2InThePopup(): void
    {
        $this->assertCount(1, $this->render('page')->query('//h1[@class="ctp-events__detail-title"]'));
        $this->assertCount(0, $this->render('page')->query('//h2'));

        $this->assertCount(1, $this->render('popup')->query('//h2[@class="ctp-events__detail-title"]'));
        $this->assertCount(0, $this->render('popup')->query('//h1'));
    }

    /**
     * Der Fehler, den 1.4.0 ausgeliefert hat, als Test: Der Betreiber hatte das
     * Kalender-Etikett im Design-Tab ganz nach hinten gezogen
     * (`detail_element_order` endete auf „description, calendar"), und auf der
     * eigenen Seite stand es trotzdem zwischen Titel und Datum. Ursache war
     * eine Sortierung nach Art — „Kopf" gegen „Eckdaten" —, die die
     * eingestellte Reihenfolge stillschweigend überstimmte. Mit der
     * Standardreihenfolge wäre das nie aufgefallen: Dort steht das Etikett
     * ohnehin vorne.
     */
    public function testTheConfiguredOrderSurvivesInsideTheColumn(): void
    {
        $xpath = $this->render('page', ['media', 'title', 'subtitle', 'date', 'time', 'location', 'description', 'calendar']);

        $this->assertSame(
            [
                'ctp-events__detail-heading',
                'ctp-events__subtitle',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
                'ctp-events__meta-item',
                'ctp-events__eyebrow',
            ],
            $this->childClasses($xpath, 'ctp-events__detail-text'),
            'Ein ans Ende gezogenes Kalender-Etikett muss auch am Ende stehen.'
        );
    }

    /**
     * Die Gegenprobe in die andere Richtung — eine Reihenfolge, in der die
     * Eckdaten den Kopf durchsetzen. Auch das ist eine Angabe des Betreibers
     * und keine, die das Layout zu glätten hat.
     */
    public function testEvenAnInterleavedOrderIsReproduced(): void
    {
        $xpath = $this->render('page', ['media', 'location', 'title', 'time', 'calendar', 'date', 'subtitle', 'description']);

        $this->assertSame(
            [
                'ctp-events__meta-item ctp-events__meta-item--location',
                'ctp-events__detail-heading',
                'ctp-events__meta-item ctp-events__meta-item--time',
                'ctp-events__eyebrow',
                'ctp-events__meta-item ctp-events__meta-item--date',
                'ctp-events__subtitle',
            ],
            $this->childClasses($xpath, 'ctp-events__detail-text', true)
        );
    }

    /**
     * Ohne Bild wäre die zweite Spalte ein breiter leerer Streifen rechts —
     * das Raster kann das nicht selbst merken, nur das Partial weiß es.
     */
    public function testAnEventWithoutAnImageSwitchesTheSecondColumnOff(): void
    {
        $withImage = $this->render('page')->query('//*[contains(@class, "ctp-events__detail")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $withImage);
        $this->assertStringNotContainsString('--no-media', $withImage->getAttribute('class'));

        $withoutImage = $this->render('page', null, '')->query('//*[contains(@class, "ctp-events__detail")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $withoutImage);
        $this->assertStringContainsString('ctp-events__detail--no-media', $withoutImage->getAttribute('class'));
    }

    /**
     * Das Gegenstück im Stylesheet. Die beiden Hüllen sind sonst reine
     * Markup-Namen, die niemand vermisst, wenn ihre Regeln beim Aufräumen
     * verschwinden.
     */
    public function testTheStylesheetLaysOutTheTwoColumns(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');

        $this->assertStringContainsString('.ctp-events--detail .ctp-events__detail > .ctp-events__detail-text', $css);
        $this->assertStringContainsString('.ctp-events--detail .ctp-events__detail > .ctp-events__detail-media', $css);
        // Ohne Bild bleibt es bei einer Spalte — sonst stuende rechts ein
        // breiter leerer Streifen.
        $this->assertStringContainsString(':not(.ctp-events__detail--no-media)', $css);
        // Die Neutralisierung der Kachel-`order`-Variablen eine Ebene tiefer:
        // ohne sie sortiert die Kachelreihenfolge die Felder in den Hüllen ein
        // zweites Mal um (siehe .ctp-events__detail > * weiter oben).
        $this->assertStringContainsString('.ctp-events .ctp-events__detail-text > *', $css);
    }

    /**
     * Die Zweispaltigkeit hängt an der Breite *dieses Blocks*, nicht an der des
     * Fensters — und das ist keine Feinheit. Als Inhalt einer Seite steckt die
     * Terminseite im Inhaltsbereich des Themes, und der ist bei manchen Themes
     * 650px breit, auch auf einem 1600px-Bildschirm. Eine Fensterabfrage hätte
     * dort zwei Spalten aufgemacht, wo keine hinpassen.
     *
     * Dazu die Richtung: Eine Spalte ist der Ausgangszustand, zwei sind die
     * Ausnahme. In 1.4.0 war es umgekehrt, und die Rücknahme in der
     * Medienabfrage wog eine Klasse weniger als die Regel, die sie zurücknehmen
     * sollte — auf dem Telefon stand die Seite deshalb weiter zweispaltig.
     */
    public function testTheTwoColumnLayoutHangsOnTheContainerNotTheViewport(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');

        $this->assertStringContainsString('container-name: ctp-detail;', $css);
        $this->assertStringContainsString('@container ctp-detail (min-width:', $css);

        $start = strpos($css, '@container ctp-detail (min-width:');
        $this->assertIsInt($start);
        $block = substr($css, $start, (int) strpos($css, "\n}", $start) - $start);

        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) 1.05fr;', $block, 'Die zweite Spalte darf nur innerhalb der Container-Abfrage entstehen.');
        $this->assertStringContainsString('grid-column: 2;', $block, 'Und die Platzierung des Bildes ebenso.');
    }

    /**
     * @param string[]|null $order Ohne „description": deren Aufbereitung läuft
     *                             über wpautop()/make_clickable()/wp_kses_post(),
     *                             die diese Testumgebung bewusst nicht nachbaut
     *                             (siehe DetailPageDesignTest).
     */
    private function render(
        string $detailContext,
        ?array $order = null,
        string $imageUrl = 'https://example.test/flyer.jpg',
        bool $shareEnabled = false
    ): DOMXPath {
        $event = [
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'subtitle' => 'mit Kinderprogramm',
            'location' => 'Gemeindehaus',
            'description' => '',
            'start_date' => '2026-09-06 10:00:00',
            'end_date' => '2026-09-06 11:30:00',
            'all_day' => 0,
            'calendar_name' => 'Gottesdienste',
            'calendar_color' => '#006d8f',
            'image_url' => $imageUrl,
            'image_is_fallback' => false,
            'detail_url' => 'https://example.test/termin/gottesdienst',
        ];
        $order = array_values(array_diff($order ?? DetailDesign::DEFAULT_ORDER, ['description']));

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-content.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    /**
     * @return string[] Die erste Klasse jedes Kindes — bzw. mit $full die
     *                  vollständige Klassenliste, wo der Modifier zählt.
     */
    private function childClasses(DOMXPath $xpath, string $wrapper, bool $full = false): array
    {
        $children = $xpath->query('//*[@class="' . $wrapper . '"]/*');
        $this->assertInstanceOf(DOMNodeList::class, $children);

        $classes = [];
        foreach ($children as $child) {
            $this->assertInstanceOf(DOMElement::class, $child);
            $class = $child->getAttribute('class');
            $classes[] = $full ? $class : strtok($class, ' ');
        }

        return $classes;
    }
}
