<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Integrations;

use ChurchToolsPlugin\Blocks\GroupListBlock;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;

final class WpBakeryIntegration
{
    /**
     * Shortcode-Tag, unter dem das Element bei WPBakery gemeldet ist. Steht
     * hier als Konstante, weil die CSS-Selektoren unten den Tag woertlich
     * enthalten muessen (WPBakery baut daraus die id des Kachel-Links).
     */
    private const BASE = 'ctp_events';

    /** Das zweite Element, die Gruppenliste - mit eigenem Symbol, siehe enqueueElementIcon(). */
    private const GROUPS_BASE = 'ctp_groups';

    /** Klasse des Gruppen-Symbols (drei Personen statt des Kalenders). */
    private const GROUPS_ICON_CLASS = 'ctp-vc-icon-groups';

    /** Der eigene Feldtyp fuer die Auswahl einzelner Gruppen, siehe renderGroupPicker(). */
    public const GROUP_PICKER_TYPE = 'ctp_group_picker';

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

        // Die Beschriftung im Baustein entsteht im Backend-Editor im Browser
        // (vc.atts[typ].render), und das Skript des Feldtyps laedt WPBakery
        // erst mit dem Bearbeitungsfenster - beim Laden der Seite stuenden
        // sonst IDs statt Namen im Baustein (so im echten WPBakery 8.7 gesehen).
        add_action('vc_backend_editor_enqueue_js_css', [$this, 'enqueueGroupPickerScript']);
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
        if (!is_array($settings) || !in_array($settings['base'] ?? '', [self::BASE, self::GROUPS_BASE], true)) {
            return $value;
        }

        // Die Gruppen-Auswahl speichert kommagetrennte IDs ("514,269"). Im
        // Baustein sollen die Namen stehen, in der gewaehlten Reihenfolge; die
        // Beschriftungen traegt der eigene Feldtyp unter `ctp_choices`.
        if (($param['param_name'] ?? '') === 'groups' && is_scalar($value) && is_array($param['ctp_choices'] ?? $param['value'] ?? null)) {
            $labels = array_flip(array_map('strval', $param['ctp_choices'] ?? $param['value']));
            $names = [];

            foreach (explode(',', (string) $value) as $id) {
                $names[] = $labels[trim($id)] ?? sprintf('#%s', trim($id));
            }

            return implode(', ', $names);
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
     * background-size setzt das Symbol auf 48% der Kachel: Das Theme zeichnet
     * seine eigenen Symbole als Schriftzeichen in 15px, und 15 von 32 ist
     * genau diese Groesse - randfuellend war es sichtbar groesser als seine
     * Nachbarn.
     *
     * Das ?ver= an der Bildadresse ist kein Beiwerk. Der Dateiname bleibt
     * ueber Versionen hinweg gleich, und nach 1.2.0 lieferte der Browser
     * weiter das dunkelblaue Bild aus 1.1.1 aus - die Regel war neu, das Bild
     * darin nicht. Am vc_map()-Wert waere ein Anhaengsel riskant (WPBakery
     * prueft dort auf eine URL), hier steht die Adresse aber im eigenen
     * Stylesheet und nichts prueft sie.
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
        // Die Selektoren mit Tag gibt es je Element einmal; die mit der Klasse
        // decken beide zugleich ab.
        $perBase = static fn (string $base): string => sprintf(
            '#wpbakery_content .wpb_%1$s > .wpb_element_wrapper > .wpb_element_title > .vc_element-icon,'
                . '.vc_el-container #%1$s .vc_element-icon,'
                . '.vc_el-container > #%1$s > .vc_element-icon,'
                . '.wpb_%1$s > .wpb_element_wrapper > .wpb_element_title > .vc_element-icon,'
                . '.vc_helper.vc_helper-%1$s > .vc_element-icon,',
            $base
        );

        $iconRule = static fn (string $class, string $selectors, string $file): string => sprintf(
            '#wpbakery_content .vc_element-icon.%1$s,'
                . '%2$s'
                . '.vc_ui-panel-content-container .vc_element-icon.%1$s,'
                . '.vc_element-icon.%1$s'
                . '{background-color:%4$s;border-radius:3px;'
                . 'background-image:url("%3$s") !important;'
                . 'background-position:center !important;'
                . 'background-repeat:no-repeat !important;'
                . 'background-size:48%% !important;}',
            $class,
            $selectors,
            esc_url(add_query_arg('ver', CTP_VERSION, CTP_PLUGIN_URL . 'assets/img/' . $file)),
            self::ICON_BACKDROP
        );

        wp_add_inline_style(
            $handle,
            $iconRule(self::ICON_CLASS, $perBase(self::BASE), 'wpbakery-element-icon.svg')
                . $iconRule(self::GROUPS_ICON_CLASS, $perBase(self::GROUPS_BASE), 'wpbakery-groups-icon.svg')
                . self::groupPickerCss()
        );

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

        // Vor vc_map(), das den Typ benutzt. Die Schnittstelle so, wie
        // WPBakerys eigenes Beispiel sie zeigt (github.com/wpbakery/dev-example,
        // elements/with-custom-param): Das Feld liefert beliebiges Markup, und
        // gespeichert wird der Wert des Eingabefelds mit der Klasse
        // `wpb_vc_param_value` und dem Parameternamen als `name`. Das Skript
        // (dritter Parameter) laedt WPBakery zum Bearbeitungsfenster.
        if (function_exists('vc_add_shortcode_param')) {
            vc_add_shortcode_param(
                self::GROUP_PICKER_TYPE,
                [self::class, 'renderGroupPicker'],
                add_query_arg('ver', CTP_VERSION, CTP_PLUGIN_URL . 'assets/js/wpbakery-group-picker.js')
            );
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
                    'description' => __('Höchstens so viele, wie in die Zeile passen – je Kachel mindestens 240px.', 'churchtools-plugin'),
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
                        'Geführte Auswahl: Knöpfe für Thema und Zeitraum plus Suche — ersetzt Kalenderfilter und Suchleiste unten, falls dort ebenfalls angehakt.',
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
                    'description' => __('Überschreibt die globale Einstellung unter „Einstellungen → Design“ nur für dieses Element.', 'churchtools-plugin'),
                    'param_name' => 'months',
                    'value' => '0',
                    'dependency' => ['element' => 'layout', 'value_not_equal_to' => 'upcoming'],
                ],
            ],
        ]);

        vc_map([
            'name' => __('ChurchTools Gruppen', 'churchtools-plugin'),
            'base' => self::GROUPS_BASE,
            'category' => __('ChurchTools', 'churchtools-plugin'),
            'icon' => self::GROUPS_ICON_CLASS,
            'params' => [
                [
                    // Erst die Frage, dann nur das passende Feld (Nutzerwunsch
                    // 2026-09-15: „Sonst ist der Startscreen gleich ueberladen").
                    //
                    // WPBakery laesst beim Speichern jedes Feld weg, dessen
                    // Abhaengigkeit nicht erfuellt ist, und ein Feld mit dem
                    // Standardwert nur ohne `save_always` (vc.getMergedParams()
                    // in backend.min.js, 7.9 nachgelesen). Die jeweils andere
                    // Angabe faellt damit von selbst heraus; `save_always`
                    // schreibt `source` trotzdem immer mit, damit der Shortcode
                    // fuer sich lesbar bleibt und nicht von einem Standardwert
                    // abhaengt, den man ihm nicht ansieht.
                    'type' => 'dropdown',
                    'heading' => __('Welche Gruppen?', 'churchtools-plugin'),
                    'param_name' => 'source',
                    'admin_label' => true,
                    'std' => 'homepage',
                    'save_always' => true,
                    'value' => [
                        __('Alle Gruppen einer Homepage', 'churchtools-plugin') => 'homepage',
                        __('Einzelne Gruppen', 'churchtools-plugin') => 'groups',
                    ],
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Gruppen-Homepage', 'churchtools-plugin'),
                    'param_name' => 'homepage',
                    'admin_label' => true,
                    'value' => self::homepageOptions(),
                    'dependency' => ['element' => 'source', 'value' => 'homepage'],
                ],
                [
                    // Eigener Feldtyp statt WPBakerys Ankreuzfeldern: Die
                    // setzte WPBakery als Fliesstext nebeneinander, Namen
                    // brachen mitten im Eintrag um, und bei 19 Gruppen suchte
                    // man lange (Nutzerbefund 2026-09-15 mit Screenshot aus
                    // WPBakery 8.7). Siehe renderGroupPicker().
                    'type' => self::GROUP_PICKER_TYPE,
                    'heading' => __('Einzelne Gruppen', 'churchtools-plugin'),
                    'description' => __('Zur Auswahl stehen die Gruppen der angehakten Homepages, in der Reihenfolge der Liste „Ausgewählt“.', 'churchtools-plugin'),
                    'param_name' => 'groups',
                    'admin_label' => true,
                    'ctp_choices' => self::groupOptions(),
                    'dependency' => ['element' => 'source', 'value' => 'groups'],
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Ansicht', 'churchtools-plugin'),
                    'param_name' => 'layout',
                    'admin_label' => true,
                    'value' => [
                        __('Raster', 'churchtools-plugin') => 'grid',
                        __('Hervorgehoben', 'churchtools-plugin') => 'featured',
                    ],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Spalten', 'churchtools-plugin'),
                    'description' => __('Höchstens so viele, wie in die Zeile passen – je Kachel mindestens 240px.', 'churchtools-plugin'),
                    'param_name' => 'columns',
                    'value' => '3',
                    'dependency' => ['element' => 'layout', 'value' => 'grid'],
                ],
            ],
        ]);
    }

    /**
     * Die angehakten Homepages als Auswahl, mit dem Namen als Wert - so steht
     * im Shortcode derselbe lesbare Wert wie in dem, den der Reiter „Gruppen"
     * zum Kopieren anbietet. Der leere erste Eintrag ist noetig, weil WPBakery
     * ein Auswahlfeld ohne gespeicherten Wert sonst stillschweigend auf den
     * ersten Eintrag setzt, ohne ihn in den Shortcode zu schreiben.
     *
     * @return array<string, string>
     */
    private static function homepageOptions(): array
    {
        $options = [__('— Homepage wählen —', 'churchtools-plugin') => ''];

        foreach (GroupSettings::enabledHomepages() as $id => $homepage) {
            $name = $homepage['name'] !== '' ? $homepage['name'] : (string) $id;
            $options[$name] = $name;
        }

        return $options;
    }

    /**
     * Die einzeln waehlbaren Gruppen als `Beschriftung => ID`, mit den
     * Homepages dahinter, weil Gruppennamen nicht eindeutig sind (siehe
     * Blocks\GroupListBlock::groupChoices()).
     *
     * @return array<string, string>
     */
    private static function groupOptions(): array
    {
        $options = [];

        foreach (GroupListBlock::groupChoices() as $choice) {
            $label = $choice['homepages'] !== ''
                ? sprintf('%s (%s)', $choice['name'], $choice['homepages'])
                : $choice['name'];

            // Zwei gleich lautende Beschriftungen wuerden sich im Array
            // ueberschreiben; die ID macht sie eindeutig.
            if (isset($options[$label])) {
                $label .= sprintf(' #%d', $choice['id']);
            }

            $options[$label] = (string) $choice['id'];
        }

        return $options;
    }

    /**
     * Laedt das Skript des Feldtyps schon mit dem Backend-Editor, siehe
     * register(). Mit WPBakerys eigenem Skript als Abhaengigkeit, wo es
     * registriert ist (in 7.9 `vc-backend-min-js`), damit `vc.atts` schon
     * steht; das Skript faengt den anderen Fall selbst ab.
     */
    public function enqueueGroupPickerScript(): void
    {
        $handle = 'ctp-wpbakery-group-picker';
        $dependencies = wp_script_is('vc-backend-min-js', 'registered') ? ['vc-backend-min-js'] : [];

        wp_enqueue_script($handle, CTP_PLUGIN_URL . 'assets/js/wpbakery-group-picker.js', $dependencies, CTP_VERSION, true);
    }

    /**
     * Das Feld „Einzelne Gruppen": oben die gewaehlten Gruppen in ihrer
     * Reihenfolge (verschiebbar, entfernbar), darunter ein Filter und alle
     * waehlbaren Gruppen nach Homepage gegliedert.
     *
     * Die Ausgangslage rendert PHP vollstaendig - das Skript
     * (assets/js/wpbakery-group-picker.js) haelt danach nur noch Liste, Haken
     * und das versteckte Feld im Gleichschritt. Eine gewaehlte ID, die es nicht
     * mehr gibt, bleibt als „nicht mehr verfuegbar" in der Liste stehen, statt
     * beim naechsten Speichern still zu verschwinden.
     *
     * @param array<string, mixed> $settings
     * @param mixed                $value
     */
    public static function renderGroupPicker($settings, $value): string
    {
        $paramName = (string) ($settings['param_name'] ?? 'groups');
        $selected = GroupSync::parseIds(is_scalar($value) ? (string) $value : '');
        $names = [];
        $sections = '';

        foreach (GroupSettings::enabledHomepages() as $homepageId => $homepage) {
            $items = '';

            foreach (GroupSync::groupsFor((int) $homepageId) as $group) {
                $id = (int) ($group['id'] ?? 0);
                $name = (string) ($group['name'] ?? '');

                if ($id <= 0) {
                    continue;
                }

                $names[$id] = $name;
                $items .= sprintf(
                    '<label class="ctp-wpb-picker__option"><input type="checkbox" value="%1$d" data-name="%2$s"%3$s /> <span>%4$s</span></label>',
                    $id,
                    esc_attr($name),
                    in_array($id, $selected, true) ? ' checked="checked"' : '',
                    esc_html($name)
                );
            }

            if ($items === '') {
                continue;
            }

            $sections .= sprintf(
                '<fieldset class="ctp-wpb-picker__homepage"><legend>%1$s</legend><div class="ctp-wpb-picker__options">%2$s</div></fieldset>',
                esc_html($homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $homepageId)),
                $items
            );
        }

        /* translators: %d: ID of a selected group that is no longer available */
        $missingLabel = __('#%d (nicht mehr verfügbar)', 'churchtools-plugin');
        $order = '';

        foreach ($selected as $id) {
            $order .= self::pickerOrderItem($id, $names[$id] ?? sprintf($missingLabel, $id), !isset($names[$id]));
        }

        return sprintf(
            '<div class="ctp-wpb-picker" data-missing-label="%1$s" data-up-label="%2$s" data-down-label="%3$s" data-remove-label="%4$s">'
                . '<input type="hidden" name="%5$s" class="wpb_vc_param_value %5$s %6$s_field" value="%7$s" />'
                . '<div class="ctp-wpb-picker__selected">'
                . '<p class="ctp-wpb-picker__heading">%8$s</p>'
                . '<ol class="ctp-wpb-picker__order">%9$s</ol>'
                . '<p class="ctp-wpb-picker__empty"%10$s>%11$s</p>'
                . '</div>'
                . '%12$s'
                . '</div>',
            esc_attr($missingLabel),
            esc_attr__('Nach oben', 'churchtools-plugin'),
            esc_attr__('Nach unten', 'churchtools-plugin'),
            esc_attr__('Entfernen', 'churchtools-plugin'),
            esc_attr($paramName),
            esc_attr(self::GROUP_PICKER_TYPE),
            esc_attr(implode(',', $selected)),
            esc_html__('Ausgewählt – in dieser Reihenfolge auf der Seite', 'churchtools-plugin'),
            $order,
            $selected === [] ? '' : ' hidden',
            esc_html__('Keine einzelnen Gruppen gewählt – es gilt die Gruppen-Homepage.', 'churchtools-plugin'),
            $sections === ''
                ? '<p class="ctp-wpb-picker__none">' . esc_html__('Noch keine Gruppen abgeglichen. Unter „ChurchTools → Gruppen“ eine Homepage anhaken.', 'churchtools-plugin') . '</p>'
                : sprintf(
                    '<input type="search" class="ctp-wpb-picker__filter" placeholder="%1$s" aria-label="%1$s" /><div class="ctp-wpb-picker__homepages">%2$s</div>',
                    esc_attr__('Gruppen filtern …', 'churchtools-plugin'),
                    $sections
                )
        );
    }

    /** Ein Eintrag der Liste „Ausgewaehlt" - das Skript baut dieselbe Form nach. */
    private static function pickerOrderItem(int $id, string $name, bool $missing): string
    {
        return sprintf(
            '<li class="ctp-wpb-picker__item%1$s" data-id="%2$d"><span class="ctp-wpb-picker__name">%3$s</span>'
                . '<button type="button" class="button-link" data-action="up" aria-label="%4$s">&uarr;</button>'
                . '<button type="button" class="button-link" data-action="down" aria-label="%5$s">&darr;</button>'
                . '<button type="button" class="button-link" data-action="remove" aria-label="%6$s">&times;</button></li>',
            $missing ? ' ctp-wpb-picker__item--missing' : '',
            $id,
            esc_html($name),
            esc_attr__('Nach oben', 'churchtools-plugin'),
            esc_attr__('Nach unten', 'churchtools-plugin'),
            esc_attr__('Entfernen', 'churchtools-plugin')
        );
    }

    /**
     * Die Gestaltung der Auswahl, im selben Inline-Stylesheet wie die Symbole.
     * Mehrspaltig ab genuegend Breite, damit 20 Gruppen nicht eine lange
     * Spalte werden; jede Option einzeilig bis zur Spaltenbreite, dann mit
     * sauberem Umbruch innerhalb ihrer Zelle statt mitten im Fliesstext.
     */
    private static function groupPickerCss(): string
    {
        return '.ctp-wpb-picker{display:grid;gap:12px;}'
            . '.ctp-wpb-picker__selected{padding:10px 12px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;}'
            . '.ctp-wpb-picker__heading{margin:0 0 6px;font-weight:600;}'
            // Eigener Zaehler: display:flex am Eintrag nimmt der <ol> ihre Nummern.
            . '.ctp-wpb-picker__order{margin:0;padding:0;list-style:none;counter-reset:ctp-pick;}'
            . '.ctp-wpb-picker__item{display:flex;align-items:center;gap:6px;margin:2px 0;counter-increment:ctp-pick;}'
            . '.ctp-wpb-picker__item::before{content:counter(ctp-pick) ".";min-width:1.6em;color:#646970;}'
            . '.ctp-wpb-picker__item .ctp-wpb-picker__name{flex:1;}'
            . '.ctp-wpb-picker__item--missing .ctp-wpb-picker__name{color:#b32d2e;}'
            . '.ctp-wpb-picker__item .button-link{min-width:24px;text-align:center;text-decoration:none;font-size:15px;}'
            . '.ctp-wpb-picker__empty{margin:0;color:#646970;}'
            . '.ctp-wpb-picker__filter{width:100%;max-width:320px;}'
            . '.ctp-wpb-picker__homepages{display:grid;gap:10px;}'
            . '.ctp-wpb-picker__homepage{margin:0;padding:8px 12px;border:1px solid #dcdcde;border-radius:4px;}'
            . '.ctp-wpb-picker__homepage legend{padding:0 4px;font-weight:600;}'
            . '.ctp-wpb-picker__options{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 16px;}'
            . '.ctp-wpb-picker__option{display:flex;align-items:flex-start;gap:6px;margin:0;line-height:1.4;}'
            . '.ctp-wpb-picker__option input{margin-top:2px;flex-shrink:0;}'
            . '.ctp-wpb-picker [hidden]{display:none !important;}';
    }
}
