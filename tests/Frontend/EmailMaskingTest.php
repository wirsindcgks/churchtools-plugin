<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\DetailSeo;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\EventSchema;
use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Frontend\ReturnAnchor;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use PHPUnit\Framework\TestCase;

/**
 * Keine E-Mail-Adresse aus ChurchTools im Klartext des Quelltexts (2026-09-18).
 *
 * Anlass war ein Untertitel wie „Infos unter: gebet@cg-ks.de": Er stand ueber
 * esc_html() offen auf Kachel, Popup und Terminseite und im Suchattribut der
 * Kachel - Adresssammler lesen genau das. Verschleiert wird mit antispambot()
 * (EventFormatter::maskEmails()), im JSON-LD mit \u0040. Jeder Test prueft
 * beide Seiten: roh keine Adresse, dekodiert unveraendert dieselbe - Besucher
 * und die Suche im Browser sehen also, was sie vorher sahen.
 */
final class EmailMaskingTest extends TestCase
{
    private const ADDRESS = 'gebet@cg-ks.de';

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_hooks();
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
        ctp_test_set_option('blogname', 'Musterkirche');
        ReturnAnchor::reset();
        DetailSeo::reset();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_hooks();
        DetailSeo::reset();
    }

    public function testMaskEmailsHidesOnlyTheAddress(): void
    {
        $masked = EventFormatter::safeText('Infos unter: ' . self::ADDRESS . ' & mehr');

        $this->assertStringStartsWith('Infos unter: ', $masked);
        $this->assertStringEndsWith(' &amp; mehr', $masked);
        $this->assertStringNotContainsString('@', $masked);
        $this->assertSame('Infos unter: ' . self::ADDRESS . ' & mehr', html_entity_decode($masked, ENT_QUOTES));
    }

    /** @return array<string, array{string}> */
    public static function layoutProvider(): array
    {
        return ['list' => ['list'], 'grid' => ['grid'], 'upcoming' => ['upcoming']];
    }

    /**
     * Untertitel, Auszug und Suchattribut in jeder Ansicht, dazu die Terminseite
     * bzw. das Popup (partials/event-detail-element.php).
     *
     * @dataProvider layoutProvider
     */
    public function testNoAddressInTheEventLayouts(string $layout): void
    {
        $html = $this->renderEvents($layout);

        $this->assertStringNotContainsString(self::ADDRESS, $html);
        $this->assertStringContainsString('Infos unter: ' . self::ADDRESS, html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('Anmeldung bei ' . self::ADDRESS, html_entity_decode($html, ENT_QUOTES));
    }

    public function testNoAddressInTheEventDetail(): void
    {
        $event = $this->event();
        $detailContext = 'page';

        ob_start();
        foreach (DetailDesign::ELEMENT_KEYS as $key) {
            require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-element.php';
        }
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ctp-events__subtitle', $html);

        $this->assertStringNotContainsString(self::ADDRESS, $html);
        $this->assertStringContainsString('Infos unter: ' . self::ADDRESS, html_entity_decode($html, ENT_QUOTES));
    }

    /** Das Suchattribut des Gruppenfinders traegt den ganzen Beschreibungstext. */
    public function testNoAddressInTheGroupFinderSearchAttribute(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['fetched' => '', 'empty_runs' => 0, 'groups' => [[
            'id' => 1, 'name' => 'Jugend', 'note' => 'Fragen an ' . self::ADDRESS, 'image_url' => '',
            'weekday' => 'Freitag', 'meeting_time' => '19:00', 'target_group' => 'Jeder', 'target_group_key' => 'everyone',
            'category' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/1',
        ]]]]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'search' => true]);

        $this->assertStringContainsString('data-ctp-group-search="', $html);
        $this->assertStringNotContainsString(self::ADDRESS, $html);
        $this->assertStringContainsString('fragen an ' . self::ADDRESS, html_entity_decode($html, ENT_QUOTES));
    }

    /** JSON-LD liest keine Entities - dort steht das @ als \u0040. */
    public function testNoAddressInTheJsonLd(): void
    {
        $script = EventSchema::detailScript($this->event(['description' => '']));
        $json = (string) preg_replace('#^<script type="application/ld\+json">(.*)</script>$#s', '$1', $script);
        $data = json_decode($json, true);

        $this->assertStringNotContainsString(self::ADDRESS, $script);
        $this->assertStringContainsString('"@context"', $script, 'Die Schluessel mit @ bleiben, wie sie sind.');
        $this->assertSame('Infos unter: ' . self::ADDRESS, $data['description']);
    }

    public function testNoAddressInTheMetaDescription(): void
    {
        DetailSeo::registerForEvent($this->event(['description' => '']));

        ob_start();
        DetailSeo::renderMetaTags();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringNotContainsString(self::ADDRESS, $html);
        $this->assertStringContainsString('Infos unter: ' . self::ADDRESS, html_entity_decode($html, ENT_QUOTES));
    }

    private function renderEvents(string $layout): string
    {
        $events = [$this->event(), $this->event(['id' => 102, 'start_date' => '2026-10-15 19:30:00', 'end_date' => '2026-10-15 21:00:00'])];
        $args = [
            'layout' => $layout,
            'columns' => 3,
            'click_behavior' => 'popup',
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

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'id' => 101,
            'ct_calendar_id' => 53,
            'title' => 'Encounter God',
            'subtitle' => 'Infos unter: ' . self::ADDRESS,
            'description' => 'Anmeldung bei ' . self::ADDRESS,
            'location' => 'Jugendraum',
            'start_date' => '2026-10-01 19:30:00',
            'end_date' => '2026-10-01 21:00:00',
            'all_day' => 0,
            'calendar_name' => 'Gebet',
            'calendar_color' => '#006d8f',
            'image_url' => '',
            'image_is_fallback' => false,
            'detail_url' => 'https://example.test/events/encounter-god-01-10-2026/',
            'detail_html' => '',
        ], $overrides);
    }
}
