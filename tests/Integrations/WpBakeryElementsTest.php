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
    use ChurchToolsPlugin\Settings;
    use PHPUnit\Framework\TestCase;

    /**
     * Die WPBakery-Elemente „ChurchTools Events" und „ChurchTools Gruppen" -
     * einheitlich aufgebaut (Nutzerwunsch 2026-09-15). Ein echtes WPBakery
     * laeuft in den Tests nicht; geprueft wird die Definition, die die
     * Elemente an vc_map() geben, die Ausgabe der Auswahlfelder und dass der
     * Shortcode, den WPBakery daraus speichert, das Erwartete zeigt.
     */
    final class WpBakeryElementsTest extends TestCase
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

        /** Beide Elemente mit denselben Reitern wie die Bereiche der Bloecke. */
        public function testBothElementsUseTheTabsOfTheBlocks(): void
        {
            (new WpBakeryIntegration())->mapShortcode();

            $tabs = static fn (string $base): array => array_column($GLOBALS['ctp_test_vc_map'][$base]['params'], 'group', 'param_name');

            $this->assertSame('Auswahl', $tabs('ctp_events')['calendar']);
            $this->assertSame('Darstellung', $tabs('ctp_events')['layout']);
            $this->assertSame('Darstellung', $tabs('ctp_events')['search']);
            $this->assertSame(['Auswahl'], array_values(array_unique(array_intersect_key($tabs('ctp_groups'), array_flip(['source', 'homepage', 'groups'])))));
            $this->assertSame('Darstellung', $tabs('ctp_groups')['finder']);
            $this->assertSame(['Auswahl', 'Darstellung'], array_values(array_unique(array_merge(array_values($tabs('ctp_events')), array_values($tabs('ctp_groups'))))));
        }

        /** Die Kalender kommen in dieselbe Auswahlliste wie die Gruppen, statt als Textfeld mit IDs. */
        public function testCalendarsArePickedLikeGroups(): void
        {
            ctp_test_set_option(Settings::OPTION_KEY, ['calendars' => [
                3 => ['name' => 'Gottesdienste', 'color' => '', 'enabled' => true],
                7 => ['name' => 'Jugend', 'color' => '', 'enabled' => true],
            ]]);
            (new WpBakeryIntegration())->mapShortcode();

            $param = array_column($GLOBALS['ctp_test_vc_map']['ctp_events']['params'], null, 'param_name')['calendar'];

            $this->assertSame(WpBakeryIntegration::CALENDAR_PICKER_TYPE, $param['type']);
            $this->assertSame(['Gottesdienste' => '3', 'Jugend' => '7'], $param['ctp_choices']);
            $this->assertSame('Gottesdienste, Jugend', (new WpBakeryIntegration())->adminLabelValue('3,7', $param, $GLOBALS['ctp_test_vc_map']['ctp_events']));
        }

        /**
         * Von Hand geschriebene Shortcodes nennen Kalender beim Namen. Ein
         * bekannter Name wird zur ID; ein unbekannter bleibt stehen und wird
         * mitgespeichert, statt beim ersten Speichern zu verschwinden.
         */
        public function testCalendarNamesAreRecognisedAndUnknownOnesKept(): void
        {
            ctp_test_set_option(Settings::OPTION_KEY, ['calendars' => [
                3 => ['name' => 'Gottesdienste', 'color' => '', 'enabled' => true],
                7 => ['name' => 'Jugend', 'color' => '', 'enabled' => true],
            ]]);

            $html = WpBakeryIntegration::renderCalendarPicker(['param_name' => 'calendar'], 'gottesdienste, Chor, 99');

            $this->assertStringContainsString('class="wpb_vc_param_value calendar ctp_calendar_picker_field" value="3,99,Chor"', $html);
            $this->assertStringContainsString('value="3" data-name="Gottesdienste" checked="checked"', $html);
            $this->assertStringContainsString('value="7" data-name="Jugend" />', $html);
            $this->assertStringContainsString('Chor (nicht gefunden)', $html);
            $this->assertStringContainsString('data-extra="Chor"', $html);
            $this->assertStringContainsString('#99 (nicht mehr verfügbar)', $html);
            $this->assertStringContainsString('ctp-wpb-picker--unordered', $html, 'Termine stehen nach Datum - keine Reihenfolge, keine Pfeile.');
        }

        public function testAnEmptyCalendarPickerMeansAllCalendars(): void
        {
            $html = WpBakeryIntegration::renderCalendarPicker(['param_name' => 'calendar'], '');

            $this->assertStringContainsString('value="" />', $html);
            $this->assertStringContainsString('Noch keine Kalender geladen.', $html);
            $this->assertStringNotContainsString('ctp-wpb-picker__empty" hidden', $html);
        }

        /** Die Gruppenauswahl behaelt ihre Reihenfolge. */
        public function testTheGroupPickerStaysOrdered(): void
        {
            $this->assertStringNotContainsString('ctp-wpb-picker--unordered', WpBakeryIntegration::renderGroupPicker(['param_name' => 'groups'], ''));
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
