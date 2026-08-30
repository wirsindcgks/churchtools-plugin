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
        $this->assertStringContainsString('.ctp-events--detail .ctp-events__detail--no-media', $css);
        // Die Neutralisierung der Kachel-`order`-Variablen eine Ebene tiefer:
        // ohne sie sortiert die Kachelreihenfolge die Felder in den Hüllen ein
        // zweites Mal um (siehe .ctp-events__detail > * weiter oben).
        $this->assertStringContainsString('.ctp-events .ctp-events__detail-text > *', $css);
    }

    /**
     * Der Nachbau des Fehlers, der beim Bauen tatsächlich passiert ist: Unter
     * 900px nimmt das Layout die Platzierung der Kinder zurück, und dafür
     * muss jedes einzeln beim Namen genannt werden. Ein knappes `> *` sieht
     * gleichwertig aus, wiegt aber eine Klasse weniger als die
     * Desktop-Regeln — ein Media-Query erhöht die Spezifität nicht. Das Bild
     * behielt damit sein `grid-column: 2`, das Raster legte eine implizite
     * zweite Spalte an, und die Seite stand auf dem Telefon weiter
     * zweispaltig: mit einer 70px schmalen Textspalte neben dem Bild.
     */
    public function testTheMobileLayoutTakesBackEveryPlacedChildByName(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');
        $start = strpos($css, '@media (max-width: 900px)');
        $this->assertIsInt($start);

        $reset = substr($css, $start, (int) strpos($css, 'grid-row: auto;', $start) - $start);

        foreach (['.ctp-events__detail-text', '.ctp-events__detail-media', '.ctp-events__detail-description'] as $child) {
            $this->assertStringContainsString(
                '.ctp-events__detail > ' . $child,
                $reset,
                $child . ' muss unter 900px einzeln zurückgenommen werden.'
            );
        }
    }

    /**
     * @param string[]|null $order Ohne „description": deren Aufbereitung läuft
     *                             über wpautop()/make_clickable()/wp_kses_post(),
     *                             die diese Testumgebung bewusst nicht nachbaut
     *                             (siehe DetailPageDesignTest).
     */
    private function render(string $detailContext, ?array $order = null, string $imageUrl = 'https://example.test/flyer.jpg'): DOMXPath
    {
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
