<?php

declare(strict_types=1);

namespace {
    if (!function_exists('vc_map')) {
        /** Faengt die Element-Definitionen ein, statt sie an WPBakery zu geben. */
        function vc_map(array $settings): void
        {
            $GLOBALS['ctp_test_vc_map'][$settings['base']] = $settings;
        }
    }

    if (!function_exists('shortcode_atts')) {
        /** Wie in WordPress: nur bekannte Attribute, sonst der Standardwert. */
        function shortcode_atts(array $pairs, $atts, string $shortcode = ''): array
        {
            $atts = (array) $atts;
            $out = [];
            foreach ($pairs as $name => $default) {
                $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
            }

            return $out;
        }
    }
}

namespace ChurchToolsPlugin\Tests\Integrations {
    use ChurchToolsPlugin\Frontend\Shortcode;
    use ChurchToolsPlugin\Groups\GroupSettings;
    use ChurchToolsPlugin\Groups\GroupSync;
    use ChurchToolsPlugin\Integrations\WpBakeryIntegration;
    use PHPUnit\Framework\TestCase;

    /**
     * Der Gruppenfinder im WPBakery-Element „ChurchTools Gruppen": ein
     * Ankreuzfeld, das `finder="1"` in den Shortcode schreibt - nur im Raster.
     * Ein echtes WPBakery laeuft in den Tests nicht; geprueft wird die
     * Definition, die das Element an vc_map() gibt, und dass der Shortcode,
     * den WPBakery daraus speichert, den Finder zeigt.
     */
    final class WpBakeryGroupFinderTest extends TestCase
    {
        protected function setUp(): void
        {
            ctp_test_reset_options();
            ctp_test_reset_attachments();
            $GLOBALS['ctp_test_vc_map'] = [];
        }

        public function testTheElementOffersTheFinderInTheGridOnly(): void
        {
            (new WpBakeryIntegration())->mapShortcode();

            $params = array_column($GLOBALS['ctp_test_vc_map']['ctp_groups']['params'], null, 'param_name');

            $this->assertArrayHasKey('finder', $params);
            $this->assertSame('checkbox', $params['finder']['type']);
            $this->assertSame('Gruppenfinder anzeigen', $params['finder']['heading']);
            $this->assertSame(['Anzeigen' => '1'], $params['finder']['value']);
            $this->assertSame(['element' => 'layout', 'value' => 'grid'], $params['finder']['dependency']);
        }

        /** Im Baustein steht „Ja" wie bei den Ankreuzfeldern des Termin-Elements, nicht „1". */
        public function testTheElementLabelReadsAsText(): void
        {
            (new WpBakeryIntegration())->mapShortcode();
            $settings = $GLOBALS['ctp_test_vc_map']['ctp_groups'];
            $param = array_column($settings['params'], null, 'param_name')['finder'];

            $this->assertSame('Ja', (new WpBakeryIntegration())->adminLabelValue('1', $param, $settings));
        }

        /** So speichert WPBakery das angekreuzte Feld - und so kommt der Finder an. */
        /**
         * `finder` heisst bei beiden Shortcodes gleich; bei den Terminen gilt
         * der aeltere Name `eventfinder` weiter.
         */
        public function testEventsAcceptFinderLikeGroupsAndKeepEventfinder(): void
        {
            $this->assertTrue(Shortcode::finderEnabled(['finder' => '1', 'eventfinder' => '0']));
            $this->assertTrue(Shortcode::finderEnabled(['finder' => '0', 'eventfinder' => '1']));
            $this->assertFalse(Shortcode::finderEnabled(['finder' => '0', 'eventfinder' => '0']));
        }

        public function testTheSavedShortcodeShowsTheFinder(): void
        {
            ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
                9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            ]]);
            ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['fetched' => '', 'empty_runs' => 0, 'filters' => ['weekday'], 'groups' => [
                ['id' => 1, 'name' => 'Chor', 'note' => '', 'image_url' => '', 'weekday' => 'Montag', 'meeting_time' => '', 'target_group' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => '#'],
                ['id' => 2, 'name' => 'Hauskreis', 'note' => '', 'image_url' => '', 'weekday' => 'Freitag', 'meeting_time' => '', 'target_group' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => '#'],
            ]]]);

            $shortcode = new Shortcode();
            $with = $shortcode->renderGroups(['source' => 'homepage', 'homepage' => 'Kleingruppen', 'finder' => '1']);
            $without = $shortcode->renderGroups(['source' => 'homepage', 'homepage' => 'Kleingruppen']);

            $this->assertStringContainsString('ctp-groups__finder', $with);
            $this->assertStringContainsString('data-ctp-group-value="Freitag"', $with);
            $this->assertStringNotContainsString('ctp-groups__finder', $without);
        }
    }
}
