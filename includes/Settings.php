<?php

declare(strict_types=1);

namespace ChurchToolsPlugin;

use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DesignPreset;
use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\EventWindow;

/**
 * Die Einstellungen des Plugins (`ctp_settings`): lesen, Vorgaben, und der Weg,
 * am Formular-Sanitizer vorbei zu schreiben.
 *
 * Bis zum Sicherheits-Review vom 2026-09-14 lag das alles in
 * Admin\SettingsPage, und damit hing der Sync am Backend: SyncEngine fragte
 * die Admin-Seite nach der Instanz und liess sie die Kalenderliste schreiben.
 * Die Formularlogik (Felder, Sanitizer) bleibt dort; was Sync, Frontend und
 * Backend gemeinsam brauchen, steht hier.
 */
final class Settings
{
    public const OPTION_KEY = 'ctp_settings';

    /** Siehe writeUnsanitized(). */
    private static bool $writingUnsanitized = false;

    public static function defaults(): array
    {
        return [
            'instance' => '',
            'api_key' => '',
            /**
             * Keyed by ChurchTools calendar ID:
             * [ 'name' => string, 'enabled' => bool, 'color' => '#rrggbb',
             *   'default_color' => '#rrggbb' (ChurchTools' own color, for the "reset" button, see Admin\SettingsPage::renderCalendarCard()),
             *   'default_image_id' => int (attachment ID) ]
             */
            'calendars' => [],
            /**
             * Keyed by ChurchTools resource ID:
             * [ 'name' => string, 'enabled' => bool, 'sort_key' => int ]
             *
             * Ein Haken heisst „dieser Raum ist es wert, oeffentlich genannt zu
             * werden". Leer ist der Normalzustand: Ohne Auswahl fragt der Sync
             * die Buchungen gar nicht erst ab.
             */
            'resources' => [],
            /**
             * Wie streng die Ortsangabe aus den Buchungen gebildet wird - eine
             * der RoomLookup::MODE_*-Konstanten. An den Daten der
             * Referenzinstanz: „exclusive" 50, „single" 81, „all" 85 Termine
             * (bei drei angehakten Raeumen).
             *
             * Der Standardwert ist bewusst leer und nicht MODE_SINGLE: Sonst
             * stuende hier nach dem Zusammenfuehren mit den Vorgaben immer ein
             * gueltiger Modus, und der Rueckfall auf das Kaestchen aus 1.12.0
             * (`rooms_exclusive`) kaeme nie zum Zug. Aufgeloest wird in
             * Sync\ResourceList::resolveMode().
             */
            'rooms_mode' => '',
            'sync_interval' => 'hourly',
            // Ein volles Jahr, nicht ein halbes: Der Gemeindekalender ist ein
            // Jahreszyklus (Weihnachten, Ostern, Konfirmation, Freizeiten), und
            // bei 180 Tagen fehlt davon regelmäßig die zweite Hälfte, ohne dass
            // im Frontend erkennbar wäre, dass da noch etwas käme — die Liste
            // hört einfach auf. Der Preis ist gering: auf der Referenzinstanz
            // sind es 156 statt 125 Zeilen.
            'sync_days_ahead' => 365,
            'retention_days' => 30,
            'keep_data_on_uninstall' => false,
            'design_preset' => DesignPreset::DEFAULT_PRESET,
            'element_order' => CardDesign::DEFAULT_ORDER,
            'corner_style' => 'rounded',
            'hidden_elements' => [],
            'media_aspect_ratio' => 'wide',
            'accent_color_enabled' => false,
            // Matches frontend.css's own --ctp-accent fallback, so the color
            // picker starts on the value that's already visually in effect
            // rather than on an arbitrary, surprising default.
            'accent_color' => '#2563eb',
            'button_color_enabled' => false,
            // Matches frontend.css's own --ctp-color-button-strong fallback,
            // same "start on the value already in effect" rule as accent_color.
            'button_color' => '#111827',
            /**
             * Das Wort, das an einem gerade laufenden Termin steht („Jetzt",
             * „Live", „Läuft gerade" - was die Gemeinde sagt). Leer heißt: kein
             * Kennzeichen, das leere Feld ist der Ausschalter (siehe
             * Frontend\LiveBadge).
             *
             * Anders als Teilen- und Importieren-Knopf mit einem Wert
             * vorbelegt statt aus: Die beiden sind Bedienelemente, die
             * ausdrücklich bestellt werden wollen - das hier ist eine Angabe
             * zum Termin, so wie „Ganztägig", und die steht auch ungefragt da.
             *
             * Der Vorgabewert ist bewusst nicht übersetzbar: defaults() läuft
             * auch, bevor WordPress die Sprachdateien geladen hat, und ein
             * __() an dieser Stelle löste die Meldung über zu früh geladene
             * Textdomains aus. Das Wort ist ohnehin eine Eingabe des
             * Betreibers, keine Oberflächenbeschriftung.
             */
            'live_label' => 'Jetzt',
            'click_behavior' => 'popup',
            // 0 = keine Elternseite: Termine behalten die Adresse
            // /churchtools-termin/<id>/, mit der sie bis 1.4.1 ausgeliefert
            // wurden. Bestandsseiten aendern ihre Adressen also nicht von
            // selbst, nur weil aktualisiert wurde.
            'detail_page_id' => 0,
            'detail_element_order' => DetailDesign::DEFAULT_ORDER,
            /**
             * Der „Teilen"-Knopf in Popup und eigener Terminseite. Aus, und
             * zwar aus zwei Gründen: Dasselbe Opt-in-Muster tragen `filter`,
             * `search`, `month_dividers` und `eventfinder` schon, und ohne den
             * Standard „aus" bekäme jede Bestandsseite beim Update ungefragt
             * ein neues Bedienelement in ihre Termine.
             */
            'detail_share_enabled' => false,
            /**
             * Der Button „Importieren". Aus demselben Grund aus wie
             * der Teilen-Knopf darueber: Eine Bestandsseite soll beim Update
             * kein Bedienelement dazubekommen, das niemand bestellt hat.
             */
            'detail_ics_enabled' => false,
            /*
             * Wie der Importieren-Knopf aus: Ein Abonnement ist eine
             * Zusage ueber alle kuenftigen Termine, und die trifft der
             * Betreiber ausdruecklich oder gar nicht.
             */
            'detail_subscribe_enabled' => false,
            'paging_months' => EventWindow::DEFAULT_MONTHS,
        ];
    }

    /**
     * Widens both stored element orders (and the hidden-field list) from the
     * pre-split key set on every read — date, time and location used to be one
     * "meta" element. Doing it here rather than in a one-shot upgrade means a
     * site that never re-saves its Design tab still renders correctly; the
     * migrated value is written back the next time anything saves.
     */
    public static function get(): array
    {
        $settings = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());

        $settings['element_order'] = CardDesign::upgradeOrder((array) $settings['element_order']);
        $settings['detail_element_order'] = DetailDesign::upgradeOrder((array) $settings['detail_element_order']);
        $settings['hidden_elements'] = CardDesign::upgradeHiddenElements((array) $settings['hidden_elements']);

        return $settings;
    }

    public static function getBaseUrl(): string
    {
        return self::buildBaseUrl(self::get()['instance']);
    }

    public static function buildBaseUrl(string $instance): string
    {
        return $instance === '' ? '' : "https://{$instance}.church.tools";
    }

    public static function getEnabledCalendarIds(): array
    {
        $enabled = array_filter(self::get()['calendars'], static fn (array $calendar): bool => !empty($calendar['enabled']));

        return array_map('intval', array_keys($enabled));
    }

    /**
     * Resolves shortcode/block calendar references, which may be a mix of numeric
     * ChurchTools calendar IDs and calendar names, into known calendar IDs. Only
     * calendars the admin has fetched into settings (see Admin\SettingsPage::ajaxFetchCalendars()) can be
     * matched by name — unknown IDs typed by hand still work since the sync itself
     * validates them against ChurchTools.
     */
    public static function resolveCalendarIds(array $refs): array
    {
        $calendars = self::get()['calendars'];
        $resolved = [];

        foreach ($refs as $ref) {
            $ref = trim((string) $ref);

            if ($ref === '') {
                continue;
            }

            if (ctype_digit($ref)) {
                $resolved[] = (int) $ref;
                continue;
            }

            foreach ($calendars as $id => $calendar) {
                if (strcasecmp($calendar['name'], $ref) === 0) {
                    $resolved[] = (int) $id;
                    break;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Schreibt Einstellungen, die nicht aus dem Formular kommen, am Sanitizer
     * vorbei - frisch aus ChurchTools geholte Listen, Migrationen.
     *
     * Der Sanitizer (Admin\SettingsPage::sanitizeSettings()) haengt ueber
     * register_setting() an *jedem* update_option() dieser Option, nicht nur an
     * Formularen. Er liesse etwa beim ersten Laden keine einzige Kalender-ID
     * durch, weil noch keine „bekannt" ist. Frueher hing jeder Aufrufer ihn mit
     * remove_filter()/add_filter() selbst aus - und musste dafuer die
     * Admin-Klasse kennen. Jetzt fragt der Sanitizer hier nach.
     */
    public static function writeUnsanitized(array $settings): void
    {
        self::$writingUnsanitized = true;

        try {
            update_option(self::OPTION_KEY, $settings);
        } finally {
            self::$writingUnsanitized = false;
        }
    }

    public static function isWritingUnsanitized(): bool
    {
        return self::$writingUnsanitized;
    }
}
