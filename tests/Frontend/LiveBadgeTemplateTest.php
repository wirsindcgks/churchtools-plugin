<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\ReturnAnchor;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Dass das Kennzeichen „läuft gerade" in *jeder* Ansicht steht, sichert keine
 * Klasse ab: Es ist fünfmal dieselbe Zeile in fünf Templates, und eine
 * vergessene fällt nicht auf. Sie fällt sogar besonders schlecht auf - die
 * Pille ist im Quelltext ohnehin verborgen und wird erst vom Skript an der Uhr
 * eingeblendet. Eine fehlende Ansicht sähe schlicht aus wie „gerade läuft
 * nichts".
 *
 * Deshalb hier: dieselben Templates, derselbe Weg wie in
 * EventListRenderer::render(), und einmal quer über alle Layouts nachgezählt.
 */
final class LiveBadgeTemplateTest extends TestCase
{
    private const LAYOUTS = ['list', 'grid', 'upcoming'];

    protected function setUp(): void
    {
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
        ReturnAnchor::reset();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public static function layoutProvider(): array
    {
        return array_combine(self::LAYOUTS, array_map(static fn (string $l): array => [$l], self::LAYOUTS));
    }

    /**
     * Je Termin genau eine Pille - auch im Layout „upcoming", das seinen
     * ersten Termin als Hero und die übrigen als Liste baut und die beiden
     * Zweige deshalb getrennt bedienen muss.
     *
     * @dataProvider layoutProvider
     */
    public function testEveryEventInEveryLayoutCarriesTheBadge(string $layout): void
    {
        $xpath = $this->renderLayout($layout, 'Jetzt');

        $this->assertCount(
            3,
            iterator_to_array($xpath->query($this->badgeQuery())),
            "{$layout}: nicht jeder Termin trägt das Kennzeichen"
        );
    }

    /**
     * Ohne Skript bleibt die Pille verborgen. Das ist der eigentliche Schutz
     * dieser Funktion: Kommt frontend.js nicht an (abgeschaltet, Ladeblocker,
     * Fehler weiter oben), sieht die Kachel aus wie vorher, statt an jedem
     * Termin „läuft gerade" zu behaupten.
     *
     * @dataProvider layoutProvider
     */
    public function testBadgeShipsHidden(string $layout): void
    {
        $xpath = $this->renderLayout($layout, 'Jetzt');

        foreach ($xpath->query($this->badgeQuery()) as $badge) {
            $this->assertTrue($badge->hasAttribute('hidden'), "{$layout}: Pille ohne hidden");
            $this->assertNotSame('', $badge->getAttribute('data-ctp-live-start'), "{$layout}: kein Beginn");
            $this->assertNotSame('', $badge->getAttribute('data-ctp-live-end'), "{$layout}: kein Ende");
        }
    }

    /**
     * Das leere Feld ist der Ausschalter (siehe SettingsPage::renderLiveLabelField()) -
     * dann steht weder Pille noch Zeitstempel im Quelltext.
     *
     * @dataProvider layoutProvider
     */
    public function testEmptyLabelRemovesTheBadgeEverywhere(string $layout): void
    {
        $xpath = $this->renderLayout($layout, '');

        $this->assertCount(0, iterator_to_array($xpath->query($this->badgeQuery())), $layout);
    }

    /**
     * Ein Theme darf jedes dieser Templates überschreiben, und eine Kopie aus
     * der Zeit vor dieser Fassung reicht `live_label` nicht durch. Das darf
     * die Seite nicht mit einem PHP-Fehler beenden - dann eben ohne
     * Kennzeichen.
     *
     * @dataProvider layoutProvider
     */
    public function testLayoutSurvivesArgsWithoutTheSetting(string $layout): void
    {
        $xpath = $this->renderLayout($layout, null);

        $this->assertCount(0, iterator_to_array($xpath->query($this->badgeQuery())), $layout);
        // Die Termine stehen trotzdem da - geprüft wird, dass nichts abbricht.
        $this->assertGreaterThan(0, $xpath->query("//*[contains(text(), 'Alpha')]")->length, $layout);
    }

    public function testDetailViewCarriesTheBadge(): void
    {
        $xpath = $this->renderDetail('Jetzt');

        $this->assertCount(1, iterator_to_array($xpath->query($this->badgeQuery())));
    }

    public function testDetailViewWithoutLabelHasNoBadge(): void
    {
        $this->assertCount(0, iterator_to_array($this->renderDetail('')->query($this->badgeQuery())));
    }

    /**
     * Derselbe Rückfall wie oben, für den Fall, dass fremder Code das Partial
     * direkt einbindet - es wird in seinem eigenen Docblock als
     * überschreibbar geführt.
     */
    public function testDetailViewSurvivesAMissingLabelVariable(): void
    {
        $this->assertCount(0, iterator_to_array($this->renderDetail(null)->query($this->badgeQuery())));
    }

    private function badgeQuery(): string
    {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__badge--live ')]";
    }

    /** @param string|null $label null heisst: Schluessel gar nicht erst setzen. */
    private function renderLayout(string $layout, ?string $label): DOMXPath
    {
        $events = $this->events();
        $args = [
            'layout' => $layout,
            'columns' => 3,
            'click_behavior' => 'none',
            'hidden_elements' => [],
            'design_style' => '',
            'design_class' => '',
            'design_separators' => '',
            'month_dividers' => false,
            'eventfinder' => false,
            'show_toolbar' => false,
            'paging' => false,
        ];

        if ($label !== null) {
            $args['live_label'] = $label;
        }

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-' . $layout . '.php';

        return $this->toXPath((string) ob_get_clean());
    }

    /** @param string|null $label null heisst: Variable gar nicht erst setzen. */
    private function renderDetail(?string $label): DOMXPath
    {
        $event = $this->events()[0];
        $detailContext = 'page';
        // Ohne „description": deren Aufbereitung laeuft ueber Kernfunktionen,
        // die diese Testumgebung bewusst nicht nachbaut (siehe DetailLayoutTest).
        $order = array_values(array_diff(DetailDesign::DEFAULT_ORDER, ['description']));

        if ($label !== null) {
            $liveLabel = $label;
        }

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-content.php';

        return $this->toXPath((string) ob_get_clean());
    }

    private function toXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    /** Drei Termine, damit „upcoming" Hero *und* Liste darunter hat. */
    private function events(): array
    {
        return array_map(static function (string $title, int $day): array {
            return [
                'id' => 200 + $day,
                'ct_calendar_id' => 7,
                'title' => $title,
                'subtitle' => '',
                'location' => 'Halle',
                'description' => '',
                'start_date' => sprintf('2026-09-%02d 19:30:00', $day),
                'end_date' => sprintf('2026-09-%02d 21:00:00', $day),
                'all_day' => 0,
                'calendar_name' => 'Gottesdienst',
                'calendar_color' => '#006d8f',
                'image_url' => '',
                'image_is_fallback' => false,
                'detail_url' => 'https://example.test/termin/' . strtolower($title),
                'detail_html' => '',
            ];
        }, ['Alpha', 'Beta', 'Gamma'], [3, 10, 17]);
    }
}
