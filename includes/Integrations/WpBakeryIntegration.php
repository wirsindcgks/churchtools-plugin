<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Integrations;

final class WpBakeryIntegration
{
    /**
     * Klasse, unter der das Kalender-Icon des Elements haengt - WPBakery
     * rendert im Elementefenster nur ein <span class="vc_element-icon {icon}">
     * und erwartet in "icon" genau so einen Klassennamen. Was hier vorher
     * stand ('icon-wpb-calendar'), gibt es in WPBakerys eigenem Icon-Satz
     * nicht: Das Feld blieb leer, das Element stand als einziges ohne Bild in
     * der Liste.
     */
    private const ICON_CLASS = 'ctp-vc-icon';

    public function register(): void
    {
        add_action('vc_before_init', [$this, 'mapShortcode']);

        // Backend-Editor und Frontend-Editor sind zwei getrennte Kontexte, das
        // Elementefenster gibt es in beiden.
        add_action('admin_enqueue_scripts', [$this, 'enqueueElementIcon']);
        add_action('vc_frontend_editor_enqueue_js_css', [$this, 'enqueueElementIcon']);
    }

    /**
     * Nur eine Regel, deshalb als Inline-Style an einem leeren Handle statt an
     * einer eigenen Datei (dasselbe Muster, das WordPress fuer
     * wp_add_inline_style() ohne eigenes Stylesheet vorsieht). Der Selektor
     * nennt beide Klassen und ist damit spezifischer als WPBakerys eigene
     * .vc_element-icon-Regel - ohne !important, das in diesem Plugin nirgends
     * vorkommt.
     */
    public function enqueueElementIcon(): void
    {
        if (!defined('WPB_VC_VERSION')) {
            return;
        }

        $handle = 'ctp-wpbakery-element-icon';

        wp_register_style($handle, false, [], CTP_VERSION);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, sprintf(
            '.vc_element-icon.%1$s{background-image:url("%2$s");'
                . 'background-size:22px 22px;background-position:center;background-repeat:no-repeat;}',
            self::ICON_CLASS,
            esc_url(CTP_PLUGIN_URL . 'assets/img/wpbakery-element.svg')
        ));
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
            'base' => 'ctp_events',
            'category' => __('ChurchTools', 'churchtools-plugin'),
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
