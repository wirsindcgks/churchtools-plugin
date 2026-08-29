<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DetailDesign;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Die eigene Detailseite war bis 1.3.1 die einzige Ansicht ohne die
 * Design-Variablen aus dem Design-Tab: „Eckig" und eine global gesetzte
 * Akzentfarbe wirkten dort nicht, in Liste, Grid, „Nächster Termin" und sogar
 * im Popup daneben schon (das erbt sie vom Container der Liste). Aufgefallen
 * ist das erst beim Bau der Stil-Vorlagen, also über ein Jahr Versionen
 * später — nichts war *kaputt*, es fehlte nur eine Zeile, und ohne Test fällt
 * genau das wieder heraus.
 */
final class DetailPageDesignTest extends TestCase
{
    protected function setUp(): void
    {
        // EventFormatter liest Datums- und Zeitformat über get_option().
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public function testTheDetailPageCarriesTheDesignVariables(): void
    {
        $container = $this->renderContainer(
            CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'square', 'wide', '#ff0000')
        );

        $style = $container->getAttribute('style');

        $this->assertStringContainsString('--ctp-radius:0px', $style, '„Eckig" muss die Detailseite erreichen.');
        $this->assertStringContainsString('--ctp-radius-pill:0px', $style);
        $this->assertStringContainsString('--ctp-accent:#ff0000', $style);
    }

    /**
     * Die Gegenprobe zur Voreinstellung: „Rund" und keine gesetzte
     * Akzentfarbe geben gar nichts aus (siehe CardDesign::cssVariables()), das
     * Attribut trägt dann nur die Reihenfolge-Variablen und keinen Radius.
     */
    public function testTheDefaultSettingsEmitNoRadiusOverride(): void
    {
        $style = $this->renderContainer(
            CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'rounded')
        )->getAttribute('style');

        $this->assertStringNotContainsString('--ctp-radius', $style);
        $this->assertStringContainsString('--ctp-order-title', $style);
    }

    /**
     * Der Preset-Weg daneben: Die Vorlage kommt als Klasse, nicht als
     * Variable — die beiden dürfen sich nicht gegenseitig verdrängen, denn
     * genau aus ihrem Nebeneinander ergibt sich die Rangfolge (Inline-Style
     * schlägt Stylesheet-Klasse).
     */
    public function testPresetClassAndDesignVariablesCoexist(): void
    {
        $container = $this->renderContainer(
            CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'square'),
            'ctp-events--preset-warm'
        );

        $this->assertStringContainsString('ctp-events--preset-warm', $container->getAttribute('class'));
        $this->assertStringContainsString('--ctp-radius:0px', $container->getAttribute('style'));
    }

    /**
     * Rendert event-detail.php so, wie EventListRenderer::renderDetail() es
     * einbindet — dieselben Variablen, dasselbe include. Der Renderer selbst
     * bleibt außen vor, weil er mit locate_template()/wp_get_referer() an
     * WordPress-Funktionen hängt, die diese Testumgebung nicht nachbaut; was
     * hier geprüft wird, ist der Vertrag des Templates.
     */
    private function renderContainer(string $designStyle, string $designClass = ''): \DOMElement
    {
        $event = [
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'subtitle' => 'mit Kinderprogramm',
            'location' => 'Halle',
            'description' => 'Beschreibung',
            'start_date' => '2026-09-06 10:00:00',
            'end_date' => '2026-09-06 11:30:00',
            'all_day' => 0,
            'calendar_name' => 'Gottesdienst',
            'calendar_color' => '#006d8f',
            'image_url' => '',
            'image_is_fallback' => false,
            'detail_url' => 'https://example.test/termin/gottesdienst',
        ];
        // Alles außer der Beschreibung: Deren Aufbereitung läuft über
        // wpautop()/make_clickable()/wp_kses_post(), und die drei hier
        // nachzubauen hieße, einen gefälschten Filter in die Testumgebung zu
        // legen, dem später jemand vertraut. Geprüft wird ohnehin das
        // Container-Attribut, nicht die Beschreibung.
        $order = array_values(array_diff(DetailDesign::DEFAULT_ORDER, ['description']));
        $backUrl = 'https://example.test/termine';

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-detail.php';
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $container = (new DOMXPath($dom))
            ->query('//div[contains(@class, "ctp-events--detail")]')
            ->item(0);

        $this->assertInstanceOf(\DOMElement::class, $container);

        return $container;
    }
}
