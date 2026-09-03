<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\ReturnAnchor;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Ob ein Termin für eine Suchmaschine überhaupt existiert, entscheidet ein
 * einziges Attribut: das href seiner Kachel.
 *
 * Bis 1.15.0 war der Auslöser in der Voreinstellung („Popup") ein <button>.
 * Ein Knopf hat kein Ziel, dem ein Crawler folgen könnte, und der
 * Detailinhalt daneben steht in einem <template>, dessen Inhalt kein Browser
 * rendert und keine Suchmaschine liest. Die Termine hatten damit längst eine
 * eigene Adresse — nur führte kein Verweis dorthin.
 *
 * Der Fehler wäre nach einer Änderung am Markup wieder still da: Die Seite
 * sähe unverändert aus, das Popup ginge auf, und erst Wochen später fiele auf,
 * dass kein einziger Termin in einer Trefferliste steht.
 */
final class EventLinkTest extends TestCase
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

    /**
     * @dataProvider layoutProvider
     */
    public function testEveryCardLinksToItsEventPageInBothClickBehaviors(string $layout): void
    {
        foreach (['popup', 'page'] as $behavior) {
            $triggers = $this->triggers($this->render($layout, $behavior));

            $this->assertCount(3, $triggers, "{$layout}/{$behavior}: ein Auslöser je Termin");

            foreach ($triggers as $trigger) {
                $this->assertSame('a', $trigger->tagName, "{$layout}/{$behavior}: kein Verweis, sondern ein Knopf");
                $this->assertMatchesRegularExpression(
                    '#^https://example\.test/termin/(alpha|beta|gamma)$#',
                    $trigger->getAttribute('href'),
                    "{$layout}/{$behavior}: der Verweis zeigt nicht auf die Terminseite"
                );
            }
        }
    }

    /**
     * Woran assets/js/frontend.js den Dialog erkennt. Ohne dieses Kennzeichen
     * verließe ein Klick im Popup-Betrieb die Seite — der Verweis führt ja
     * wirklich irgendwohin.
     *
     * @dataProvider layoutProvider
     */
    public function testOnlyThePopupBehaviorMarksItsLinksForTheDialog(string $layout): void
    {
        foreach ($this->triggers($this->render($layout, 'popup')) as $trigger) {
            $this->assertSame('1', $trigger->getAttribute('data-ctp-modal'));
        }

        foreach ($this->triggers($this->render($layout, 'page')) as $trigger) {
            $this->assertSame('', $trigger->getAttribute('data-ctp-modal'), 'die eigene Seite öffnet keinen Dialog');
        }
    }

    /**
     * „Nichts" bleibt nichts: Wer die Termine bewusst nicht anklickbar macht,
     * bekommt auch keine Verweise auf Terminseiten.
     *
     * @dataProvider layoutProvider
     */
    public function testTheClickBehaviorNoneStillRendersNoTrigger(string $layout): void
    {
        $this->assertCount(0, $this->triggers($this->render($layout, 'none')));
    }

    public function layoutProvider(): array
    {
        return array_combine(
            self::LAYOUTS,
            array_map(static fn (string $layout): array => [$layout], self::LAYOUTS)
        );
    }

    /**
     * @return DOMElement[]
     */
    private function triggers(DOMDocument $dom): array
    {
        $nodes = (new DOMXPath($dom))->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__card-trigger ')]"
        );

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Wie EventListRenderer::render() das Template einbindet — dieselben
     * Variablen, derselbe include (siehe PopupTemplateTest, das denselben Weg
     * für den <template>-Vertrag geht).
     */
    private function render(string $layout, string $clickBehavior): DOMDocument
    {
        $events = $this->events();
        $args = [
            'layout' => $layout,
            'columns' => 3,
            'click_behavior' => $clickBehavior,
            'hidden_elements' => [],
            'design_style' => '',
            'design_class' => '',
            'design_separators' => '',
            'month_dividers' => false,
            'eventfinder' => false,
            'show_toolbar' => false,
            'paging' => false,
        ];

        ReturnAnchor::reset();

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-' . $layout . '.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return array_map(static function (string $title, int $day): array {
            return [
                'id' => 100 + $day,
                'ct_calendar_id' => 7,
                'title' => $title,
                'subtitle' => '',
                'location' => 'Halle',
                'description' => 'Description of ' . $title,
                'start_date' => sprintf('2026-09-%02d 19:30:00', $day),
                'end_date' => sprintf('2026-09-%02d 21:00:00', $day),
                'all_day' => 0,
                'calendar_name' => 'Gottesdienst',
                'calendar_color' => '#006d8f',
                'image_url' => '',
                'image_is_fallback' => false,
                'detail_url' => 'https://example.test/termin/' . strtolower($title),
                'detail_html' => '<div class="ctp-events__detail"></div>',
            ];
        }, ['Alpha', 'Beta', 'Gamma'], [3, 10, 17]);
    }
}
