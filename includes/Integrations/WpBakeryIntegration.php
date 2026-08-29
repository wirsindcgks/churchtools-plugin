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
     * Flaeche hinter dem Symbol. Sie steht hier nur fuer den Fall, dass kein
     * Theme sie setzt - auf der Zielseite gewinnt ohnehin dessen eigene Regel
     * (`background: #2d343f !important`), und genau dieselbe Farbe zu nehmen
     * heisst, dass das Element dort aussieht wie seine Nachbarn.
     */
    private const ICON_BACKDROP = '#2d343f';

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

        add_filter('vc_wpbakeryshortcode_single_param_html_holder_value', [$this, 'adminLabelValue'], 10, 3);
    }

    /**
     * Uebersetzt den gespeicherten Wert einer Option in ihre Beschriftung,
     * bevor WPBakery ihn im Baustein anzeigt (siehe admin_label unten).
     *
     * Ohne das stuende dort der rohe Wert - "Ansicht: grid" statt "Ansicht:
     * Grid", bei den Ankreuzfeldern sogar "Eventfinder anzeigen: 1". Die
     * Zuordnung kommt aus der Option selbst: `value` ist bei Auswahlfeldern
     * wie bei Ankreuzfeldern ein Array `Beschriftung => Wert`, ein
     * Rueckwaertssuchen genuegt also und es gibt keine zweite Liste, die mit
     * der ersten aus dem Tritt geraten koennte.
     *
     * Der Filter ist global, deshalb die Pruefung auf das eigene Element:
     * `$settings` ist die vc_map()-Definition des Elements, zu dem die Option
     * gehoert.
     *
     * @param mixed              $value
     * @param array<string,mixed> $param
     * @param array<string,mixed> $settings
     *
     * @return mixed
     */
    public function adminLabelValue($value, $param, $settings)
    {
        if (!is_array($settings) || (($settings['base'] ?? '') !== self::BASE)) {
            return $value;
        }

        if (!is_scalar($value) || !is_array($param['value'] ?? null)) {
            return $value;
        }

        // Bei Ankreuzfeldern waere die Beschriftung des Wertes eine Dopplung
        // der Ueberschrift ("Eventfinder anzeigen: Anzeigen"). Ausgeschaltete
        // Felder kommen hier ohnehin nicht an - WPBakery laesst sie aus dem
        // Shortcode weg, und leere Werte blendet es selbst aus.
        if (($param['type'] ?? '') === 'checkbox') {
            return __('Ja', 'churchtools-plugin');
        }

        $label = array_search((string) $value, array_map('strval', $param['value']), true);

        return $label === false ? $value : $label;
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
     * Grundlage der Selektorliste ist die aus printIconStyles(): sie deckt
     * beide Orte ab, Elementefenster und schematische Darstellung im Backend.
     * Davor stehen zwei Selektoren mit #wpbakery_content bzw.
     * .vc_ui-panel-content-container - den beiden Containern, an denen die
     * Zielseite ihre eigene Regel aufhaengt:
     *
     *     .vc_ui-panel-content-container .vc_element-icon,
     *     #wpbakery_content .vc_element-icon {
     *         background: #2d343f !important; border-radius: 3px !important;
     *     }
     *
     * Daraus folgt zweierlei. Erstens setzt die *Kurzform* background auch
     * background-image zurueck, und das mit !important - hier muss also
     * zwingend !important stehen, sonst gewinnt sie und das Symbol ist weg
     * (genau der Zustand nach 1.1.1). Es ist die einzige Stelle im Plugin mit
     * !important, und sie steht nicht aus Bequemlichkeit da, sondern weil eine
     * fremde Regel es zuerst benutzt. Zweitens entscheidet unter
     * !important-Regeln wieder die Spezifitaet: #wpbakery_content
     * .vc_element-icon ist (1,1,0), die Selektoren hier liegen darueber.
     *
     * Die Flaeche bleibt bewusst *ohne* !important - wo ein Theme sie faerbt,
     * soll seine Farbe gelten, sonst springt ICON_BACKDROP ein. Und
     * background-size haelt das Symbol auf rund zwei Dritteln der Kachel,
     * statt es randfuellend aufzublasen: dieselbe optische Groesse, die die
     * uebrigen Elemente dort haben.
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
            '#wpbakery_content .vc_element-icon.%1$s,'
                . '#wpbakery_content .wpb_%2$s > .wpb_element_wrapper > .wpb_element_title > .vc_element-icon,'
                . '.vc_ui-panel-content-container .vc_element-icon.%1$s,'
                . '.vc_element-icon.%1$s,'
                . '.vc_el-container #%2$s .vc_element-icon,'
                . '.vc_el-container > #%2$s > .vc_element-icon,'
                . '.wpb_%2$s > .wpb_element_wrapper > .wpb_element_title > .vc_element-icon,'
                . '.vc_helper.vc_helper-%2$s > .vc_element-icon'
                . '{background-color:%4$s;border-radius:3px;'
                . 'background-image:url("%3$s") !important;'
                . 'background-position:center !important;'
                . 'background-repeat:no-repeat !important;'
                . 'background-size:64%% !important;}',
            self::ICON_CLASS,
            self::BASE,
            esc_url(CTP_PLUGIN_URL . 'assets/img/wpbakery-element-icon.svg'),
            self::ICON_BACKDROP
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
                    'admin_label' => true,
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Ansicht', 'churchtools-plugin'),
                    'param_name' => 'layout',
                    'admin_label' => true,
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
                    'admin_label' => true,
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Kalenderfilter anzeigen', 'churchtools-plugin'),
                    'param_name' => 'filter',
                    'admin_label' => true,
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Suchleiste anzeigen', 'churchtools-plugin'),
                    'param_name' => 'search',
                    'admin_label' => true,
                    'value' => [__('Anzeigen', 'churchtools-plugin') => '1'],
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
                [
                    'type' => 'checkbox',
                    'heading' => __('Termine nach Monat gruppieren', 'churchtools-plugin'),
                    'param_name' => 'month_dividers',
                    'admin_label' => true,
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
