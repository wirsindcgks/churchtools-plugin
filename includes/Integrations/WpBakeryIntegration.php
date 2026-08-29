<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Integrations;

final class WpBakeryIntegration
{
    /**
     * Shortcode-Tag, unter dem das Element bei WPBakery gemeldet ist. Steht
     * hier als Konstante, weil die CSS-Selektoren unten den Tag woertlich
     * enthalten muessen (WPBakery baut daraus die id des Kachel-Links).
     */
    private const BASE = 'ctp_events';

    /**
     * Klasse, unter der das Symbol des Elements haengt. Der "icon"-Wert von
     * vc_map() muss ein Klassenname sein, keine Bildadresse - siehe
     * enqueueElementIcon() fuer die Begruendung.
     */
    private const ICON_CLASS = 'ctp-vc-icon';

    /**
     * Verhindert, dass die Regel doppelt im Dokument landet, falls beide
     * Enqueue-Haken in derselben Anfrage feuern: wp_add_inline_style() haengt
     * bei jedem Aufruf an, wp_enqueue_style() nicht.
     */
    private bool $iconStyleAdded = false;

    public function register(): void
    {
        add_action('vc_before_init', [$this, 'mapShortcode']);

        // Backend-Editor und Frontend-Editor sind zwei getrennte Kontexte, das
        // Elementefenster gibt es in beiden.
        add_action('admin_enqueue_scripts', [$this, 'enqueueElementIcon']);
        add_action('vc_frontend_editor_enqueue_js_css', [$this, 'enqueueElementIcon']);
    }

    /**
     * Legt das Symbol des Elements per CSS fest.
     *
     * Warum ueberhaupt eigenes CSS, wo vc_map() laut Dokumentation auch eine
     * Bildadresse in "icon" akzeptiert: Im Elementefenster kommt eine solche
     * Adresse nie an. WPBakery baut die Kachel in
     * Vc_Add_Element_Box::getIcon(); dort landete der "icon"-Wert bis 6.x
     * ungeprueft im class-Attribut (aus einer URL werden dabei sinnlose
     * Klassennamen), und seit 8.4 wird ein Wert, den FILTER_VALIDATE_URL
     * durchlaesst, ersatzlos verworfen - uebrig bleibt
     * <i class="vc_general vc_element-icon">, also WPBakerys eigenes Logo aus
     * der Standardregel. Die Adresse wertet allein printIconStyles() aus, ein
     * zweiter, an admin_head haengender Pfad fuer die Element-Kachel *im
     * Seitenaufbau*. Genau das ist der Grund, warum das Symbol zweimal
     * "einfach nicht kam", obwohl die Datei jedes Mal erreichbar war.
     *
     * Die Selektorliste ist die aus printIconStyles(): sie deckt beide Orte
     * ab (Elementefenster und schematische Darstellung im Backend) und traegt
     * ueber die id des Kachel-Links genug Gewicht, um sich gegen WPBakerys
     * Standardregel und gegen Themes mit eigenem WPBakery-Zweig zu behaupten
     * - ohne !important, das in diesem Plugin nirgends vorkommt.
     */
    public function enqueueElementIcon(): void
    {
        if (!defined('WPB_VC_VERSION') || $this->iconStyleAdded) {
            return;
        }

        $handle = 'ctp-wpbakery-element-icon';

        // Kein eigenes Stylesheet fuer eine einzige Regel: false als Quelle ist
        // der von WordPress dafuer vorgesehene Weg fuer wp_add_inline_style().
        wp_register_style($handle, false, [], CTP_VERSION);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, sprintf(
            '.vc_element-icon.%1$s,'
                . '.vc_el-container #%2$s .vc_element-icon,'
                . '.vc_el-container > #%2$s > .vc_element-icon,'
                . '.wpb_%2$s > .wpb_element_wrapper > .wpb_element_title > .vc_element-icon,'
                . '.vc_helper.vc_helper-%2$s > .vc_element-icon'
                . '{background-image:url("%3$s");background-position:center;'
                . 'background-repeat:no-repeat;background-size:contain;}',
            self::ICON_CLASS,
            self::BASE,
            esc_url(CTP_PLUGIN_URL . 'assets/img/wpbakery-element-icon.svg')
        ));

        $this->iconStyleAdded = true;
    }

    /**
     * Maps the same `ctp_events` shortcode (registered in Frontend\Shortcode)
     * into the WPBakery element panel, so both share one rendering path.
     */
    public function mapShortcode(): void
    {
        if (!function_exists('vc_map')) {
            return;
        }

        vc_map([
            'name' => __('ChurchTools Events', 'churchtools-plugin'),
            'base' => self::BASE,
            'category' => __('ChurchTools', 'churchtools-plugin'),
            // Klassenname, keine Bildadresse - warum, steht in
            // enqueueElementIcon().
            'icon' => self::ICON_CLASS,
            'params' => [
                [
                    'type' => 'textfield',
                    'heading' => __('Kalender-IDs (kommagetrennt)', 'churchtools-plugin'),
                    'param_name' => 'calendar',
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Ansicht', 'churchtools-plugin'),
                    'param_name' => 'layout',
                    'value' => [
                        __('Liste', 'churchtools-plugin') => 'list',
                        __('Grid', 'churchtools-plugin') => 'grid',
                        __('Nächster Termin', 'churchtools-plugin') => 'upcoming',
                    ],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Spalten (nur Grid)', 'churchtools-plugin'),
                    'param_name' => 'columns',
                    'value' => '3',
                    'dependency' => ['element' => 'layout', 'value' => 'grid'],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Maximale Anzahl Events (0 = unbegrenzt)', 'churchtools-plugin'),
                    'description' => __('Bei Liste/Grid nur eine Obergrenze pro Nachlade-Schritt – wie viel angezeigt wird, bestimmt der Zeitraum. Bei „Nächster Termin“ die Gesamtzahl inkl. Hero-Kachel.', 'churchtools-plugin'),
                    'param_name' => 'limit',
                    'value' => '0',
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Klickverhalten', 'churchtools-plugin'),
                    'param_name' => 'click',
                    'value' => [
                        __('Standard (Design-Einstellung)', 'churchtools-plugin') => 'default',
                        __('Keine', 'churchtools-plugin') => 'none',
                        __('Popup', 'churchtools-plugin') => 'popup',
                        __('Eigene Seite', 'churchtools-plugin') => 'page',
                    ],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Eventfinder anzeigen', 'churchtools-plugin'),
                    'description' => __(
                        '„Du suchst …“-Buttons für Kalender/Zeitraum plus Suche — ersetzt Kalenderfilter und Suchleiste unten, falls dort ebenfalls angehakt.',
                        'churchtools-plugin'
                    ),
                    'param_name' => 'eventfinder',
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Kalenderfilter anzeigen', 'churchtools-plugin'),
                    'param_name' => 'filter',
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Suchleiste anzeigen', 'churchtools-plugin'),
                    'param_name' => 'search',
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Termine nach Monat gruppieren', 'churchtools-plugin'),
                    'param_name' => 'month_dividers',
                    'value' => [__('Gruppieren', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    // A dropdown, not a checkbox like the three opt-ins above:
                    // WPBakery omits an unchecked checkbox from the shortcode
                    // entirely, which the shortcode reads as "attribute not set"
                    // — and paging's default is *on*, so unchecking the box
                    // would silently do nothing. A dropdown always writes a
                    // value, so both directions actually stick.
                    'type' => 'dropdown',
                    'heading' => __('Weitere Termine nachladen', 'churchtools-plugin'),
                    'description' => __('Lädt jeweils den nächsten Zeitraum nach, ohne die Seite neu zu laden.', 'churchtools-plugin'),
                    'param_name' => 'paging',
                    'value' => [
                        __('Nachladen-Button anzeigen', 'churchtools-plugin') => '1',
                        __('Aus (nur der erste Zeitraum)', 'churchtools-plugin') => '0',
                    ],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Zeitraum pro Seite in Monaten (0 = Standard)', 'churchtools-plugin'),
                    'description' => __('Überschreibt die globale Einstellung im Plugin-Tab „Design“ nur für dieses Element.', 'churchtools-plugin'),
                    'param_name' => 'months',
                    'value' => '0',
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
            ],
        ]);
    }
}
