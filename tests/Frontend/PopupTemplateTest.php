<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The "popup" click behavior has one piece of markup contract that no PHP class
 * enforces: each event's <template class="ctp-events__detail-template"> has to be
 * *inside* the unit its trigger sits in, because openDetailModal() in
 * assets/js/frontend.js resolves it with
 * `trigger.closest('li, .ctp-events__hero').querySelector('template…')`.
 *
 * Nothing complained when the "upcoming" hero's template sat next to the hero
 * instead of inside it — the markup was valid, the button rendered, and clicking
 * it simply did nothing, from 0.2.0 until it was noticed. So this renders the
 * three bundled layout templates and walks the same path the browser walks.
 */
final class PopupTemplateTest extends TestCase
{
    private const LAYOUTS = ['list', 'grid', 'upcoming'];

    protected function setUp(): void
    {
        // EventFormatter's date/time lines read both through get_option().
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
        // ReturnAnchor vergibt jede id nur einmal pro Request. Hier rendert ein
        // Prozess mehrere „Seiten" nacheinander, also je Test zurücksetzen —
        // sonst bekäme nur das erste Layout seine Sprungziele.
        \ChurchToolsPlugin\Frontend\ReturnAnchor::reset();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    /**
     * Der „Zurück"-Knopf der Detailseite zeigt auf `#ctp-event-<id>` und
     * erwartet dort die Kachel, aus der er geöffnet wurde
     * (EventListRenderer::renderDetail()). Das ist ein Vertrag zwischen zwei
     * Dateien, die nichts voneinander wissen: Fällt die id aus dem Markup,
     * bleibt der Knopf funktionsfähig und springt nur wieder an den
     * Seitenanfang — also genau der Fehler, der behoben werden sollte, ohne
     * dass irgendetwas kaputt aussieht.
     *
     * @dataProvider layoutProvider
     */
    public function testEveryCardCarriesItsEventIdAsAnAnchorTarget(string $layout): void
    {
        $dom = $this->render($layout, 'page');

        foreach ($this->events() as $event) {
            $id = 'ctp-event-' . $event['id'];
            $this->assertCount(
                1,
                $this->query($dom, "//*[@id='{$id}']"),
                "{$layout}: „{$event['title']}\" hat kein Sprungziel #{$id}"
            );
        }
    }

    /**
     * @dataProvider layoutProvider
     */
    public function testEveryPopupTriggerReachesItsOwnDetailTemplate(string $layout): void
    {
        $dom = $this->render($layout, 'popup');
        $triggers = $this->query($dom, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__card-trigger ')]");

        $this->assertCount(3, $triggers, "{$layout}: one trigger per event");

        foreach ($triggers as $trigger) {
            $title = trim($trigger->textContent);
            $unit = $this->clickedUnit($trigger);

            $this->assertNotNull($unit, "{$layout}: trigger for \"{$title}\" sits in no Eintrag/Hero-Kachel");

            $templates = $this->query(
                $dom,
                ".//template[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__detail-template ')]",
                $unit
            );

            $this->assertCount(1, $templates, "{$layout}: \"{$title}\" has no detail template inside its unit");
            $this->assertSame(
                $title,
                $this->markerIn($templates[0]),
                "{$layout}: \"{$title}\" would open another event's detail"
            );
        }
    }

    /**
     * The bug this test exists for, named: the hero's own template used to be a
     * sibling of .ctp-events__hero, one level too far out for closest() to see.
     */
    public function testUpcomingHeroTriggerReachesItsTemplateFromInsideTheHero(): void
    {
        $dom = $this->render('upcoming', 'popup');
        $hero = $this->query($dom, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__hero ')]")[0] ?? null;

        $this->assertInstanceOf(DOMElement::class, $hero);

        $templates = $this->query(
            $dom,
            ".//template[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__detail-template ')]",
            $hero
        );

        $this->assertCount(1, $templates);
        $this->assertSame('Alpha', $this->markerIn($templates[0]), 'the hero carries the first event, not a later one');
    }

    /**
     * The counterpart: the detail markup is only worth embedding for the one
     * behavior that reads it from the page. "page" links out to the detail URL
     * and "none" has no trigger at all, so neither should ship a copy of every
     * event's description with the list.
     *
     * @dataProvider layoutProvider
     */
    public function testNoDetailTemplatesWithoutThePopupBehavior(string $layout): void
    {
        foreach (['none', 'page'] as $behavior) {
            $dom = $this->render($layout, $behavior);
            $templates = $this->query($dom, "//template[contains(concat(' ', normalize-space(@class), ' '), ' ctp-events__detail-template ')]");

            $this->assertCount(0, $templates, "{$layout}/{$behavior} embeds detail markup nobody reads");
        }
    }

    public function layoutProvider(): array
    {
        return array_combine(
            self::LAYOUTS,
            array_map(static fn (string $layout): array => [$layout], self::LAYOUTS)
        );
    }

    /**
     * Renders one bundled layout template the way EventListRenderer::render()
     * does — same variables, same include — with the toolbar and paging turned
     * off, since neither has anything to do with the click behavior.
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

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-' . $layout . '.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // <template>, <dialog> and <article> are all past libxml's HTML
        // vocabulary; it keeps them as generic elements with their nesting
        // intact, which is the only thing asserted on here.
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Three events, enough for "upcoming" to have both a hero and a tail. Each
     * one's detail markup carries its title as a marker, so a template can be
     * traced back to the event it belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return array_map(static function (string $title, int $day): array {
            return [
                // Wie eine echte Zeile aus ctp_events: Die Kacheln tragen die
                // ID als Sprungziel des „Zurück"-Knopfes (siehe
                // partials/event-list-items.php).
                'id' => 100 + $day,
                'ct_calendar_id' => 7,
                'title' => $title,
                'subtitle' => $title . ' subtitle',
                'location' => 'Halle',
                'description' => 'Description of ' . $title,
                'start_date' => sprintf('2026-09-%02d 19:30:00', $day),
                'end_date' => sprintf('2026-09-%02d 21:00:00', $day),
                'all_day' => 0,
                'calendar_name' => 'Gottesdienst',
                'calendar_color' => '#006d8f',
                'image_url' => 'https://example.test/' . strtolower($title) . '.webp',
                'image_is_fallback' => false,
                'detail_url' => 'https://example.test/termin/' . strtolower($title),
                'detail_html' => '<div class="ctp-events__detail" data-ctp-test-event="' . $title . '"></div>',
            ];
        }, ['Alpha', 'Beta', 'Gamma'], [3, 10, 17]);
    }

    /**
     * The element openDetailModal() would land on:
     * `trigger.closest('.ctp-events__item, .ctp-events__cell, .ctp-events__hero')`.
     */
    private function clickedUnit(DOMElement $trigger): ?DOMElement
    {
        $units = ['ctp-events__item', 'ctp-events__cell', 'ctp-events__hero'];

        for ($node = $trigger->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $classes = explode(' ', (string) $node->getAttribute('class'));

            if (array_intersect($units, $classes) !== []) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The title of the event a rendered detail template belongs to, from the
     * marker attribute the fixture stamped into its markup.
     */
    private function markerIn(DOMElement $template): ?string
    {
        $marked = $template->getElementsByTagName('*');

        foreach ($marked as $element) {
            if ($element->hasAttribute('data-ctp-test-event')) {
                return $element->getAttribute('data-ctp-test-event');
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function query(DOMDocument $dom, string $expression, ?DOMElement $context = null): array
    {
        $nodes = (new DOMXPath($dom))->query($expression, $context);
        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
