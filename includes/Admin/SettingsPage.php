<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DesignPreset;
use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\EventWindow;
use ChurchToolsPlugin\Frontend\Icons;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\RoomLookup;
use ChurchToolsPlugin\Sync\SyncEngine;
use ChurchToolsPlugin\Update\GitHubUpdateChecker;
use Throwable;

final class SettingsPage
{
    private const OPTION_KEY = 'ctp_settings';

    /**
     * Wann die Kalenderliste zuletzt von ChurchTools geholt wurde. Steht in der
     * Statuszeile des Tabs „Kalender“ - ohne sie sieht eine Liste, die seit
     * einem halben Jahr niemand mehr aktualisiert hat, genauso aus wie eine
     * gerade eben geladene.
     */
    private const OPTION_CALENDARS_FETCHED = 'ctp_calendars_fetched';

    /**
     * Dasselbe fuer die Raumliste. Sie kommt aus einem anderen Modul und
     * haengt an einer eigenen Freigabe des API-Keys - eine leere Liste kann
     * deshalb auch heissen „darf dieser Key nicht sehen" statt „gibt es
     * nicht", und dann ist der Zeitstempel die einzige Auskunft darueber, ob
     * ueberhaupt schon einmal nachgesehen wurde.
     */
    private const OPTION_RESOURCES_FETCHED = 'ctp_resources_fetched';

    private const PAGE_SLUG = 'churchtools-plugin';

    /**
     * Dasselbe Repository, aus dem GitHubUpdateChecker::METADATA_URL seine
     * Angaben zu neuen Versionen liest - hier verlinkt der Tab „Updates“ die
     * Quelle, dort wird sie abgefragt. Wer aus einem Fork verteilt, aendert
     * beide. Der abschliessende Schraegstrich gehoert dazu, die Links haengen
     * ihre Pfade direkt an.
     */
    private const REPO_URL = 'https://github.com/wirsindcgks/churchtools-plugin/';
    private const DEFAULT_TAB = 'status';

    private string $pageHook = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_ctp_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_ctp_fetch_calendars', [$this, 'ajaxFetchCalendars']);
        add_action('wp_ajax_ctp_fetch_resources', [$this, 'ajaxFetchResources']);
        add_action('wp_ajax_ctp_run_sync', [$this, 'ajaxRunSync']);
        add_action('wp_ajax_ctp_check_updates', [$this, 'ajaxCheckUpdates']);
    }

    public function addMenuPage(): void
    {
        $this->pageHook = add_menu_page(
            __('ChurchTools Events', 'churchtools-plugin'),
            __('ChurchTools', 'churchtools-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-calendar-alt',
            26
        );
    }

    /**
     * One entry per tab; the key doubles as the `tab` query var and as the settings
     * page slug suffix (see registerSettings()) so each tab's <form> only submits its
     * own fields — sanitizeSettings() relies on that to know which keys to touch.
     */
    private static function tabs(): array
    {
        return [
            'status' => __('Übersicht', 'churchtools-plugin'),
            'connection' => __('Verbindung', 'churchtools-plugin'),
            'calendars' => __('Kalender', 'churchtools-plugin'),
            'rooms' => __('Räume', 'churchtools-plugin'),
            'sync' => __('Synchronisation', 'churchtools-plugin'),
            'design' => __('Design', 'churchtools-plugin'),
            'embed' => __('Einbinden', 'churchtools-plugin'),
            'events' => __('Events', 'churchtools-plugin'),
            'updates' => __('Updates', 'churchtools-plugin'),
        ];
    }

    private static function currentTab(): string
    {
        $tab = sanitize_key((string) ($_GET['tab'] ?? self::DEFAULT_TAB));

        return array_key_exists($tab, self::tabs()) ? $tab : self::DEFAULT_TAB;
    }

    /**
     * Purely cosmetic (tab-nav scanability) — keyed the same as tabs(), one dashicon
     * per topic so the tabs read as distinct sections instead of plain text labels.
     */
    private static function tabIcons(): array
    {
        return [
            'status' => 'dashboard',
            'connection' => 'admin-links',
            'calendars' => 'calendar-alt',
            'rooms' => 'location-alt',
            'sync' => 'update',
            'design' => 'admin-appearance',
            'embed' => 'editor-code',
            'events' => 'list-view',
            'updates' => 'cloud-upload',
        ];
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->pageHook) {
            return;
        }

        // Nur der Tab „Kalender“ oeffnet einen Medien-Dialog (Standardbild je
        // Kalender) - auf allen uebrigen Tabs waren das bisher rund ein Dutzend
        // Skripte und Stylesheets, die niemand aufruft.
        if (self::currentTab() === 'calendars') {
            wp_enqueue_media();
        }

        wp_enqueue_style('ctp-admin', CTP_PLUGIN_URL . 'assets/css/admin.css', [], CTP_VERSION);

        // Own handle rather than reusing Assets::STYLE_HANDLE — that class'
        // enqueue is conditioned on the current *frontend* request using the
        // shortcode/block, an unrelated concern to whether the admin's Design
        // tab preview needs the stylesheet. Loaded after ctp-admin so its
        // .ctp-events rules (needed for the live preview) aren't shadowed by it.
        if (self::currentTab() === 'design') {
            wp_enqueue_style('ctp-admin-design', CTP_PLUGIN_URL . 'assets/css/frontend.css', ['ctp-admin'], CTP_VERSION);
            wp_enqueue_script('ctp-admin-design', CTP_PLUGIN_URL . 'assets/js/admin-design.js', [], CTP_VERSION, true);
            // Every other user-facing string in this plugin is translated in PHP
            // (__()/esc_html_e()), not duplicated in JS — the labels the script
            // needs for dynamically-added spacer/divider list items (see
            // renderElementOrderField()/separatorLabels()) go through the same path.
            wp_localize_script('ctp-admin-design', 'ctpDesignLabels', array_merge(
                self::separatorLabels(),
                ['remove' => __('Entfernen', 'churchtools-plugin')]
            ));
        }
    }

    public function registerSettings(): void
    {
        register_setting(self::PAGE_SLUG, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
            'default' => self::defaults(),
        ]);

        $connectionPage = self::PAGE_SLUG . '_connection';
        // Kein $calendarsPage mehr: der Tab „Kalender“ rendert seine Auswahl
        // selbst (siehe renderCalendarsTab()), weil die Settings-API-Tabelle
        // sie in eine Formularzelle gepresst hat, in der weder die
        // Kalenderfarbe noch die Terminzahl Platz fand. Die Feldnamen sind
        // dieselben geblieben, sanitizeCalendars() merkt davon nichts.
        $syncPage = self::PAGE_SLUG . '_sync';

        add_settings_section('ctp_instance', __('ChurchTools-Instanz', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('instance', __('Instanz', 'churchtools-plugin'), [$this, 'renderInstanceField'], $connectionPage, 'ctp_instance');

        add_settings_section('ctp_api', __('API-Key & Verbindungstest', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('api_key', __('API-Key', 'churchtools-plugin'), [$this, 'renderApiKeyField'], $connectionPage, 'ctp_api');

        // Section-Callback statt '__return_false': er rendert genau zwischen
        // Ueberschrift und Feldtabelle - die Stelle, an der die Aktionsleiste
        // auf jedem anderen Tab auch steht (siehe renderActionBar()).
        add_settings_section('ctp_sync', __('Sync-Einstellungen', 'churchtools-plugin'), [self::class, 'renderSyncIntro'], $syncPage);
        add_settings_field('sync_interval', __('Sync-Intervall', 'churchtools-plugin'), [$this, 'renderSyncIntervalField'], $syncPage, 'ctp_sync');
        add_settings_field('sync_days_ahead', __('Sync-Zeitraum (Tage in die Zukunft)', 'churchtools-plugin'), [$this, 'renderSyncDaysAheadField'], $syncPage, 'ctp_sync');
        add_settings_field('retention_days', __('Aufbewahrung nach Event-Ende (Tage)', 'churchtools-plugin'), [$this, 'renderRetentionField'], $syncPage, 'ctp_sync');
        add_settings_field('keep_data_on_uninstall', __('Beim Deinstallieren', 'churchtools-plugin'), [$this, 'renderKeepDataOnUninstallField'], $syncPage, 'ctp_sync');

        /*
         * The Design tab is grouped by "can you watch this change happen?", not
         * by which data structure a setting belongs to:
         *
         *   _design_tile   -> the card's element order, paired in the layout grid
         *                     with the card preview it drives
         *   _design_detail -> the detail view's element order, paired with the
         *                     detail preview
         *   _design_global -> everything that applies site-wide and has no
         *                     drag&drop of its own (click behavior, corners,
         *                     field visibility, image ratio, accent and button
         *                     color, months per page)
         *
         * Click behavior started out above the detail editor, on the grounds
         * that it decides whether a detail view is reachable at all. It reads
         * as a property *of that editor* there, though, while it in fact
         * governs every card on the site — so it now sits with the rest of the
         * site-wide settings, as the first of them.
         *
         * Previously the five global settings sat *between* the two drag&drop
         * editors in one tall left-hand column, which pushed the detail editor
         * far below the detail preview it belongs to — you could not see the
         * live preview react while dragging. Page slugs only select which
         * do_settings_sections() call renders a section; saving is governed
         * solely by settings_fields(self::PAGE_SLUG) in renderPage().
         */
        /*
         * Vier Gruppen statt der bisherigen drei plus Sammelbecken. Bis 1.5.2
         * gab es einen Abschnitt „Globale Einstellungen", in dem acht Felder
         * lagen, die miteinander wenig zu tun hatten: das Klickverhalten neben
         * dem Eckenstil neben dem Zeitraum pro Seite. Was zusammengehoert,
         * stand auseinander — die Felder der Kachel unter der Detailansicht,
         * die Adresse der Terminseite drei Felder von der Detailansicht
         * entfernt, zu der sie gehoert.
         *
         * Sortiert ist jetzt nach der Frage, die der Betreiber gerade
         * beantwortet:
         *   1. Wie soll es grundsaetzlich aussehen?   (Vorlage, Farben, Ecken)
         *   2. Was steht auf einer Kachel?            (Reihenfolge, Sichtbarkeit, Bildformat)
         *   3. Was passiert beim Klick darauf?        (Detailansicht, Adresse)
         *   4. Wie viel wird auf einmal geladen?      (Zeitraum pro Seite)
         *
         * Seitenslugs waehlen nur aus, welcher do_settings_sections()-Aufruf
         * einen Abschnitt rendert; gespeichert wird weiterhin allein ueber
         * settings_fields(self::PAGE_SLUG) in renderPage().
         */
        $designStylePage = self::PAGE_SLUG . '_design_style';
        add_settings_section('ctp_design_style', __('Stil', 'churchtools-plugin'), [self::class, 'renderDesignStyleIntro'], $designStylePage);
        // Ohne Beschriftungsspalte: Die vier Karten tragen ihren Namen selbst,
        // und eine Spalte mit dem Wort „Vorlage" daneben nimmt ihnen ein Achtel
        // der Breite, ohne etwas zu sagen (siehe .ctp-field--full in admin.css).
        // Die Reihenfolge-Editoren behalten ihre Beschriftung dagegen: In ihren
        // Panels stehen beschriftete Felder daneben, und eine Zeile ohne Label
        // wirkt dort abgerissen.
        add_settings_field('design_preset', __('Vorlage', 'churchtools-plugin'), [$this, 'renderDesignPresetField'], $designStylePage, 'ctp_design_style', ['class' => 'ctp-field--full']);
        // Zweiter Abschnitt auf derselben Seite: Was die Vorlage vorgibt und
        // hier ueberschrieben wird, steht damit unmittelbar unter ihr statt
        // eine Bildschirmhoehe weiter unten.
        add_settings_section('ctp_design_look', __('Farben und Formen', 'churchtools-plugin'), [self::class, 'renderLookIntro'], $designStylePage);
        add_settings_field('corner_style', __('Ecken', 'churchtools-plugin'), [$this, 'renderCornerStyleField'], $designStylePage, 'ctp_design_look');
        add_settings_field('accent_color', __('Akzentfarbe', 'churchtools-plugin'), [$this, 'renderAccentColorField'], $designStylePage, 'ctp_design_look');
        add_settings_field('button_color', __('Buttonfarbe', 'churchtools-plugin'), [$this, 'renderButtonColorField'], $designStylePage, 'ctp_design_look');

        $designTilePage = self::PAGE_SLUG . '_design_tile';
        add_settings_section('ctp_design_order', __('Aufbau der Kachel', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('element_order', __('Reihenfolge', 'churchtools-plugin'), [$this, 'renderElementOrderField'], $designTilePage, 'ctp_design_order');
        // Beide betreffen ausschliesslich die Kachel: Die Sichtbarkeit arbeitet
        // auf CardDesign::TOGGLEABLE_KEYS, und das Seitenverhaeltnis greift nur
        // im Kachelbild (die Detailansicht begrenzt ihr Bild ueber die Hoehe).
        add_settings_field('hidden_elements', __('Ausgeblendete Felder', 'churchtools-plugin'), [$this, 'renderFieldVisibilityField'], $designTilePage, 'ctp_design_order');
        add_settings_field('media_aspect_ratio', __('Bild-Seitenverhältnis', 'churchtools-plugin'), [$this, 'renderMediaAspectRatioField'], $designTilePage, 'ctp_design_order');

        $designDetailPage = self::PAGE_SLUG . '_design_detail';
        add_settings_section('ctp_design_detail_order', __('Aufbau der Detailansicht', 'churchtools-plugin'), '__return_false', $designDetailPage);
        // Das Klickverhalten steht vor der Reihenfolge, weil es die Frage davor
        // beantwortet: Gibt es ueberhaupt eine Detailansicht, und wo oeffnet
        // sie? Die Adresse folgt unmittelbar, sie ist die zweite Haelfte
        // derselben Entscheidung.
        add_settings_field('click_behavior', __('Bei Klick auf eine Kachel', 'churchtools-plugin'), [$this, 'renderClickBehaviorField'], $designDetailPage, 'ctp_design_detail_order');
        add_settings_field('detail_page_id', __('Adresse der Terminseite', 'churchtools-plugin'), [$this, 'renderDetailPageField'], $designDetailPage, 'ctp_design_detail_order');
        add_settings_field('detail_element_order', __('Reihenfolge', 'churchtools-plugin'), [$this, 'renderDetailElementOrderField'], $designDetailPage, 'ctp_design_detail_order');

        $designListPage = self::PAGE_SLUG . '_design_list';
        add_settings_section('ctp_design_list', __('Listen', 'churchtools-plugin'), [self::class, 'renderListIntro'], $designListPage);
        add_settings_field('paging_months', __('Zeitraum pro Seite', 'churchtools-plugin'), [$this, 'renderPagingMonthsField'], $designListPage, 'ctp_design_list');
    }

    public static function defaults(): array
    {
        return [
            'instance' => '',
            'api_key' => '',
            /**
             * Keyed by ChurchTools calendar ID:
             * [ 'name' => string, 'enabled' => bool, 'color' => '#rrggbb',
             *   'default_color' => '#rrggbb' (ChurchTools' own color, for the "reset" button, see renderCalendarCard()),
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
             * resolveRoomsMode().
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
            'click_behavior' => 'popup',
            // 0 = keine Elternseite: Termine behalten die Adresse
            // /churchtools-termin/<id>/, mit der sie bis 1.4.1 ausgeliefert
            // wurden. Bestandsseiten aendern ihre Adressen also nicht von
            // selbst, nur weil aktualisiert wurde.
            'detail_page_id' => 0,
            'detail_element_order' => DetailDesign::DEFAULT_ORDER,
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

    private static function buildBaseUrl(string $instance): string
    {
        return $instance === '' ? '' : "https://{$instance}.church.tools";
    }

    /**
     * The "Verbindung testen" / "Kalender laden" buttons should test whatever is
     * currently typed into the instance/API-key fields — including a value the
     * admin hasn't clicked "Speichern" for yet — falling back to the stored value
     * only where a field was left empty (mirrors the same "empty means keep
     * existing" rule sanitizeSettings() uses when saving the API key).
     */
    private static function effectiveConnection(): array
    {
        $stored = self::get();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- both callers (ajaxTestConnection/ajaxFetchCalendars) already run check_ajax_referer() before reaching this helper.
        $instance = self::sanitizeInstance((string) wp_unslash($_POST['instance'] ?? ''));
        if ($instance === '') {
            $instance = $stored['instance'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
        $apiKey = trim((string) wp_unslash($_POST['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = self::getDecryptedApiKey();
        }

        return [
            'instance' => $instance,
            'api_key' => $apiKey,
            'base_url' => self::buildBaseUrl($instance),
        ];
    }

    /**
     * Was gespeichert wird, wenn im Formular ein API-Key steht.
     *
     * Ein bereits verschluesselter Wert wird durchgereicht statt erneut
     * verschluesselt: Beim allerersten Speichern einer Option laeuft dieser
     * Sanitizer zweimal (update_option() sanitisiert und reicht an
     * add_option() weiter, das erneut sanitisiert - siehe Crypto::PREFIX),
     * der zweite Durchlauf bekommt also die Ausgabe des ersten zu sehen. Ohne
     * diese Abfrage lag der Token danach doppelt verschluesselt in der
     * Datenbank, und jede Anfrage an ChurchTools scheiterte mit
     * "401: No valid token" - waehrend "Verbindung testen" gruen blieb, weil
     * der Test den getippten Wert nimmt und nicht den gespeicherten (siehe
     * effectiveConnection()).
     *
     * Leer heisst weiterhin "bestehenden Wert behalten": Das Feld wird nie mit
     * dem gespeicherten Token vorbefuellt (siehe renderApiKeyField()), ein
     * Speichern aus einem anderen Tab darf ihn also nicht loeschen.
     */
    private static function apiKeyToStore(string $submitted, string $existing): string
    {
        if ($submitted === '') {
            return $existing;
        }

        return Crypto::isCiphertext($submitted) ? $submitted : Crypto::encrypt($submitted);
    }

    public static function getDecryptedApiKey(): string
    {
        return self::storedApiKey();
    }

    /**
     * Der gespeicherte Token, entschluesselt - oder '', wenn dabei nichts
     * Brauchbares herauskommt.
     *
     * Packt dabei aus, was vor 0.12.4 beim allerersten Speichern doppelt
     * verschluesselt wurde (siehe apiKeyToStore(); davor entsteht ein solcher
     * Wert nicht mehr, die bereits gespeicherten tragen das Praefix aber
     * nicht). Einmal entschluesselt kommt bei ihnen der base64-Text der
     * inneren Verschluesselung heraus - druckbar und kurz genug, also fuer
     * isPlausibleApiKey() ein gueltiger Token, der dann als
     * "401: No valid token" bei ChurchTools landete. Hier wird er beim Lesen
     * ausgepackt, damit niemand seinen Token deswegen neu eintragen muss; das
     * naechste Speichern legt ihn ohnehin einfach verschluesselt ab.
     *
     * Ein echter Token entschluesselt sich zu nichts, deshalb entscheidet
     * allein das Ergebnis der zweiten Runde, ob es eine zu entpacken gab.
     */
    private static function storedApiKey(): string
    {
        $stored = self::get()['api_key'];
        if ($stored === '') {
            return '';
        }

        $decrypted = Crypto::decrypt($stored);
        $unwrapped = Crypto::decrypt($decrypted);

        if (self::isPlausibleApiKey($unwrapped)) {
            return $unwrapped;
        }

        return self::isPlausibleApiKey($decrypted) ? $decrypted : '';
    }

    /**
     * Detects a stored, encrypted API key that no longer decrypts to a plausible
     * token — the symptom of an AUTH_KEY rotation (salt change, server move,
     * secrets-management switch), which silently breaks Crypto::decrypt() since the
     * key is derived from AUTH_KEY (see Crypto::key()). A failed openssl_decrypt()
     * already returns '' on its own, but a wrong key can also "succeed" by chance
     * and hand back binary garbage — this used to be sent straight into the
     * Authorization header and fail as a generic, misleading 401 (see the
     * 2026-08-14 "No valid token" incident in plan.md, which had a different root
     * cause but the same confusing symptom).
     */
    public static function apiKeyDecryptionFailed(): bool
    {
        return self::get()['api_key'] !== '' && self::storedApiKey() === '';
    }

    private static function isPlausibleApiKey(string $token): bool
    {
        return $token !== '' && strlen($token) <= 512 && ctype_print($token);
    }

    public static function apiKeyDecryptionErrorMessage(): string
    {
        return __('Der gespeicherte API-Key lässt sich nicht mehr entschlüsseln (z. B. nach einer Änderung von AUTH_KEY) – bitte im Tab „Verbindung“ neu eingeben.', 'churchtools-plugin');
    }

    public static function getEnabledCalendarIds(): array
    {
        $enabled = array_filter(self::get()['calendars'], static fn (array $calendar): bool => !empty($calendar['enabled']));

        return array_map('intval', array_keys($enabled));
    }

    /**
     * Resolves shortcode/block calendar references, which may be a mix of numeric
     * ChurchTools calendar IDs and calendar names, into known calendar IDs. Only
     * calendars the admin has fetched into settings (see ajaxFetchCalendars) can be
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
     * Each admin-UI tab is its own <form> and only posts the fields it renders, so
     * $input only ever contains a subset of the keys below (see tabs()). A key that
     * is entirely absent means "this tab wasn't submitted", not "clear this value" —
     * every branch here must fall back to $existing, not to a hardcoded default.
     *
     * $input is nullable because options.php passes null when the tab's <form> had
     * no ctp_settings[...] field at all — e.g. the Kalender tab before the first
     * successful fetch renders no inputs, only the "Kalender laden" button.
     */
    public static function sanitizeSettings(?array $input): array
    {
        $input ??= [];
        $existing = self::get();
        $apiKey = trim((string) ($input['api_key'] ?? ''));

        $syncInterval = $existing['sync_interval'];
        if (array_key_exists('sync_interval', $input) && in_array($input['sync_interval'], Installer::SYNC_INTERVALS, true)) {
            $syncInterval = $input['sync_interval'];
        }

        $designPreset = $existing['design_preset'];
        if (array_key_exists('design_preset', $input) && in_array($input['design_preset'], DesignPreset::PRESETS, true)) {
            $designPreset = $input['design_preset'];
        }

        $cornerStyle = $existing['corner_style'];
        if (array_key_exists('corner_style', $input) && in_array($input['corner_style'], CardDesign::CORNER_STYLES, true)) {
            $cornerStyle = $input['corner_style'];
        }

        $clickBehavior = $existing['click_behavior'];
        if (array_key_exists('click_behavior', $input) && in_array($input['click_behavior'], ['none', 'popup', 'page'], true)) {
            $clickBehavior = $input['click_behavior'];
        }

        // Muss eine veroeffentlichte Seite sein, sonst 0: Ein Entwurf oder ein
        // Beitrag haette entweder gar keine oeffentliche Adresse oder eine, die
        // WordPress schon selbst belegt. Die Pruefung steht hier und nicht erst
        // beim Rendern, damit die Einstellung nicht still etwas anderes
        // bedeutet, als sie anzeigt.
        $detailPageId = $existing['detail_page_id'];
        if (array_key_exists('detail_page_id', $input)) {
            $candidate = (int) $input['detail_page_id'];
            $usable = $candidate > 0
                && get_post_type($candidate) === 'page'
                && get_post_status($candidate) === 'publish'
                // Startseite und Beitragsseite scheiden aus: Ihre
                // Rewrite-Regel laege ueber der halben Website
                // (Frontend\EventDetailPage::mayHostEvents()).
                && $candidate !== (int) get_option('page_on_front')
                && $candidate !== (int) get_option('page_for_posts');
            $detailPageId = $usable ? $candidate : 0;
        }

        $mediaAspectRatio = $existing['media_aspect_ratio'];
        if (array_key_exists('media_aspect_ratio', $input) && array_key_exists($input['media_aspect_ratio'], CardDesign::MEDIA_ASPECT_RATIOS)) {
            $mediaAspectRatio = $input['media_aspect_ratio'];
        }

        $accentColor = $existing['accent_color'];
        if (array_key_exists('accent_color', $input)) {
            $sanitizedColor = sanitize_hex_color((string) $input['accent_color']);
            if (!empty($sanitizedColor)) {
                $accentColor = $sanitizedColor;
            }
        }

        $buttonColor = $existing['button_color'];
        if (array_key_exists('button_color', $input)) {
            $sanitizedButtonColor = sanitize_hex_color((string) $input['button_color']);
            if (!empty($sanitizedButtonColor)) {
                $buttonColor = $sanitizedButtonColor;
            }
        }

        return [
            'instance' => array_key_exists('instance', $input)
                ? self::sanitizeInstance((string) $input['instance'])
                : $existing['instance'],
            'api_key' => self::apiKeyToStore($apiKey, $existing['api_key']),
            'calendars' => array_key_exists('calendars', $input)
                ? self::sanitizeCalendars((array) $input['calendars'], $existing['calendars'])
                : $existing['calendars'],
            'resources' => array_key_exists('resources', $input)
                ? self::sanitizeResources((array) $input['resources'], $existing['resources'] ?? [])
                : ($existing['resources'] ?? []),
            'rooms_mode' => self::sanitizeRoomsMode($input, $existing),
            'sync_interval' => $syncInterval,
            'sync_days_ahead' => array_key_exists('sync_days_ahead', $input)
                ? max(1, (int) $input['sync_days_ahead'])
                : $existing['sync_days_ahead'],
            'retention_days' => array_key_exists('retention_days', $input)
                ? max(0, (int) $input['retention_days'])
                : $existing['retention_days'],
            'keep_data_on_uninstall' => array_key_exists('keep_data_on_uninstall', $input)
                ? (bool) $input['keep_data_on_uninstall']
                : $existing['keep_data_on_uninstall'],
            'design_preset' => $designPreset,
            'element_order' => array_key_exists('element_order', $input)
                ? self::sanitizeElementOrder(self::orderInput($input['element_order']))
                : $existing['element_order'],
            'corner_style' => $cornerStyle,
            // Checkbox group: renderFieldVisibilityField() prints a hidden
            // "[]" marker before the checkboxes (same trick as
            // keep_data_on_uninstall below) so an all-unchecked submit still
            // arrives as an empty array, not a missing key.
            'hidden_elements' => array_key_exists('hidden_elements', $input)
                ? CardDesign::sanitizeHiddenElements((array) $input['hidden_elements'])
                : $existing['hidden_elements'],
            'media_aspect_ratio' => $mediaAspectRatio,
            'accent_color_enabled' => array_key_exists('accent_color_enabled', $input)
                ? (bool) $input['accent_color_enabled']
                : $existing['accent_color_enabled'],
            'accent_color' => $accentColor,
            'button_color_enabled' => array_key_exists('button_color_enabled', $input)
                ? (bool) $input['button_color_enabled']
                : $existing['button_color_enabled'],
            'button_color' => $buttonColor,
            'click_behavior' => $clickBehavior,
            'detail_page_id' => $detailPageId,
            'detail_element_order' => array_key_exists('detail_element_order', $input)
                ? self::sanitizeDetailElementOrder(self::orderInput($input['detail_element_order']))
                : $existing['detail_element_order'],
            'paging_months' => array_key_exists('paging_months', $input)
                ? EventWindow::sanitizeMonths((int) $input['paging_months'])
                : $existing['paging_months'],
        ];
    }

    /**
     * Beide Reihenfolge-Felder kommen als kommagetrennter String aus ihrem
     * Hidden-Input - beim allerersten Speichern einer Option laeuft dieser
     * Sanitizer aber zweimal (siehe apiKeyToStore()), und im zweiten
     * Durchlauf steht dort die bereits zerlegte Liste des ersten. Ohne diese
     * Umwandlung machte (string) daraus "Array": eine PHP-Warnung mitten in
     * der Antwort auf das Speichern, und aus einer gerade eingestellten
     * Anordnung die Standardanordnung (siehe sanitizeElementOrder(), das
     * einen unbekannten Wert bewusst auf CardDesign::DEFAULT_ORDER schnappen
     * laesst).
     */
    private static function orderInput(mixed $raw): string
    {
        return is_array($raw) ? implode(',', array_map('strval', $raw)) : (string) $raw;
    }

    /**
     * The Design tab's hidden input submits the drag&drop order (the fixed
     * CardDesign::ELEMENT_KEYS plus any admin-inserted spacer-/divider-prefixed
     * separators, see renderElementOrderField()) as a comma-separated string.
     * Unlike every other field in this method, an invalid value here does NOT
     * fall back to $existing — a *present but malformed* value (JS bug,
     * tampered POST, a duplicate/missing/unknown fixed key) snaps straight to
     * CardDesign::DEFAULT_ORDER instead. Falling back to $existing would risk
     * silently keeping a half-applied permutation; the known-good default is
     * the safer failure mode. The ordinary "key entirely absent from $input"
     * case (a different tab's form was submitted) still falls back to
     * $existing in sanitizeSettings() above, same as every other field.
     */
    private static function sanitizeElementOrder(string $raw): array
    {
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        // Drops anything that isn't a plain key/instance-id string (JS only ever
        // generates keys matching this shape) before the permutation check below,
        // rather than letting one garbage entry invalidate an otherwise-valid order.
        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => (bool) preg_match('/^[a-z0-9-]+$/', $key)
        ));

        return CardDesign::isValidOrder($keys) ? $keys : CardDesign::DEFAULT_ORDER;
    }

    /**
     * Same "present but malformed value snaps to the default, absent value falls
     * back to $existing" rule as sanitizeElementOrder() above, for the detail
     * view's own (separator-free) key set.
     */
    private static function sanitizeDetailElementOrder(string $raw): array
    {
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => (bool) preg_match('/^[a-z0-9-]+$/', $key)
        ));

        return DetailDesign::isValidOrder($keys) ? $keys : DetailDesign::DEFAULT_ORDER;
    }

    /**
     * Accepts either a bare instance name ("musterkirche") or a full URL a user
     * might paste by habit ("https://musterkirche.church.tools/") and normalizes
     * both to "musterkirche".
     */
    private static function sanitizeInstance(string $raw): string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('#^https?://#i', '', $raw);
        $raw = (string) preg_replace('#\.church\.tools.*$#i', '', $raw);
        $raw = trim($raw, "/ \t\n\r\0\x0B");

        return (string) preg_replace('/[^a-z0-9-]/', '', strtolower($raw));
    }

    /**
     * The calendar table always posts an entry per known calendar (the checkbox is
     * the only field that can be missing when unchecked), so we only trust rows for
     * IDs we already know about from a previous fetch — the `name` label itself is
     * never taken from user input, it is carried over from $existing.
     */
    /**
     * Zwilling von sanitizeCalendars(): Nur IDs, die schon bekannt sind, kommen
     * durch - alles andere waere ein Formularfeld, das jemand erfunden hat. Name
     * und Sortierschluessel sind keine Eingabefelder, sie stammen aus
     * ChurchTools und werden nur mitgetragen; einstellbar ist genau der Haken.
     */
    private static function sanitizeResources(array $input, array $existing): array
    {
        $resources = [];

        foreach ($input as $id => $row) {
            $id = (int) $id;

            if (!isset($existing[$id])) {
                continue;
            }

            $resources[$id] = [
                'name' => (string) $existing[$id]['name'],
                'enabled' => !empty($row['enabled']),
                'sort_key' => (int) ($existing[$id]['sort_key'] ?? 0),
            ];
        }

        return $resources;
    }

    private static function sanitizeCalendars(array $input, array $existing): array
    {
        $calendars = [];

        foreach ($input as $id => $row) {
            $id = (int) $id;

            if (!isset($existing[$id])) {
                continue;
            }

            $color = sanitize_hex_color((string) ($row['color'] ?? ''));

            $calendars[$id] = [
                'name' => $existing[$id]['name'],
                'enabled' => !empty($row['enabled']),
                'color' => $color ?: $existing[$id]['color'],
                // Not user-editable here (no form field for it) — always carried
                // over from $existing, where mergeCalendars() keeps it in sync
                // with ChurchTools' own color on every "Kalender laden".
                'default_color' => $existing[$id]['default_color'] ?? $existing[$id]['color'],
                'default_image_id' => absint($row['default_image_id'] ?? 0),
                // Ebenfalls kein Formularfeld: Das ist ChurchTools' Angabe,
                // hier waere sie nur eine Meinung. mergeCalendars() haelt sie
                // bei jedem „Kalender laden" aktuell.
                'is_public' => (bool) ($existing[$id]['is_public'] ?? true),
            ];
        }

        return $calendars;
    }

    public function renderInstanceField(): void
    {
        printf(
            '<span class="ctp-instance-row">'
            . '<code>https://</code>'
            . '<input type="text" id="ctp-instance" name="%1$s[instance]" value="%2$s" class="regular-text" placeholder="musterkirche" pattern="[a-z0-9-]+" />'
            . '<code>.church.tools</code>'
            . '</span>'
            . '<p class="description">%3$s</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr(self::get()['instance']),
            esc_html__('Nur der Instanz-Name eintragen, z. B. „musterkirche“ für https://musterkirche.church.tools', 'churchtools-plugin')
        );
    }

    public function renderApiKeyField(): void
    {
        $hasKey = self::get()['api_key'] !== '';

        // „Verbindung testen“ bleibt bewusst am Feld statt in der
        // Aktionsleiste unter der Ueberschrift: der Knopf prueft genau das,
        // was gerade in diesen beiden Feldern steht - auch ungespeichert
        // (siehe effectiveConnection()). Die Rueckmeldung nutzt trotzdem
        // dasselbe .ctp-inline-status-Bauteil wie jede andere Aktion im
        // Backend.
        printf(
            '<span class="ctp-field-with-button">'
            . '<input type="password" id="ctp-api-key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />'
            . '<button type="button" class="button" id="ctp-test-connection">%3$s</button>'
            . '<span class="ctp-inline-status" id="ctp-test-connection-result" role="status" aria-live="polite"></span>'
            . '</span>'
            . '<p class="description">%4$s</p>',
            esc_attr(self::OPTION_KEY),
            $hasKey ? esc_attr__('Hinterlegt – zum Ändern neuen Key eingeben', 'churchtools-plugin') : '',
            esc_html__('Verbindung testen', 'churchtools-plugin'),
            esc_html__('Der Test fragt ChurchTools mit Instanz und Key aus den Feldern oben ab – auch ungespeichert. Leere Felder greifen dabei auf die gespeicherten Werte zurück.', 'churchtools-plugin')
        );
    }

    /**
     * Der Tab „Kalender“ - eine Kachelliste statt der bisherigen Tabelle.
     *
     * Vorher steckte die ganze Kalenderauswahl als *ein* Settings-API-Feld in
     * einer .form-table. Das kostete links rund 200 Pixel fuer eine
     * Beschriftung („Kalender“), die nur die Ueberschrift darueber
     * wiederholte, und presste vier Spalten - Haken, Name, Farbe, Standardbild -
     * in den Rest. Die Farbe war ein 36px-Kaestchen zwischen zwei
     * Bedienelementen, und woran man einen Kalender ueberhaupt erkennt,
     * naemlich wie viele Termine er liefert, stand nirgends.
     *
     * Die Kachelliste dreht das um:
     *   - Die Kalenderfarbe ist der farbige Balken der Kachel, also das, was
     *     man zuerst sieht - nicht mehr ein Kaestchen in Spalte drei.
     *   - Jede Kachel nennt ihre Termine (kommend/gesamt, siehe
     *     EventRepository::countsByCalendar()). Ein Kalender, der seit Monaten
     *     nichts liefert, faellt damit auf.
     *   - Inaktive Kalender sind sichtbar gedimmt, statt sich nur durch einen
     *     leeren Haken ganz links von den aktiven zu unterscheiden.
     *   - Suche und „Alle aktivieren/deaktivieren“ machen die Liste auch bei
     *     zwei Dutzend Kalendern noch bedienbar.
     *   - Jede Kachel liefert den fertigen Shortcode fuer genau diesen
     *     Kalender zum Kopieren - dieselbe Schaltflaeche wie in der
     *     Shortcode-Referenz im Tab „Design“.
     *
     * Kein Settings-API-Abschnitt mehr, sondern direkt gerendert: die
     * Feldnamen sind unveraendert (ctp_settings[calendars][ID][...]), also
     * greifen settings_fields() und sanitizeCalendars() genau wie zuvor.
     */
    /**
     * Angehakte Kalender, die ChurchTools selbst nicht als oeffentlich fuehrt.
     *
     * Bewusst nur gemeldet und nicht stillschweigend uebergangen: Anders als
     * ein interner Termin (den SyncEngine::mapOccurrence() gar nicht erst
     * speichert) ist ein angehakter Kalender eine ausdrueckliche Entscheidung
     * im WordPress-Backend. Verschwaende sein Inhalt wortlos, suchte man den
     * Grund an der falschen Stelle - und die Person, die das Haekchen gesetzt
     * hat, sitzt genau dort, wo dieser Hinweis erscheint.
     *
     * @return array<int, string> Kalender-ID => Name
     */
    public static function nonPublicEnabledCalendars(): array
    {
        $found = [];

        foreach (self::get()['calendars'] as $id => $calendar) {
            if (empty($calendar['enabled'])) {
                continue;
            }

            // Fehlendes Feld gilt als oeffentlich - siehe mergeCalendars().
            if (($calendar['is_public'] ?? true)) {
                continue;
            }

            $found[(int) $id] = (string) ($calendar['name'] ?? (string) $id);
        }

        return $found;
    }

    /**
     * Der Tab „Raeume". Eine Liste mit Haken, sonst nichts - und das ist die
     * ganze Bedienung dieser Funktion.
     *
     * Warum es keine Reihenfolge gibt: Der verworfene Gegenentwurf war eine
     * Prioritaetenliste, aus der bei mehreren gebuchten Raeumen der oberste
     * gewinnt. Sie erreichte mehr Termine, behauptete aber sichtbar Falsches -
     * ein Ferienprogramm mit zehn gebuchten Raeumen erschien unter dem Namen
     * eines Nebenraums, und dieselbe Serie zeigte von Woche zu Woche einen
     * anderen Raum, weil das gebuchte Buendel wechselt. Ein Haken allein sagt
     * genug: „Dieser Raum ist es wert, oeffentlich genannt zu werden."
     */
    private function renderRoomsTab(): void
    {
        $resources = self::get()['resources'] ?? [];

        // ChurchTools' eigene Ordnung: grosse Raeume oben, Testressourcen unten.
        uasort($resources, static function (array $a, array $b): int {
            return [$a['sort_key'] ?? 0, $a['name']] <=> [$b['sort_key'] ?? 0, $b['name']];
        });

        $fetched = (string) get_option(self::OPTION_RESOURCES_FETCHED, '');
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(self::PAGE_SLUG); ?>
                <h2><?php esc_html_e('Räume in der Ortsangabe', 'churchtools-plugin'); ?></h2>

                <p class="description">
                    <?php esc_html_e('ChurchTools führt am Termin eine Adresse – in der Praxis die des Gebäudes – und daneben die Räume, die dafür gebucht werden. Angehakte Räume erscheinen als Ortsangabe, sobald für einen Termin genau einer davon bestätigt gebucht ist. Sind es mehrere, bleibt die Angabe aus: Eine Aufzählung aller gebuchten Räume ist keine Ortsangabe. Ist keiner gebucht, gilt weiterhin die Adresse aus ChurchTools.', 'churchtools-plugin'); ?>
                </p>

                <?php
                self::renderActionBar(
                    'ctp-fetch-resources',
                    __('Räume von ChurchTools laden', 'churchtools-plugin'),
                    __('Jede Synchronisation gleicht die Liste automatisch mit ab – dieser Knopf holt sie sofort. Die Haken bleiben dabei erhalten.', 'churchtools-plugin')
                );
                ?>

                <?php
                /*
                 * Die Raumangabe entsteht beim Sync und steht danach als Wert in
                 * der Termintabelle - anders als eine Kalenderfarbe, die bei
                 * jedem Seitenaufruf neu gerendert wird. Gemeldet wurde das als
                 * „der Wechsel zeigt nicht direkt zu greifen", und der zweite
                 * Anlauf (ein Hinweis plus ein Knopf oben) war auch noch keine
                 * gute Antwort: Nach dem Speichern landet man am Seitenanfang
                 * und musste den Knopf erst suchen.
                 *
                 * Jetzt uebernimmt die Seite selbst. Kommt sie mit
                 * `settings-updated` zurueck - also direkt nach dem Speichern -,
                 * laeuft der Abgleich von allein an, und darunter steht, was
                 * dabei herausgekommen ist. Der Knopf bleibt fuer den Fall, dass
                 * jemand ohne Aenderung nachsehen will.
                 */
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur die Frage „kommt die Seite von einem Speichern?"; WordPress haengt den Parameter selbst an, und ausgeloest wird davon ein Lesevorgang, der auch per Knopf jederzeit erlaubt ist.
                $justSaved = isset($_GET['settings-updated']);
                ?>
                <div class="ctp-rooms-apply" data-auto="<?php echo $justSaved ? '1' : '0'; ?>">
                    <?php
                    self::renderActionBar(
                        'ctp-sync-rooms',
                        __('Änderungen übernehmen', 'churchtools-plugin'),
                        __('Der Ort wird beim Abgleich zum Termin geschrieben, nicht beim Anzeigen – eine Änderung hier wird deshalb erst mit dem nächsten Abgleich sichtbar. Nach dem Speichern startet er von selbst.', 'churchtools-plugin')
                    );
                    ?>
                    <p class="description" id="ctp-rooms-summary"><?php echo esc_html(self::locationSummary()); ?></p>
                </div>

                <?php if ($resources === []) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php esc_html_e('Es sind keine Räume bekannt. Entweder verwendet die Instanz keine Ressourcen, oder der API-Key ist nicht für sie freigegeben – die Freigabe heißt in ChurchTools „Ressource sehen“ und ist von der allgemeinen Berechtigung für das Ressourcen-Modul getrennt.', 'churchtools-plugin'); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <?php if ($fetched !== '') : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: date and time the room list was last fetched */
                                esc_html__('Zuletzt geladen: %s', 'churchtools-plugin'),
                                esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $fetched))
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <table class="widefat striped ctp-rooms-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Als Ortsangabe zeigen', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Raum', 'churchtools-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $id => $resource) : ?>
                                <tr>
                                    <td>
                                        <?php
                                        /*
                                         * Verstecktes Feld vor jedem Kaestchen: Ein
                                         * leeres Kaestchen sendet gar nichts, und ohne
                                         * diese Null waere das Abwaehlen des letzten
                                         * Raums nicht speicherbar - sanitizeSettings()
                                         * saehe dann keinen `resources`-Schluessel und
                                         * truege die alten Haken unveraendert weiter.
                                         */
                                        ?>
                                        <input
                                            type="hidden"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[resources][<?php echo esc_attr((string) $id); ?>][enabled]"
                                            value="0"
                                        >
                                        <input
                                            type="checkbox"
                                            id="ctp-resource-<?php echo esc_attr((string) $id); ?>"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[resources][<?php echo esc_attr((string) $id); ?>][enabled]"
                                            value="1"
                                            <?php checked(!empty($resource['enabled'])); ?>
                                        >
                                    </td>
                                    <td>
                                        <label for="ctp-resource-<?php echo esc_attr((string) $id); ?>">
                                            <?php echo esc_html($resource['name']); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description">
                        <?php esc_html_e('Sparsam anhaken: Je mehr Räume ausgewählt sind, desto häufiger sind mehrere davon gleichzeitig gebucht – und desto öfter bleibt die Ortsangabe deshalb leer. Am Anfang sind die wenigen Räume richtig, die für sich allein einen Termin verorten.', 'churchtools-plugin'); ?>
                    </p>

                    <h3><?php esc_html_e('Wenn für einen Termin mehrere Räume gebucht sind', 'churchtools-plugin'); ?></h3>

                    <?php $mode = self::roomsMode(); ?>
                    <fieldset>
                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_ALL); ?>"
                                    <?php checked($mode, RoomLookup::MODE_ALL); ?>
                                >
                                <?php esc_html_e('Alle ausgewählten Räume nennen, durch Komma getrennt', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Zeigt, was ChurchTools liefert. Die Zeile wächst mit der Auswahl – bei wenigen ausgewählten Räumen bleibt sie kurz, bei vielen kann aus der Ortsangabe eine Aufzählung werden.', 'churchtools-plugin'); ?>
                            </span>
                        </p>

                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_SINGLE); ?>"
                                    <?php checked($mode, RoomLookup::MODE_SINGLE); ?>
                                >
                                <?php esc_html_e('Nur nennen, wenn genau ein ausgewählter Raum gebucht ist', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Nicht ausgewählte Räume zählen dabei nicht mit: Sind daneben weitere belegt, erscheint trotzdem der eine ausgewählte.', 'churchtools-plugin'); ?>
                            </span>
                        </p>

                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_EXCLUSIVE); ?>"
                                    <?php checked($mode, RoomLookup::MODE_EXCLUSIVE); ?>
                                >
                                <?php esc_html_e('Nur nennen, wenn für den Termin sonst kein weiterer Raum gebucht ist', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Die vorsichtigste Einstellung: Jede weitere Buchung lässt die Ortsangabe aus, auch die eines nicht ausgewählten Raums.', 'churchtools-plugin'); ?>
                            </span>
                        </p>
                    </fieldset>
                <?php endif; ?>
            </div>
            <?php $this->renderSaveBar(); ?>
        </form>
        <?php
    }

    private function renderCalendarsTab(): void
    {
        $calendars = self::get()['calendars'];
        uasort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $counts = self::calendarEventCounts();
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
            <?php settings_fields(self::PAGE_SLUG); ?>
            <h2><?php esc_html_e('Kalenderauswahl', 'churchtools-plugin'); ?></h2>
            <?php
            self::renderActionBar(
                'ctp-fetch-calendars',
                __('Kalender von ChurchTools laden', 'churchtools-plugin'),
                __('Jede Synchronisation gleicht die Liste automatisch mit ab – dieser Knopf holt sie sofort. Eingestellte Farben und Standardbilder bleiben dabei erhalten.', 'churchtools-plugin')
            );
            ?>

            <?php $calendarError = SyncEngine::getLastCalendarError(); ?>
            <?php if ($calendarError !== null) : ?>
                <?php
                /*
                 * Der Sync zieht die Kalenderliste inzwischen bei jedem Lauf
                 * mit nach (siehe SyncEngine::refreshCalendarList()). Scheitert
                 * das, laeuft der Terminabgleich trotzdem weiter - dieser
                 * Hinweis ist die einzige Stelle, an der es auffaellt.
                 */
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the calendar refresh last failed, 2: error message */
                            esc_html__('Der automatische Kalenderabgleich ist zuletzt fehlgeschlagen (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $calendarError['time'])),
                            esc_html(wp_html_excerpt($calendarError['message'], 400, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $nonPublic = self::nonPublicEnabledCalendars(); ?>
            <?php if ($nonPublic !== []) : ?>
                <?php // Zahl-neutral formuliert: bin/make-pot.php kann keine Plurale (_n). ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: comma-separated list of calendar names */
                            esc_html__('Hier aktiv, in ChurchTools aber nicht als öffentlich geführt: %s. Die Termine erscheinen trotzdem auf der Website – entweder hier abwählen oder in ChurchTools öffentlich stellen.', 'churchtools-plugin'),
                            esc_html(implode(', ', $nonPublic))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($calendars === []) : ?>
                <p class="ctp-empty-state"><?php esc_html_e('Noch keine Kalender geladen.', 'churchtools-plugin'); ?></p>
            <?php else : ?>
                <div class="ctp-toolbar ctp-toolbar--secondary">
                    <label class="screen-reader-text" for="ctp-calendar-search">
                        <?php esc_html_e('Kalender filtern', 'churchtools-plugin'); ?>
                    </label>
                    <input
                        type="search"
                        id="ctp-calendar-search"
                        class="ctp-calendar-search"
                        placeholder="<?php esc_attr_e('Kalender filtern …', 'churchtools-plugin'); ?>"
                    />
                    <span class="ctp-toolbar__spacer"></span>
                    <button type="button" class="button ctp-calendar-bulk" data-enable="1">
                        <?php esc_html_e('Alle aktivieren', 'churchtools-plugin'); ?>
                    </button>
                    <button type="button" class="button ctp-calendar-bulk" data-enable="0">
                        <?php esc_html_e('Alle deaktivieren', 'churchtools-plugin'); ?>
                    </button>
                </div>

                <ul class="ctp-calendar-grid" id="ctp-calendar-grid">
                    <?php foreach ($calendars as $id => $calendar) : ?>
                        <?php $this->renderCalendarCard((int) $id, $calendar, $counts[(int) $id] ?? ['total' => 0, 'upcoming' => 0]); ?>
                    <?php endforeach; ?>
                </ul>
                <p class="ctp-empty-state" id="ctp-calendar-no-match" hidden>
                    <?php esc_html_e('Kein Kalender passt zu diesem Suchbegriff.', 'churchtools-plugin'); ?>
                </p>

                <p class="description">
                    <?php esc_html_e('Nur aktive Kalender werden synchronisiert. Termine eines gerade deaktivierten Kalenders entfernt der nächste Sync.', 'churchtools-plugin'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('Das Standardbild erscheint überall dort, wo ein Termin dieses Kalenders kein eigenes Bild mitbringt.', 'churchtools-plugin'); ?>
                </p>
            <?php endif; ?>

            </div>
            <?php $this->renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Eine Kalenderkachel. Der farbige Balken oben traegt die Kalenderfarbe,
     * damit sie ohne Umweg ueber ein Bedienelement sichtbar ist; das
     * Farbfeld darunter ist dasselbe Paar aus Farbwaehler und Hex-Feld wie
     * zuvor (siehe .ctp-color-field und den geteilten Inline-Skript-Block in
     * renderPage()).
     *
     * @param array{total: int, upcoming: int} $count
     */
    private function renderCalendarCard(int $id, array $calendar, array $count): void
    {
        $fieldBase = sprintf('%s[calendars][%d]', self::OPTION_KEY, $id);
        $imageId = (int) $calendar['default_image_id'];
        $imageUrl = $imageId ? (string) wp_get_attachment_image_url($imageId, 'thumbnail') : '';
        $enabled = !empty($calendar['enabled']);
        $shortcode = sprintf('[ctp_events calendar="%s"]', $calendar['name']);
        $checkboxId = sprintf('ctp-calendar-enabled-%d', $id);
        ?>
        <li
            class="ctp-calendar-card<?php echo $enabled ? '' : ' is-disabled'; ?>"
            data-name="<?php echo esc_attr(mb_strtolower($calendar['name'])); ?>"
            data-id="<?php echo esc_attr((string) $id); ?>"
        >
            <span class="ctp-calendar-card__bar" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>

            <div class="ctp-calendar-card__head">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($checkboxId); ?>"
                    class="ctp-calendar-enabled"
                    name="<?php echo esc_attr($fieldBase); ?>[enabled]"
                    value="1"
                    <?php checked($enabled); ?>
                />
                <label class="ctp-calendar-card__name" for="<?php echo esc_attr($checkboxId); ?>">
                    <?php echo esc_html($calendar['name']); ?>
                </label>
            </div>

            <p class="ctp-calendar-card__facts">
                <code class="ctp-muted-code">ID <?php echo (int) $id; ?></code>
                <span aria-hidden="true">·</span>
                <?php
                printf(
                    /* translators: 1: number of upcoming events, 2: total number of stored events */
                    esc_html__('%1$d kommend von %2$d gespeicherten Terminen', 'churchtools-plugin'),
                    (int) $count['upcoming'],
                    (int) $count['total']
                );
                ?>
            </p>

            <div class="ctp-calendar-card__row ctp-color-field">
                <span class="ctp-calendar-card__row-label"><?php esc_html_e('Farbe', 'churchtools-plugin'); ?></span>
                <?php
                // Farbwaehler und Hex-Feld sind ein Bedienelement: nur das
                // <input type="color"> traegt einen Namen und wird abgeschickt,
                // das Textfeld ist ein Spiegel, den das Inline-Skript in
                // renderPage() in beide Richtungen nachfuehrt. Wer nach einem
                // Styleguide arbeitet, hat den Hex-Code, und ein nativer
                // Farbwaehler bietet keine Moeglichkeit, ihn einzutippen.
                ?>
                <input
                    type="color"
                    class="ctp-color-input"
                    name="<?php echo esc_attr($fieldBase); ?>[color]"
                    value="<?php echo esc_attr($calendar['color']); ?>"
                    aria-label="<?php esc_attr_e('Farbe wählen', 'churchtools-plugin'); ?>"
                />
                <input
                    type="text"
                    class="ctp-color-hex"
                    value="<?php echo esc_attr($calendar['color']); ?>"
                    maxlength="7"
                    spellcheck="false"
                    autocomplete="off"
                    aria-label="<?php esc_attr_e('Farbe als Hex-Code', 'churchtools-plugin'); ?>"
                />
                <button
                    type="button"
                    class="button-link ctp-color-reset"
                    data-default-color="<?php echo esc_attr($calendar['default_color'] ?? $calendar['color']); ?>"
                    title="<?php esc_attr_e('Auf die Farbe zurücksetzen, die dieser Kalender in ChurchTools hat', 'churchtools-plugin'); ?>"
                >
                    <?php esc_html_e('Zurücksetzen', 'churchtools-plugin'); ?>
                </button>
            </div>

            <div class="ctp-calendar-card__row ctp-image-field">
                <span class="ctp-calendar-card__row-label"><?php esc_html_e('Standardbild', 'churchtools-plugin'); ?></span>
                <input type="hidden" class="ctp-image-id" name="<?php echo esc_attr($fieldBase); ?>[default_image_id]" value="<?php echo esc_attr((string) $imageId); ?>" />
                <img class="ctp-image-preview" src="<?php echo esc_url($imageUrl); ?>" alt="" <?php echo $imageUrl ? '' : 'hidden'; ?> />
                <button type="button" class="button button-small ctp-image-select">
                    <?php echo $imageUrl ? esc_html__('Ersetzen', 'churchtools-plugin') : esc_html__('Bild wählen', 'churchtools-plugin'); ?>
                </button>
                <button type="button" class="button-link ctp-image-remove" <?php echo $imageUrl ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Entfernen', 'churchtools-plugin'); ?>
                </button>
            </div>

            <div class="ctp-calendar-card__row ctp-calendar-card__row--shortcode">
                <code><?php echo esc_html($shortcode); ?></code>
                <button
                    type="button"
                    class="button button-small ctp-copy-shortcode"
                    data-shortcode="<?php echo esc_attr($shortcode); ?>"
                    title="<?php esc_attr_e('Shortcode für genau diesen Kalender kopieren', 'churchtools-plugin'); ?>"
                >
                    <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                </button>
            </div>
        </li>
        <?php
    }

    /**
     * German labels for Installer::SYNC_INTERVALS, shared by the Sync tab's
     * select and the Übersicht tab's "Nächste Synchronisation" card — keyed by
     * the WP-Cron recurrence name the schedule is actually created with.
     */
    private static function syncIntervalLabels(): array
    {
        return [
            'hourly' => __('Stündlich', 'churchtools-plugin'),
            'twicedaily' => __('Zweimal täglich', 'churchtools-plugin'),
            'daily' => __('Täglich', 'churchtools-plugin'),
        ];
    }

    public function renderSyncIntervalField(): void
    {
        $current = self::get()['sync_interval'];

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[sync_interval]">';
        foreach (self::syncIntervalLabels() as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">'
            . esc_html__('Beim Speichern wird der WP-Cron-Termin auf dieses Intervall umgestellt. Wann er tatsächlich feuert, hängt vom Seitenaufkommen ab – siehe Hinweis zu WP-Cron in der readme.txt.', 'churchtools-plugin')
            . '</p>';
    }

    public function renderSyncDaysAheadField(): void
    {
        printf(
            '<input type="number" min="1" name="%1$s[sync_days_ahead]" value="%2$s" class="small-text" /> %3$s',
            esc_attr(self::OPTION_KEY),
            esc_attr((string) self::get()['sync_days_ahead']),
            esc_html__('Tage', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Wie weit im Voraus Termine synchronisiert werden. Betrifft nur den Sync-Zeitraum, nicht die Aufbewahrung vergangener Termine.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Global default for how much of the calendar one page of a list/grid shows
     * before the "Weitere Termine laden" button takes over. Individual
     * shortcodes/blocks/WPBakery elements can override it with months="…",
     * same relationship the click behavior already has.
     */
    public function renderPagingMonthsField(): void
    {
        printf(
            '<input type="number" min="%1$s" max="%2$s" name="%3$s[paging_months]" value="%4$s" class="small-text" /> %5$s',
            esc_attr((string) EventWindow::MIN_MONTHS),
            esc_attr((string) EventWindow::MAX_MONTHS),
            esc_attr(self::OPTION_KEY),
            esc_attr((string) self::get()['paging_months']),
            esc_html__('Monate', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Liste und Grid zeigen zunächst den angebrochenen aktuellen Monat plus so viele weitere Monate.', 'churchtools-plugin')
            . '</p>';
        echo '<ul class="description ctp-hint-list">'
            . '<li>' . esc_html__('„Weitere Termine laden“ hängt jeweils den nächsten Zeitraum an; kürzere Zeiträume laden schneller.', 'churchtools-plugin') . '</li>'
            . '<li>' . esc_html__('Liegt im Zeitraum kein Termin, springt die Ansicht automatisch zum nächsten Monat mit Terminen.', 'churchtools-plugin') . '</li>'
            . '<li>' . esc_html__('Ohne Wirkung auf „Nächster Termin“ – diese Ansicht zeigt weiterhin eine feste Anzahl Termine (Attribut „limit“).', 'churchtools-plugin') . '</li>'
            . '</ul>';
    }

    public function renderRetentionField(): void
    {
        printf(
            '<input type="number" min="0" name="%1$s[retention_days]" value="%2$s" class="small-text" /> %3$s',
            esc_attr(self::OPTION_KEY),
            esc_attr((string) self::get()['retention_days']),
            esc_html__('Tage', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Wie lange bereits vergangene Termine noch gespeichert bleiben, bevor der Sync sie entfernt. 0 = sofort nach Ende des Termins löschen.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Hidden field before the checkbox so an unchecked box still posts
     * "0" — otherwise sanitizeSettings()'s array_key_exists() check would
     * see the key as entirely absent and keep the previous (possibly
     * checked) value instead of actually unchecking it.
     */
    public function renderKeepDataOnUninstallField(): void
    {
        printf('<input type="hidden" name="%1$s[keep_data_on_uninstall]" value="0" />', esc_attr(self::OPTION_KEY));
        printf(
            '<label><input type="checkbox" name="%1$s[keep_data_on_uninstall]" value="1" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_KEY),
            checked(!empty(self::get()['keep_data_on_uninstall']), true, false),
            esc_html__('Termindaten, importierte Bilder und Einstellungen beim Deinstallieren behalten', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Gilt nur für „Deinstallieren“ (Plugin löschen), nicht für „Deaktivieren“. Standardmäßig aus, damit ein versehentliches Löschen keine Daten hinterlässt, die niemand mehr sieht.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * German labels for CardDesign::ELEMENT_KEYS, shared by the drag&drop
     * field and (indirectly, via the same key set) the preview markup below.
     */
    private static function elementOrderLabels(): array
    {
        return [
            'media' => __('Bild (mit Datumsbadge)', 'churchtools-plugin'),
            'calendar' => __('Kalendername', 'churchtools-plugin'),
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'excerpt' => __('Beschreibungsauszug', 'churchtools-plugin'),
            'date' => __('Datum', 'churchtools-plugin'),
            'time' => __('Uhrzeit', 'churchtools-plugin'),
            'location' => __('Ort', 'churchtools-plugin'),
        ];
    }

    /** German labels for CardDesign::SEPARATOR_TYPES, keyed the same way. */
    private static function separatorLabels(): array
    {
        return [
            'divider' => __('Trennlinie', 'churchtools-plugin'),
            'spacer' => __('Abstand', 'churchtools-plugin'),
        ];
    }

    public function renderElementOrderField(): void
    {
        $labels = self::elementOrderLabels();
        $separatorLabels = self::separatorLabels();
        $order = self::get()['element_order'];
        ?>
        <ul
            id="ctp-design-order"
            class="ctp-order-list"
            data-default-order="<?php echo esc_attr(implode(',', CardDesign::DEFAULT_ORDER)); ?>"
        >
            <?php foreach ($order as $key) : ?>
                <?php $isSeparator = CardDesign::isSeparator($key); ?>
                <li
                    draggable="true"
                    data-key="<?php echo esc_attr($key); ?>"
                    class="ctp-order-item<?php echo $isSeparator ? ' ctp-order-item--separator' : ''; ?>"
                >
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                    <?php if ($isSeparator) : ?>
                        <?php echo esc_html($separatorLabels[CardDesign::separatorType($key)] ?? $key); ?>
                        <button
                            type="button"
                            class="ctp-order-item__remove"
                            aria-label="<?php esc_attr_e('Entfernen', 'churchtools-plugin'); ?>"
                        >&times;</button>
                    <?php else : ?>
                        <?php echo esc_html($labels[$key] ?? $key); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <input
            type="hidden"
            id="ctp-design-order-input"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[element_order]"
            value="<?php echo esc_attr(implode(',', $order)); ?>"
        />
        <p class="ctp-order-actions">
            <button type="button" class="button" id="ctp-design-add-divider">
                <?php esc_html_e('+ Trennlinie einfügen', 'churchtools-plugin'); ?>
            </button>
            <button type="button" class="button" id="ctp-design-add-spacer">
                <?php esc_html_e('+ Abstand einfügen', 'churchtools-plugin'); ?>
            </button>
            <?php
            // Getting back to the shipped layout after experimenting used to
            // mean dragging six rows into the right order by hand and deleting
            // every separator individually.
            ?>
            <button type="button" class="button-link ctp-order-reset" data-target="ctp-design-order">
                <?php esc_html_e('Standard wiederherstellen', 'churchtools-plugin'); ?>
            </button>
        </p>
        <?php
        /*
         * Vier Aussagen, die vorher als ein einziger Absatz unter dem Feld
         * standen und dort niemand zu Ende gelesen hat. Als Liste ist jede
         * Regel einzeln auffindbar - der Satz, den man gerade braucht, steht
         * in seiner eigenen Zeile.
         */
        ?>
        <p class="description"><?php esc_html_e('Reihenfolge per Drag&Drop ändern:', 'churchtools-plugin'); ?></p>
        <ul class="description ctp-hint-list">
            <li><?php esc_html_e('Ziehen funktioniert mit Maus und Trackpad – Sortieren per Touch wird derzeit nicht unterstützt.', 'churchtools-plugin'); ?></li>
            <li><?php esc_html_e('Die Bild-Position legt nur fest, ob das Bild über oder unter dem Textblock steht – nicht zwischen einzelnen Textzeilen.', 'churchtools-plugin'); ?></li>
            <li><?php esc_html_e('Trennlinien und Abstände lassen sich beliebig oft einfügen und wie jedes andere Element verschieben.', 'churchtools-plugin'); ?></li>
            <li><?php esc_html_e('Das „×“ an einer Trennlinie oder einem Abstand entfernt sie wieder; die festen Elemente bleiben immer erhalten.', 'churchtools-plugin'); ?></li>
        </ul>
        <?php
    }

    /**
     * Kopf des Sync-Tabs: dieselbe Aktionsleiste wie auf der Uebersicht und im
     * Tab „Kalender“.
     *
     * „Jetzt synchronisieren“ gab es bisher nur auf der Uebersicht. Wer hier
     * gerade Intervall oder Zeitraum geaendert hat, will das Ergebnis sofort
     * sehen und musste dafuer den Tab wechseln.
     */
    public static function renderSyncIntro(): void
    {
        self::renderActionBar(
            'ctp-run-sync',
            __('Jetzt synchronisieren', 'churchtools-plugin'),
            __('Läuft sofort, unabhängig vom Intervall. Änderungen unten vorher speichern.', 'churchtools-plugin')
        );
    }

    /**
     * Section intro for the global block — the one place on this tab where a
     * setting's effect is not visible in a preview right next to it, so it says
     * where to look instead.
     */
    public static function renderDesignStyleIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Die Grundlage für alle Ansichten. Sie legt Rundungen, Schatten, Ränder und das Verhalten beim Überfahren mit der Maus fest – nicht aber, welche Felder erscheinen oder in welcher Reihenfolge. Das entscheiden die beiden Editoren weiter unten.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * German labels and one-line descriptions for DesignPreset::PRESETS —
     * dieselbe Aufteilung wie bei elementOrderLabels(): Die Schlüssel gehören
     * der Frontend-Klasse, die Beschriftungen dieser Admin-Klasse.
     *
     * @return array<string, array{label: string, description: string}>
     */
    private static function designPresetLabels(): array
    {
        return [
            'standard' => [
                'label' => __('Standard', 'churchtools-plugin'),
                'description' => __('Weiche Rundungen, ruhiger Schatten, umrandetes Kalender-Etikett. Die bisherige Optik – wer nichts umstellt, bekommt genau das.', 'churchtools-plugin'),
            ],
            'ruhig' => [
                'label' => __('Ruhig', 'churchtools-plugin'),
                'description' => __('Zurückhaltend und architektonisch: feine Linien statt Schatten, fast keine Rundung, keine Bewegung beim Überfahren. Der Kalendername steht als farbiger Text da.', 'churchtools-plugin'),
            ],
            'warm' => [
                'label' => __('Warm', 'churchtools-plugin'),
                'description' => __('Großzügig und einladend: starke Rundungen, ein weicherer Schatten, mehr Luft zwischen den Kacheln, gefülltes Kalender-Etikett. Beim Überfahren hebt sich die Kachel und das Bild zoomt leicht.', 'churchtools-plugin'),
            ],
            'strukturiert' => [
                'label' => __('Strukturiert', 'churchtools-plugin'),
                'description' => __('Kontrastreich und redaktionell: rechtwinklig, ohne Schatten, dafür eine kräftige Kante in der Kalenderfarbe und deutliche Trennlinien.', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Vier Optionen als anklickbare Karten, jede mit einer eigenen kleinen
     * Vorschau daneben.
     *
     * Die Vorschau ist echtes Frontend-Markup unter der jeweiligen
     * Preset-Klasse, kein nachgebautes Abbild: frontend.css ist auf diesem Tab
     * ohnehin geladen (siehe enqueueAssets()), also zeigt jede Kachel genau
     * die Regeln, die später auch auf der Seite greifen. Ein nachgebautes
     * Abbild wäre die zweite Stelle, an der jedes Preset gepflegt werden
     * müsste – und die erste, die veraltet.
     */
    public function renderDesignPresetField(): void
    {
        $current = DesignPreset::sanitize((string) self::get()['design_preset']);
        $labels = self::designPresetLabels();

        echo '<div class="ctp-preset-grid">';
        foreach (DesignPreset::PRESETS as $preset) {
            $texts = $labels[$preset] ?? ['label' => $preset, 'description' => ''];
            printf(
                '<label class="ctp-preset-option">'
                    . '<input type="radio" name="%1$s[design_preset]" value="%2$s" %3$s class="ctp-preset-input" />'
                    . '<span class="ctp-preset-body">'
                        . '<span class="ctp-preset-swatch ctp-events %4$s" aria-hidden="true">'
                            . '<span class="ctp-events__card">'
                                . '<span class="ctp-preset-swatch__media"></span>'
                                . '<span class="ctp-preset-swatch__text">'
                                    . '<span class="ctp-events__eyebrow">%5$s</span>'
                                    . '<span class="ctp-preset-swatch__line ctp-preset-swatch__line--title"></span>'
                                    . '<span class="ctp-preset-swatch__line"></span>'
                                . '</span>'
                            . '</span>'
                        . '</span>'
                        . '<span class="ctp-preset-name">%6$s</span>'
                        . '<span class="ctp-preset-description">%7$s</span>'
                    . '</span>'
                . '</label>',
                esc_attr(self::OPTION_KEY),
                esc_attr($preset),
                checked($current, $preset, false),
                esc_attr(DesignPreset::bodyClass($preset)),
                esc_html__('Kalender', 'churchtools-plugin'),
                esc_html($texts['label']),
                esc_html($texts['description'])
            );
        }
        echo '</div>';
    }

    /**
     * Der Vorrang zwischen Vorlage und Einzeleinstellung ist die eine Regel,
     * die man hier kennen muss — sie steht deshalb dort, wo man sie braucht,
     * und nicht in der Beschreibung eines der drei Felder.
     */
    public static function renderLookIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Gilt über der gewählten Vorlage: Was hier eingestellt ist, setzt sich gegen sie durch – wer „Eckig“ wählt, bekommt eckige Ecken auch in einer Vorlage mit runden. Für alle Ansichten und jeden Shortcode, Block und WPBakery-Eintrag.', 'churchtools-plugin')
            . '</p>';
    }

    public static function renderListIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Betrifft Liste und Grid: wie viel auf einmal geladen wird und was der Knopf „Weitere Termine laden“ nachholt. Ohne Einfluss auf „Nächster Termin“ – dort zählt die Anzahl, nicht der Zeitraum.', 'churchtools-plugin')
            . '</p>';
    }

    public function renderCornerStyleField(): void
    {
        $current = self::get()['corner_style'];
        $options = [
            'rounded' => __('Rund', 'churchtools-plugin'),
            'square' => __('Eckig', 'churchtools-plugin'),
        ];

        foreach ($options as $value => $label) {
            printf(
                '<label class="ctp-radio-inline"><input type="radio" name="%1$s[corner_style]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_KEY),
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label)
            );
        }
    }

    /**
     * Hidden "[]" marker before the checkboxes, same reasoning as
     * renderKeepDataOnUninstallField()'s single hidden input: without it, an
     * all-unchecked submit posts no "hidden_elements" key at all, which
     * sanitizeSettings() would read as "this tab wasn't submitted" and keep
     * whatever was previously hidden instead of actually clearing it.
     */
    public function renderFieldVisibilityField(): void
    {
        $labels = self::elementOrderLabels();
        $hidden = self::get()['hidden_elements'];
        printf('<input type="hidden" name="%1$s[hidden_elements][]" value="" />', esc_attr(self::OPTION_KEY));
        foreach (CardDesign::TOGGLEABLE_KEYS as $key) {
            printf(
                '<label class="ctp-checkbox-block"><input type="checkbox" class="ctp-design-visibility-input" name="%1$s[hidden_elements][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_KEY),
                esc_attr($key),
                checked(in_array($key, $hidden, true), true, false),
                esc_html($labels[$key] ?? $key)
            );
        }
        echo '<p class="description">'
            . esc_html__('Angehakt heißt ausgeblendet: Dieses Feld erscheint dann nicht mehr auf der Kachel. Der Titel bleibt immer sichtbar. Popup und eigene Seite bleiben davon unberührt – dort bestimmt „Aufbau der Detailansicht“, welche Felder erscheinen.', 'churchtools-plugin')
            . '</p>';
    }

    public function renderMediaAspectRatioField(): void
    {
        $current = self::get()['media_aspect_ratio'];
        $options = [
            'wide' => __('Breit – 16:9 (Standard)', 'churchtools-plugin'),
            'square' => __('Quadratisch – 1:1', 'churchtools-plugin'),
            'tall' => __('Hoch – 4:5', 'churchtools-plugin'),
        ];

        echo '<select id="ctp-design-media-ratio" name="' . esc_attr(self::OPTION_KEY) . '[media_aspect_ratio]">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">'
            . esc_html__('Seitenverhältnis des Bildes in Grid-Kachel und Hero („Nächster Termin“). Ohne Wirkung in der Listenansicht, die kein Bild zeigt.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * "Zusätzlich zur Kalenderfarbe" (see plan.md): a per-event calendar
     * color, when set, is written as an inline --ctp-accent on a more
     * specific element than this setting's own inline style (see
     * CardDesign class docblock/frontend.css), so it keeps winning the CSS
     * cascade automatically — no extra precedence logic needed here, this
     * setting only ever supplies the fallback for events whose calendar has
     * no color of its own.
     */
    public function renderAccentColorField(): void
    {
        $settings = self::get();
        printf('<input type="hidden" name="%1$s[accent_color_enabled]" value="0" />', esc_attr(self::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-accent-enabled" name="%1$s[accent_color_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_KEY),
            checked(!empty($settings['accent_color_enabled']), true, false),
            esc_html__('Eigene Akzentfarbe verwenden', 'churchtools-plugin')
        );
        // Same swatch + hex-field pair as the calendar rows (see
        // renderCalendarCard()), wrapped in the .ctp-color-field the shared
        // inline script keys its two-way sync off.
        printf(
            '<p class="ctp-color-field">'
            . '<input type="color" id="ctp-design-accent-color" class="ctp-color-input" name="%1$s[accent_color]" value="%2$s" aria-label="%3$s" %5$s />'
            . '<input type="text" class="ctp-color-hex" value="%2$s" maxlength="7" spellcheck="false" autocomplete="off" aria-label="%4$s" %5$s />'
            . '<button type="button" class="button-link ctp-color-reset" data-default-color="%6$s" %5$s>%7$s</button>'
            . '</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['accent_color']),
            esc_attr__('Akzentfarbe wählen', 'churchtools-plugin'),
            esc_attr__('Akzentfarbe als Hex-Code', 'churchtools-plugin'),
            disabled(empty($settings['accent_color_enabled']), true, false),
            esc_attr(self::defaults()['accent_color']),
            esc_html__('Zurücksetzen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Ersetzt die vom Theme übernommene Standardfarbe für Icons, Datumsbadges und Ränder sowie die aktiven Buttons des Eventfinders. Termine, deren Kalender bereits eine eigene Farbe hat, behalten weiterhin diese Kalenderfarbe.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Sibling of renderAccentColorField() above, for the one thing the accent
     * deliberately no longer controls: the interactive chrome. The two are
     * separate settings because --ctp-accent doubles as a *calendar's*
     * identity color (each card re-sets it inline), so it could never be a
     * reliable button color — see frontend.css's --ctp-color-button-* block.
     */
    public function renderButtonColorField(): void
    {
        $settings = self::get();
        printf('<input type="hidden" name="%1$s[button_color_enabled]" value="0" />', esc_attr(self::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-button-enabled" name="%1$s[button_color_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_KEY),
            checked(!empty($settings['button_color_enabled']), true, false),
            esc_html__('Eigene Buttonfarbe verwenden', 'churchtools-plugin')
        );
        printf(
            '<p class="ctp-color-field">'
            . '<input type="color" id="ctp-design-button-color" class="ctp-color-input" name="%1$s[button_color]" value="%2$s" aria-label="%3$s" %5$s />'
            . '<input type="text" class="ctp-color-hex" value="%2$s" maxlength="7" spellcheck="false" autocomplete="off" aria-label="%4$s" %5$s />'
            . '<button type="button" class="button-link ctp-color-reset" data-default-color="%6$s" %5$s>%7$s</button>'
            . '</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['button_color']),
            esc_attr__('Buttonfarbe wählen', 'churchtools-plugin'),
            esc_attr__('Buttonfarbe als Hex-Code', 'churchtools-plugin'),
            disabled(empty($settings['button_color_enabled']), true, false),
            esc_attr(self::defaults()['button_color']),
            esc_html__('Zurücksetzen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Gilt für die Buttons des Eventfinders, „Weitere Termine laden“ und den Schließen-Knopf des Popups – und zwar für deren gefüllten Zustand: ausgewählt beziehungsweise unter dem Mauszeiger. Im Ruhezustand bleiben sie hell mit dünnem Rand. Die Schriftfarbe auf der gefüllten Fläche wird automatisch auf Schwarz oder Weiß gesetzt, je nachdem, was besser lesbar ist.', 'churchtools-plugin')
            . '</p>';
    }

    public function renderClickBehaviorField(): void
    {
        $current = self::get()['click_behavior'];
        $options = [
            'none' => __('Keine – Kacheln bleiben wie bisher unklickbar', 'churchtools-plugin'),
            'popup' => __('Popup – öffnet die Details in einem Fenster auf derselben Seite', 'churchtools-plugin'),
            'page' => __('Eigene Seite – verlinkt auf eine eigene Termin-URL', 'churchtools-plugin'),
        ];

        foreach ($options as $value => $label) {
            printf(
                '<label class="ctp-radio-block"><input type="radio" name="%1$s[click_behavior]" value="%2$s" class="ctp-design-click-input" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_KEY),
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label)
            );
        }
        echo '<p class="description">'
            . esc_html__('Gilt für jeden Shortcode/Block/WPBakery-Eintrag, sofern dort nicht per Attribut "click" explizit überschrieben (siehe Referenz unten).', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Wählt die Seite, unter deren Adresse die Termine liegen. Zwei Dinge
     * hängen daran, und das zweite ist das wichtigere:
     *
     *   - die Adresse: /termine/gottesdienst-06-09-2026/ statt
     *     /churchtools-termin/4021/
     *   - die Einbettung ins Theme: Mit Elternseite liefert WordPress eine
     *     ganz normale Seite aus, wir tauschen nur ihren Inhalt. Ohne
     *     Elternseite gibt es keinen echten Beitrag, und auf einem Block-Theme
     *     bekommt die Termin-Adresse dann weder dessen Vorlage noch dessen
     *     Kopf- und Fußbereich (siehe Frontend\EventDetailPage).
     */
    public function renderDetailPageField(): void
    {
        $settings = self::get();
        $current = (int) $settings['detail_page_id'];

        /*
         * phpcs kennt wp_dropdown_pages() als ausgebende Funktion und verlangt
         * deshalb escapte Argumente. Die besorgt hier WordPress selbst: name,
         * id, class und option_none_value laufen dort durch esc_attr(), die
         * Seitentitel durch esc_html(). Ausgerechnet show_option_none wird
         * *nicht* escapt (wp-includes/post-template.php, Zeile mit
         * `$parsed_args['show_option_none']` im Options-Markup) - deshalb steht
         * darin unten esc_html__() statt __(), und nur deshalb ist die
         * Abschaltung hier vollständig.
         */
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_dropdown_pages([
            'name' => self::OPTION_KEY . '[detail_page_id]',
            'selected' => $current,
            'show_option_none' => esc_html__('— Keine — eigene Adresse ohne Elternseite', 'churchtools-plugin'),
            'option_none_value' => '0',
            'post_status' => 'publish',
            // Gar nicht erst zur Auswahl stellen, was der Sanitizer daneben
            // ohnehin ablehnen muesste — siehe dort.
            'exclude' => implode(',', array_filter([
                (int) get_option('page_on_front'),
                (int) get_option('page_for_posts'),
            ])),
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($current > 0 && get_post_status($current) === 'publish') {
            printf(
                '<p class="description">%s <code>%s</code></p>',
                esc_html__('Termine liegen dann unter:', 'churchtools-plugin'),
                esc_html(trailingslashit((string) get_permalink($current)) . 'gottesdienst-06-09-2026/')
            );
        }

        echo '<p class="description">'
            . esc_html__('Die Seite bleibt normal erreichbar und behält ihren Inhalt – nur wenn ein Termin an ihre Adresse angehängt ist, zeigt sie diesen Termin statt ihres eigenen. Bisherige Adressen leiten dauerhaft weiter.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Empfehlung: setzen. Ohne Elternseite gibt es für den Termin keinen echten WordPress-Beitrag, und auf einem Block-Theme fehlen der Seite dann die Vorlage des Themes samt Kopf- und Fußbereich. Das ist auch die Seite, auf der ein Suchtreffer landet – Termine sind für Suchmaschinen auffindbar, gleich welche Adresse sie haben. Startseite und Beitragsseite stehen nicht zur Wahl – ihre Adressregel läge über der halben Website.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * German labels for DetailDesign::ELEMENT_KEYS. Same shape as
     * elementOrderLabels() above, but describing the detail view's own field
     * set (full description instead of an excerpt, no separate calendar/media
     * split needed beyond what the card already establishes).
     */
    private static function detailElementOrderLabels(): array
    {
        return [
            'media' => __('Bild', 'churchtools-plugin'),
            'calendar' => __('Kalendername', 'churchtools-plugin'),
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'date' => __('Datum', 'churchtools-plugin'),
            'time' => __('Uhrzeit', 'churchtools-plugin'),
            'location' => __('Ort', 'churchtools-plugin'),
            'description' => __('Beschreibung', 'churchtools-plugin'),
        ];
    }

    public function renderDetailElementOrderField(): void
    {
        $labels = self::detailElementOrderLabels();
        $order = self::get()['detail_element_order'];
        ?>
        <ul
            id="ctp-design-detail-order"
            class="ctp-order-list"
            data-default-order="<?php echo esc_attr(implode(',', DetailDesign::DEFAULT_ORDER)); ?>"
        >
            <?php foreach ($order as $key) : ?>
                <li draggable="true" data-key="<?php echo esc_attr($key); ?>" class="ctp-order-item">
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                    <?php echo esc_html($labels[$key] ?? $key); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <input
            type="hidden"
            id="ctp-design-detail-order-input"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[detail_element_order]"
            value="<?php echo esc_attr(implode(',', $order)); ?>"
        />
        <p class="ctp-order-actions">
            <button type="button" class="button-link ctp-order-reset" data-target="ctp-design-detail-order">
                <?php esc_html_e('Standard wiederherstellen', 'churchtools-plugin'); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e('Reihenfolge der Felder in Popup und eigener Seite, per Drag&Drop änderbar (Maus/Trackpad).', 'churchtools-plugin'); ?>
        </p>
        <?php
    }

    /**
     * Reuses the real grid-card markup/classes with placeholder content, so
     * the preview reflects the actual rendering rules instead of a
     * hand-drawn approximation. Accent/surface colors fall back to this
     * stylesheet's plain defaults (not the active theme's Global Styles)
     * because --wp--preset--color--* custom properties are only emitted on
     * the frontend, not in wp-admin — expected, not a bug, since color isn't
     * part of what this feature controls.
     */
    private function renderDesignPreview(): void
    {
        $settings = self::get();
        $style = CardDesign::styleAttribute(
            $settings['element_order'],
            $settings['corner_style'],
            $settings['media_aspect_ratio'],
            $settings['accent_color_enabled'] ? $settings['accent_color'] : '',
            $settings['button_color_enabled'] ? $settings['button_color'] : ''
        );
        $hidden = $settings['hidden_elements'];
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Vorschau als Grid-Kachel – die Einstellung gilt gleichermaßen für Grid, Liste und „Nächster Termin“.', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-events ctp-events--grid ctp-design-preview-frame <?php echo esc_attr(DesignPreset::bodyClass((string) $settings['design_preset'])); ?>" id="ctp-design-preview" style="<?php echo esc_attr($style); ?>">
                <ul class="ctp-events__list">
                    <li>
                        <article class="ctp-events__card">
                            <div class="ctp-events__media" data-key="media" <?php echo in_array('media', $hidden, true) ? 'hidden' : ''; ?>>
                                <span class="ctp-events__date-badge" aria-hidden="true">
                                    <span class="ctp-events__day">24</span>
                                    <span class="ctp-events__month"><?php esc_html_e('Dez', 'churchtools-plugin'); ?></span>
                                </span>
                            </div>
                            <div class="ctp-events__content" id="ctp-design-preview-content">
                                <span class="ctp-events__eyebrow" data-key="calendar" <?php echo in_array('calendar', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Beispiel-Kalender', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__title">
                                    <?php esc_html_e('Beispiel-Termin', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__subtitle" data-key="subtitle" <?php echo in_array('subtitle', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Untertitel-Beispiel', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--date" data-key="date" <?php echo in_array('date', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::calendar(); ?>
                                    24.12.2026
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--time" data-key="time" <?php echo in_array('time', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::clock(); ?>
                                    18:00–20:00
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--location" data-key="location" <?php echo in_array('location', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::location(); ?>
                                    <?php esc_html_e('Gemeindehaus', 'churchtools-plugin'); ?>
                                </span>
                                <p class="ctp-events__excerpt" data-key="excerpt" <?php echo in_array('excerpt', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Kurzer Auszug aus der Terminbeschreibung, wie er auf der Kachel erscheint …', 'churchtools-plugin'); ?>
                                </p>
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup. ?>
                                <?php echo CardDesign::renderSeparators($settings['element_order']); ?>
                            </div>
                        </article>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Preview for the detail view (popup/own page), rendered in the
     * detail_element_order's actual server-side order — unlike the card
     * preview above, no CSS `order` custom properties are involved (see
     * DetailDesign docblock), so admin-design.js's drag handler just
     * re-appends these same placeholder blocks in the new order instead of
     * mirroring CSS var math.
     */
    private function renderDetailPreview(): void
    {
        $settings = self::get();
        $order = DetailDesign::isValidOrder($settings['detail_element_order'])
            ? $settings['detail_element_order']
            : DetailDesign::DEFAULT_ORDER;
        // Only the corner style is legible in this preview (element order is
        // server-rendered here, see the docblock), but it's the same style
        // attribute the card preview gets — cheaper than a second code path,
        // and admin-design.js keeps both frames in sync on change.
        $style = CardDesign::styleAttribute(
            $settings['element_order'],
            $settings['corner_style'],
            $settings['media_aspect_ratio'],
            $settings['accent_color_enabled'] ? $settings['accent_color'] : '',
            $settings['button_color_enabled'] ? $settings['button_color'] : ''
        );

        $blocks = [
            'media' => '<div class="ctp-events__detail-media" aria-hidden="true">'
                . '<div class="ctp-events__detail-media-frame ctp-design-preview-block__media"></div></div>',
            'calendar' => '<span class="ctp-events__eyebrow">'
                . esc_html__('Beispiel-Kalender', 'churchtools-plugin') . '</span>',
            'title' => '<h2 class="ctp-events__detail-title">' . esc_html__('Beispiel-Termin', 'churchtools-plugin') . '</h2>',
            'subtitle' => '<p class="ctp-events__subtitle">' . esc_html__('Untertitel-Beispiel', 'churchtools-plugin') . '</p>',
            'date' => '<p class="ctp-events__meta-item ctp-events__meta-item--date">'
                . Icons::calendar() . ' 24.12.2026</p>',
            'time' => '<p class="ctp-events__meta-item ctp-events__meta-item--time">'
                . Icons::clock() . ' 18:00–20:00</p>',
            'location' => '<p class="ctp-events__meta-item ctp-events__meta-item--location">'
                . Icons::location() . ' ' . esc_html__('Gemeindehaus', 'churchtools-plugin') . '</p>',
            'description' => '<div class="ctp-events__detail-description"><p>'
                . esc_html__('Vollständige Terminbeschreibung, wie sie in Popup und eigener Seite erscheint …', 'churchtools-plugin') . '</p></div>',
        ];
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau Detailansicht', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Gilt gleichermaßen für Popup und eigene Seite, sofern das Klickverhalten nicht auf „Keine“ steht.', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-design-preview-backdrop">
                <div
                    class="ctp-events ctp-events__detail ctp-design-preview-frame <?php echo esc_attr(DesignPreset::bodyClass((string) $settings['design_preset'])); ?>"
                    id="ctp-design-detail-preview"
                    style="<?php echo esc_attr($style); ?>"
                >
                    <?php foreach ($order as $key) : ?>
                        <div data-key="<?php echo esc_attr($key); ?>">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $blocks entries are built above from esc_html()/esc_html__()-wrapped strings plus Icons::, same trust boundary as the rest of this admin-only preview markup. ?>
                            <?php echo $blocks[$key] ?? ''; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Reference panel for the design tab: the design settings above (element
     * order, corner style) apply to every [ctp_events] shortcode automatically,
     * so this is a natural place to also show how to actually place one. The
     * first example uses a real, currently-enabled calendar when one exists
     * (same idea as the live example already shown at the bottom of the
     * Kalender tab) instead of a made-up placeholder name.
     */
    /**
     * Die Leiste am Fuss jedes Einstellungsformulars. Sie klebt am unteren
     * Fensterrand,
     * und das ist der ganze Zweck: Der Speichern-Knopf stand unter allem
     * anderen. Im Design-Tab war das am deutlichsten — wer oben zwischen den
     * vier Vorlagen wechselte, sah ihn nicht, und die Vorschauen daneben
     * schalten sofort um; es sah also aus, als waere schon gespeichert. Auf
     * den uebrigen Tabs ist derselbe Knopf dieselbe Sucherei, nur ohne die
     * Verwechslung obendrauf.
     *
     * Der Zustand daneben ist keine Verzierung, sondern die Antwort auf genau
     * diese Verwechslung: „Nicht gespeicherte Aenderungen", sobald ein Feld
     * angefasst wurde (assets/js/admin-design.js setzt die Klasse).
     */
    private function renderSaveBar(): void
    {
        ?>
        <div class="ctp-save-bar">
            <p class="ctp-save-bar__state">
                <span class="ctp-save-bar__saved"><?php esc_html_e('Keine offenen Änderungen', 'churchtools-plugin'); ?></span>
                <span class="ctp-save-bar__dirty"><?php esc_html_e('Nicht gespeicherte Änderungen', 'churchtools-plugin'); ?></span>
            </p>
            <?php submit_button(__('Änderungen speichern', 'churchtools-plugin'), 'primary', 'submit', false); ?>
        </div>
        <?php
    }

    /**
     * Der Tab „Einbinden": die Shortcode-Referenz, die bis 1.5.2 unter dem
     * Design-Tab hing.
     *
     * Sie gehoerte dort nie hin. Man liest sie, waehrend man eine *Seite*
     * baut, nicht waehrend man das Aussehen einstellt — und sie war das
     * laengste Stueck des ohnehin laengsten Bildschirms, sodass die
     * Design-Einstellungen darueber im Scrollen verschwanden. Ein eigener Tab
     * ist auffindbar (anders als WordPress' eingeklappte „Hilfe" oben rechts)
     * und nimmt dem Design-Tab seine halbe Hoehe.
     */
    private function renderEmbedTab(): void
    {
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Drei Wege, dieselbe Darstellung', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Termine lassen sich per Shortcode, über den Gutenberg-Block „ChurchTools Events“ oder über das WPBakery-Element „ChurchTools Events“ einbinden. Alle drei rendern dasselbe – was im Tab „Design“ eingestellt ist, gilt für jeden von ihnen, ohne ein weiteres Attribut. Die Optionen unten überschreiben diese Einstellungen nur für den einen Baustein, in dem sie stehen.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Block und WPBakery-Element bieten dieselben Optionen in ihrer eigenen Seitenleiste an. Zu eigenen Theme-Templates siehe readme.txt.', 'churchtools-plugin'); ?>
            </p>
        </div>
        <?php $this->renderShortcodeReference(); ?>
        <?php
    }

    private function renderShortcodeReference(): void
    {
        $calendars = self::get()['calendars'];
        $enabledIds = self::getEnabledCalendarIds();
        $exampleCalendar = '';
        if ($enabledIds !== []) {
            $firstId = $enabledIds[0];
            $exampleCalendar = $calendars[$firstId]['name'] ?? (string) $firstId;
        }

        $examples = [
            [
                'label' => __('Liste', 'churchtools-plugin'),
                'code' => $exampleCalendar !== ''
                    ? sprintf('[ctp_events calendar="%s" layout="list"]', $exampleCalendar)
                    : '[ctp_events layout="list"]',
            ],
            [
                'label' => __('Liste mit Filter, Suche & Monatstrennern', 'churchtools-plugin'),
                'code' => '[ctp_events layout="list" filter="1" search="1" month_dividers="1"]',
            ],
            [
                'label' => __('Grid mit Eventfinder', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" eventfinder="1"]',
            ],
            [
                'label' => __('Grid', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" columns="3"]',
            ],
            [
                'label' => __('Kurzer Teaser ohne Nachladen', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" limit="3" paging="0"]',
            ],
            [
                'label' => __('Nächster Termin', 'churchtools-plugin'),
                'code' => '[ctp_events layout="upcoming" limit="4"]',
            ],
        ];
        ?>
        <?php // Beispiele zuerst: Wer hier landet, will meistens etwas kopieren
        // und nicht nachschlagen. Die vollstaendige Attributliste steht im
        // Panel darunter, fuer die selteneren Faelle. ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Beispiele zum Kopieren', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Fertige Shortcodes für die häufigsten Fälle – der erste aktive Kalender dieser Instanz ist bereits eingesetzt.', 'churchtools-plugin'); ?>
            </p>
            <ul class="ctp-shortcode-examples">
                <?php foreach ($examples as $example) : ?>
                    <li>
                        <span class="ctp-shortcode-label"><?php echo esc_html($example['label']); ?></span>
                        <code><?php echo esc_html($example['code']); ?></code>
                        <button type="button" class="button button-small ctp-copy-shortcode" data-shortcode="<?php echo esc_attr($example['code']); ?>">
                            <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Alle Attribute', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Jedes Attribut ist optional. Weggelassen gilt der Standard aus der rechten Spalte – und wo dort auf den Tab „Design“ verwiesen wird, die dortige Einstellung.', 'churchtools-plugin'); ?>
            </p>

            <table class="widefat striped ctp-borderless">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Attribut', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Beschreibung', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Standard', 'churchtools-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>calendar</code></td>
                        <td><?php esc_html_e('Kommagetrennte Kalender-IDs und/oder -Namen. Leer = alle aktiven Kalender.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>layout</code></td>
                        <td><code>list</code> &middot; <code>grid</code> &middot; <code>upcoming</code></td>
                        <td><code>list</code></td>
                    </tr>
                    <tr>
                        <td><code>limit</code></td>
                        <td>
                            <?php esc_html_e('Obergrenze für die Anzahl Termine. 0 = unbegrenzt; bei list/grid entscheidet der Zeitraum, wie viel angezeigt wird, und limit wirkt nur als Deckel pro Nachlade-Schritt. Bei upcoming die Gesamtzahl inkl. Hero-Kachel (0 = 10).', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>months</code></td>
                        <td>
                            <?php
                            printf(
                                /* translators: %d: globally configured number of months per page. */
                                esc_html__('Zeitraum pro Seite in Monaten (nur list/grid). 0 = die Einstellung im Tab „Design“ (aktuell %d).', 'churchtools-plugin'),
                                (int) self::get()['paging_months']
                            );
                            ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>paging</code></td>
                        <td><?php esc_html_e('Button „Weitere Termine laden“ anzeigen (nur list/grid). 0 = nur der erste Zeitraum, ohne Nachladen.', 'churchtools-plugin'); ?></td>
                        <td><code>1</code></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Nur bei Grid-Layout relevant: Spaltenzahl auf breiten Bildschirmen (2–6)', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
                    </tr>
                    <tr>
                        <td><code>click</code></td>
                        <td>
                            <code>default</code> &middot; <code>none</code> &middot; <code>popup</code> &middot; <code>page</code>
                            &ndash; <?php esc_html_e('überschreibt das Klickverhalten aus dem Tab „Design“ nur für diesen Shortcode', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>default</code></td>
                    </tr>
                    <tr>
                        <td><code>filter</code></td>
                        <td>
                            <?php esc_html_e('Kalenderfilter-Dropdown anzeigen (nur list/grid, nur bei ≥2 Kalendern im Ergebnis)', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>search</code></td>
                        <td><?php esc_html_e('Freitext-Suchleiste anzeigen (nur list/grid, filtert Titel/Untertitel/Ort)', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>month_dividers</code></td>
                        <td><?php esc_html_e('Termine nach Monat gruppiert darstellen (nur list/grid)', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>eventfinder</code></td>
                        <td>
                            <?php esc_html_e('Geführte Auswahl: Knöpfe für Thema und Zeitraum plus Suche (nur list/grid); ersetzt filter/search statt zusätzlich dazu angezeigt zu werden', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Die Zahlen, aus denen jede Statuszeile im Backend gebaut wird - einmal
     * an einer Stelle berechnet, damit „Letzte Synchronisation“ auf der
     * Uebersicht, im Tab „Verbindung“ und im Tab „Synchronisation“ nicht drei
     * verschiedene Antworten geben kann. Vorher stand dieselbe Rechnung nur in
     * renderStatusOverview(), und jeder weitere Tab haette sie kopieren
     * muessen.
     *
     * @return array<string, mixed>
     */
    private static function statusFacts(): array
    {
        $settings = self::get();
        $calendars = $settings['calendars'];
        $enabled = array_filter($calendars, static fn (array $calendar): bool => !empty($calendar['enabled']));
        $lastSync = (string) get_option('ctp_last_sync', '');
        $nextSync = wp_next_scheduled('ctp_run_sync');
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        $apiKey = self::getDecryptedApiKey();

        return [
            'settings' => $settings,
            'calendars' => $calendars,
            'calendar_count' => count($calendars),
            'enabled_count' => count($enabled),
            'configured' => $settings['instance'] !== '' && $apiKey !== '',
            // Hinterlegt, aber nicht mehr lesbar - der Befund nach einer
            // AUTH_KEY-Rotation (siehe apiKeyDecryptionFailed()). Hier aus dem
            // bereits entschluesselten Wert abgeleitet, statt ein zweites Mal
            // durch Crypto::decrypt() zu gehen.
            'api_key_broken' => $settings['api_key'] !== '' && $apiKey === '',
            'last_sync' => $lastSync,
            'last_sync_label' => $lastSync !== ''
                ? (string) mysql2date($dateFormat, $lastSync)
                : __('noch nie', 'churchtools-plugin'),
            // wp_date(), nicht mysql2date(): wp_next_scheduled() liefert einen
            // UTC-Zeitstempel, und wp_date() ist die Funktion, die so einen
            // Zeitstempel in der Zeitzone der Seite ausgibt.
            'next_sync_label' => $nextSync !== false
                ? (string) wp_date($dateFormat, $nextSync)
                : __('nicht geplant', 'churchtools-plugin'),
            'next_sync_scheduled' => $nextSync !== false,
            'date_format' => $dateFormat,
        ];
    }

    /**
     * Termine je Kalender, einmal pro Seitenaufbau. Der Tab „Kalender“ braucht
     * dieselbe Auskunft zweimal - fuer die Statuszeile oben und fuer die Zahl
     * auf jeder Kachel -, und ohne diesen Zwischenspeicher liefe dafuer
     * zweimal dieselbe Abfrage.
     *
     * @return array<int, array{total: int, upcoming: int}>
     */
    private static function calendarEventCounts(): array
    {
        static $counts = null;

        if ($counts === null) {
            $counts = (new EventRepository())->countsByCalendar();
        }

        return $counts;
    }

    /**
     * Liest die von WordPress selbst gepflegte Update-Pruefung (befuellt vom
     * GitHubUpdateChecker, siehe includes/Update/) statt bei jedem Aufruf einer
     * Admin-Seite eine frische Anfrage an GitHub zu schicken.
     *
     * @return array{version: ?string, checked: int}
     */
    private static function updateStatus(): array
    {
        $pluginFile = plugin_basename(CTP_PLUGIN_FILE);
        $updatePlugins = get_site_transient('update_plugins');

        return [
            'version' => is_object($updatePlugins) && isset($updatePlugins->response[$pluginFile]->new_version)
                ? (string) $updatePlugins->response[$pluginFile]->new_version
                : null,
            'checked' => is_object($updatePlugins) && isset($updatePlugins->last_checked)
                ? (int) $updatePlugins->last_checked
                : 0,
        ];
    }

    /**
     * Die eine Statuszeile, die auf jedem Tab gleich aussieht.
     *
     * Vorher gab es dieses Kachelraster zweimal wortgleich im Quelltext (Tab
     * „Uebersicht“ und Tab „Events“) und auf allen uebrigen Tabs gar nicht -
     * wer im Tab „Kalender“ oder „Synchronisation“ wissen wollte, wann zuletzt
     * etwas importiert wurde, musste dafuer den Tab wechseln. Jetzt baut jeder
     * Tab, an dem Daten aus ChurchTools hereinkommen, seine Zeile aus derselben
     * Funktion, und sie steht ueberall an derselben Stelle: direkt unter der
     * Tab-Navigation, ueber dem eigentlichen Inhalt.
     *
     * Die Breite ist die der Panels darunter, damit alles auf derselben
     * rechten Kante endet. Bis 1.6.0 gab es dafuer einen $wide-Schalter, weil
     * Formulartabs 960px breit waren und der Rest 1400px; seit es nur noch
     * eine Panelbreite gibt, ist der Schalter entfallen.
     *
     * `swatch` ersetzt bei Bedarf das Dashicon durch einen Farbpunkt; die
     * Akzentfarbe im Tab „Design“ ist als Hex-Code allein nicht ablesbar.
     *
     * @param array<int, array{icon: string, value: string, label: string, tone?: string, swatch?: string}> $cards
     */
    private static function renderStatStrip(array $cards): void
    {
        if ($cards === []) {
            return;
        }
        ?>
        <div class="ctp-status-strip">
            <div class="ctp-stat-grid">
                <?php foreach ($cards as $card) : ?>
                    <?php $tone = $card['tone'] ?? ''; ?>
                    <div class="ctp-stat-card<?php echo $tone !== '' ? ' ctp-stat-card--' . esc_attr($tone) : ''; ?>">
                        <?php if (!empty($card['swatch'])) : ?>
                            <span class="ctp-stat-card__swatch" style="background-color:<?php echo esc_attr($card['swatch']); ?>" aria-hidden="true"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-<?php echo esc_attr($card['icon']); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="ctp-stat-card__value"><?php echo esc_html($card['value']); ?></span>
                        <span class="ctp-stat-card__label"><?php echo esc_html($card['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Statuszeile fuer den Tab, auf dem sie gerade gebraucht wird. Ein Schalter
     * statt fuenf Aufrufstellen, damit renderPage() nicht fuer jeden Tab eine
     * eigene Zeile Sonderbehandlung bekommt und ein neuer Tab hier automatisch
     * mitgedacht wird.
     */
    private static function renderTabStatus(string $tab): void
    {
        $facts = self::statusFacts();
        $settings = $facts['settings'];

        switch ($tab) {
            case 'status':
                self::renderStatStrip(self::statusCardsOverview($facts));

                return;
            case 'connection':
                self::renderStatStrip([
                    [
                        'icon' => 'admin-links',
                        'value' => $settings['instance'] !== '' ? $settings['instance'] : '—',
                        'label' => __('Instanz', 'churchtools-plugin'),
                        'tone' => $settings['instance'] !== '' ? 'ok' : 'warn',
                    ],
                    [
                        'icon' => 'lock',
                        'value' => $facts['api_key_broken']
                            ? __('nicht lesbar', 'churchtools-plugin')
                            : ($settings['api_key'] !== ''
                                ? __('hinterlegt', 'churchtools-plugin')
                                : __('fehlt', 'churchtools-plugin')),
                        'label' => __('API-Key', 'churchtools-plugin'),
                        'tone' => $facts['api_key_broken']
                            ? 'error'
                            : ($settings['api_key'] !== '' ? 'ok' : 'warn'),
                    ],
                    [
                        'icon' => 'calendar-alt',
                        'value' => sprintf(
                            /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                            __('%1$d von %2$d', 'churchtools-plugin'),
                            $facts['enabled_count'],
                            $facts['calendar_count']
                        ),
                        'label' => __('Aktive Kalender', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'update',
                        'value' => $facts['last_sync_label'],
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                    ],
                // Schmal: darunter steht das Verbindungsformular, kein
                // Spalteninhalt.
                ], false);

                return;
            case 'calendars':
                $counts = self::calendarEventCounts();
                $fetched = (string) get_option(self::OPTION_CALENDARS_FETCHED, '');
                $enabledIds = self::getEnabledCalendarIds();
                $eventsFromEnabled = 0;
                foreach ($enabledIds as $enabledId) {
                    $eventsFromEnabled += $counts[$enabledId]['total'] ?? 0;
                }

                self::renderStatStrip([
                    [
                        'icon' => 'calendar-alt',
                        'value' => (string) $facts['calendar_count'],
                        'label' => __('Bekannte Kalender', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'yes-alt',
                        'value' => sprintf(
                            /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                            __('%1$d von %2$d', 'churchtools-plugin'),
                            $facts['enabled_count'],
                            $facts['calendar_count']
                        ),
                        'label' => __('Zur Synchronisation aktiviert', 'churchtools-plugin'),
                        'tone' => $facts['enabled_count'] > 0 ? 'ok' : 'warn',
                    ],
                    [
                        'icon' => 'list-view',
                        'value' => (string) $eventsFromEnabled,
                        'label' => __('Termine aus aktiven Kalendern', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'download',
                        'value' => $fetched !== ''
                            ? (string) mysql2date($facts['date_format'], $fetched)
                            : __('noch nie', 'churchtools-plugin'),
                        'label' => __('Kalenderliste zuletzt geladen', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'sync':
                self::renderStatStrip([
                    [
                        'icon' => 'update',
                        'value' => $facts['last_sync_label'],
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'clock',
                        'value' => $facts['next_sync_label'],
                        'label' => sprintf(
                            /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                            __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                            self::syncIntervalLabels()[$settings['sync_interval']] ?? $settings['sync_interval']
                        ),
                        'tone' => $facts['next_sync_scheduled'] ? '' : 'error',
                    ],
                    [
                        'icon' => 'list-view',
                        'value' => (string) (new EventRepository())->count(),
                        'label' => __('Gespeicherte Termine', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'calendar-alt',
                        'value' => self::dayCountLabel((int) $settings['sync_days_ahead']),
                        'label' => __('Sync-Zeitraum', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'backup',
                        'value' => (int) $settings['retention_days'] === 0
                            ? __('sofort löschen', 'churchtools-plugin')
                            : self::dayCountLabel((int) $settings['retention_days']),
                        'label' => __('Aufbewahrung nach Event-Ende', 'churchtools-plugin'),
                    ],
                // Schmal, wie das Sync-Formular darunter.
                ], false);

                return;
            case 'updates':
                $update = self::updateStatus();
                $hasUpdate = $update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>');

                self::renderStatStrip([
                    [
                        'icon' => 'admin-plugins',
                        'value' => CTP_VERSION,
                        'label' => __('Installierte Version', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'cloud-upload',
                        'value' => $hasUpdate ? (string) $update['version'] : __('aktuell', 'churchtools-plugin'),
                        'label' => __('Verfügbare Version', 'churchtools-plugin'),
                        'tone' => $hasUpdate ? 'warn' : 'ok',
                    ],
                    [
                        'icon' => 'clock',
                        'value' => $update['checked'] > 0
                            ? (string) wp_date($facts['date_format'], $update['checked'])
                            : __('noch nie', 'churchtools-plugin'),
                        'label' => __('Letzte Update-Prüfung', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'randomize',
                        'value' => __('GitHub Releases', 'churchtools-plugin'),
                        'label' => __('Bezugsquelle', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'design':
                self::renderStatStrip(self::statusCardsDesign($settings));

                return;
        }
    }

    /**
     * Die fuenf Entscheidungen, die auf dem Design-Tab gelten, als Statuszeile -
     * in derselben Form wie auf jedem anderen Tab.
     *
     * Sie beantwortet die Frage, die man beim Aufschlagen des Tabs hat: was
     * ist hier gerade eingestellt? Vorher stand die Antwort verstreut in vier
     * Bedienelementen, von denen drei erst nach dem Scrollen an den Fuss der
     * Seite sichtbar waren.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string, swatch?: string}>
     */
    private static function statusCardsDesign(array $settings): array
    {
        $clickLabels = [
            'none' => __('Keine', 'churchtools-plugin'),
            'popup' => __('Popup', 'churchtools-plugin'),
            'page' => __('Eigene Seite', 'churchtools-plugin'),
        ];
        $cornerLabels = [
            'rounded' => __('Rund', 'churchtools-plugin'),
            'square' => __('Eckig', 'churchtools-plugin'),
        ];
        $ratioLabels = [
            'wide' => __('Breit · 16:9', 'churchtools-plugin'),
            'square' => __('Quadratisch · 1:1', 'churchtools-plugin'),
            'tall' => __('Hoch · 4:5', 'churchtools-plugin'),
        ];
        $accentEnabled = !empty($settings['accent_color_enabled']);
        $buttonEnabled = !empty($settings['button_color_enabled']);

        return [
            [
                'icon' => 'admin-links',
                'value' => $clickLabels[$settings['click_behavior']] ?? $settings['click_behavior'],
                'label' => __('Klickverhalten', 'churchtools-plugin'),
            ],
            [
                'icon' => 'grid-view',
                'value' => $cornerLabels[$settings['corner_style']] ?? $settings['corner_style'],
                'label' => __('Ecken', 'churchtools-plugin'),
            ],
            [
                // Farbpunkt statt Dashicon, sobald eine eigene Farbe gesetzt
                // ist - ein Hex-Code allein sagt niemandem, welche Farbe das
                // ist. Ohne eigene Farbe gibt es nichts zu zeigen, dann bleibt
                // es beim Symbol.
                'icon' => 'art',
                'swatch' => $accentEnabled ? (string) $settings['accent_color'] : '',
                'value' => $accentEnabled
                    ? (string) $settings['accent_color']
                    : __('vom Theme', 'churchtools-plugin'),
                'label' => __('Akzentfarbe', 'churchtools-plugin'),
            ],
            [
                // Ohne eigene Buttonfarbe steht hier nicht „vom Theme“ wie bei
                // der Akzentfarbe: die Buttons erben in dem Fall keine
                // Theme-Farbe, sie bleiben hell mit dünnem Rand und werden im
                // gefüllten Zustand schwarz (siehe renderButtonColorField()).
                'icon' => 'button',
                'swatch' => $buttonEnabled ? (string) $settings['button_color'] : '',
                'value' => $buttonEnabled
                    ? (string) $settings['button_color']
                    : __('Standard', 'churchtools-plugin'),
                'label' => __('Buttonfarbe', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => $ratioLabels[$settings['media_aspect_ratio']] ?? $settings['media_aspect_ratio'],
                'label' => __('Bild-Seitenverhältnis', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Die fuenf Kacheln der Uebersicht - als eigene Funktion, weil
     * renderTabStatus() sie nur durchreicht und die Liste sonst den
     * switch-Block ueberwuchern wuerde.
     *
     * @param array<string, mixed> $facts
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    private static function statusCardsOverview(array $facts): array
    {
        $settings = $facts['settings'];

        return [
            [
                'icon' => 'admin-links',
                'value' => $settings['instance'] !== '' ? $settings['instance'] : '—',
                'label' => __('Instanz', 'churchtools-plugin'),
                'tone' => $facts['configured'] ? 'ok' : 'warn',
            ],
            [
                'icon' => 'calendar-alt',
                'value' => sprintf(
                    /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                    __('%1$d von %2$d', 'churchtools-plugin'),
                    $facts['enabled_count'],
                    $facts['calendar_count']
                ),
                'label' => __('Aktive Kalender', 'churchtools-plugin'),
                'tone' => $facts['enabled_count'] > 0 ? '' : 'warn',
            ],
            [
                'icon' => 'update',
                'value' => $facts['last_sync_label'],
                'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
            ],
            [
                'icon' => 'list-view',
                'value' => (string) (new EventRepository())->count(),
                'label' => __('Gespeicherte Termine', 'churchtools-plugin'),
            ],
            [
                // „Letzte Synchronisation“ allein kann „lief vor einer Stunde,
                // die naechste steht gleich an“ nicht von „der Cron-Eintrag ist
                // verschwunden, diese Zahl bewegt sich nie wieder“ unterscheiden -
                // und genau das ist der Ausfall, dem dieses Plugin am staerksten
                // ausgesetzt ist (siehe WP-Cron-Hinweis in der readme.txt).
                'icon' => 'clock',
                'value' => $facts['next_sync_label'],
                'label' => sprintf(
                    /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                    __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                    self::syncIntervalLabels()[$settings['sync_interval']] ?? $settings['sync_interval']
                ),
                'tone' => $facts['next_sync_scheduled'] ? '' : 'error',
            ],
        ];
    }

    /**
     * „1 Tag“ statt „1 Tage“, ohne die Plural-Funktion von WordPress: der
     * Extraktor in bin/make-pot.php kennt nur __()/esc_html__() und bricht bei
     * Plural- oder Kontextaufrufen ausdruecklich ab (siehe seine eigene
     * Fehlermeldung). Deutsch braucht genau diese eine Unterscheidung, also
     * kostet der Verzicht hier nichts.
     */
    private static function dayCountLabel(int $days): string
    {
        if ($days === 1) {
            return __('1 Tag', 'churchtools-plugin');
        }

        return sprintf(
            /* translators: %d: number of days */
            __('%d Tage', 'churchtools-plugin'),
            $days
        );
    }

    /**
     * Aktionsleiste unter einer Panel-Ueberschrift: genau ein Knopf plus die
     * Statuszeile, in der seine AJAX-Antwort landet.
     *
     * Vorher stand jeder dieser Knoepfe woanders - „Jetzt synchronisieren“ ganz
     * unten im Panel der Uebersicht, „Kalender laden“ oben in einer
     * Formularzelle, und die Rueckmeldung war jedes Mal ein nackter
     * <span> ohne erkennbaren Erfolgs- oder Fehlerzustand. Jetzt sitzt die
     * Leiste ueberall an derselben Stelle (direkt unter der Ueberschrift, ueber
     * dem Inhalt, den sie veraendert) und die Rueckmeldung ist ueberall
     * dasselbe Bauteil (.ctp-inline-status, siehe ctpSetStatus() in
     * renderPage()).
     */
    private static function renderActionBar(string $buttonId, string $label, string $hint = ''): void
    {
        ?>
        <div class="ctp-toolbar">
            <button type="button" class="button button-primary" id="<?php echo esc_attr($buttonId); ?>">
                <?php echo esc_html($label); ?>
            </button>
            <span class="ctp-inline-status" id="<?php echo esc_attr($buttonId); ?>-result" role="status" aria-live="polite"></span>
            <?php if ($hint !== '') : ?>
                <span class="ctp-toolbar__hint"><?php echo esc_html($hint); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Der Tab „Updates“ - seit dem Wegfall des GitHub-Tokens ohne eine einzige
     * Einstellung, dafuer mit der Auskunft, fuer die man ihn tatsaechlich
     * aufsucht: Steht ein Update an, wann wurde zuletzt nachgesehen, und was
     * hat sich in den letzten Versionen geaendert.
     *
     * Der Changelog stand vorher nur als fuenfzeiliger Auszug der *einen*
     * aktuellen Version auf der Uebersicht. Wer nach einem Update wissen
     * wollte, was zwei Versionen zurueck passiert ist, musste dafuer die
     * CHANGELOG.md im Repository aufmachen.
     */
    private function renderUpdatesTab(): void
    {
        $update = self::updateStatus();
        $hasUpdate = $update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>');
        $releases = self::changelogReleases();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Plugin-Updates über GitHub', 'churchtools-plugin'); ?></h2>
            <?php
            self::renderActionBar(
                'ctp-check-updates',
                __('Jetzt auf Updates prüfen', 'churchtools-plugin'),
                __('Verwirft die zwischengespeicherte Update-Prüfung von WordPress und fragt GitHub sofort neu.', 'churchtools-plugin')
            );
            ?>

            <?php if ($hasUpdate) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: newer version number available via GitHub */
                            esc_html__('Version %s steht bereit. Einspielen lässt sie sich über die Plugins-Übersicht von WordPress.', 'churchtools-plugin'),
                            esc_html((string) $update['version'])
                        );
                        ?>
                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                            <?php esc_html_e('Zur Plugins-Übersicht', 'churchtools-plugin'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to the plugin's GitHub repository */
                    esc_html__('Dieses Plugin liegt nicht auf WordPress.org, sondern bezieht seine Updates aus den GitHub-Releases von %s. Das Repository ist öffentlich – es ist kein Zugangstoken nötig.', 'churchtools-plugin'),
                    '<a href="' . esc_url(self::REPO_URL) . '" target="_blank" rel="noopener noreferrer">wirsindcgks/churchtools-plugin</a>'
                );
                ?>
            </p>

            <p class="ctp-quicklinks">
                <a href="<?php echo esc_url(self::REPO_URL); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
                    <?php esc_html_e('Repository auf GitHub', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'releases'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
                    <?php esc_html_e('Alle Releases', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'blob/main/CHANGELOG.md'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-media-text" aria-hidden="true"></span>
                    <?php esc_html_e('Vollständiger Changelog', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'issues'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                    <?php esc_html_e('Problem melden', 'churchtools-plugin'); ?>
                </a>
            </p>
        </div>

        <?php if ($releases !== []) : ?>
            <div class="ctp-panel">
                <h2><?php esc_html_e('Änderungen der letzten Versionen', 'churchtools-plugin'); ?></h2>
                <?php foreach ($releases as $release) : ?>
                    <div class="ctp-release">
                        <h3 class="ctp-release__head">
                            <span class="ctp-release__version">
                                <?php echo esc_html($release['version']); ?>
                            </span>
                            <?php if ($release['version'] === CTP_VERSION) : ?>
                                <span class="ctp-release__badge"><?php esc_html_e('installiert', 'churchtools-plugin'); ?></span>
                            <?php endif; ?>
                            <?php if ($release['date'] !== '') : ?>
                                <span class="ctp-release__date"><?php echo esc_html($release['date']); ?></span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($release['items'] === []) : ?>
                            <p class="ctp-empty-state"><?php esc_html_e('Keine Stichpunkte hinterlegt.', 'churchtools-plugin'); ?></p>
                        <?php else : ?>
                            <ul class="ctp-changelog-excerpt ctp-changelog-excerpt--full">
                                <?php foreach ($release['items'] as $item) : ?>
                                    <li>
                                        <strong><?php echo esc_html($item['lead']); ?></strong>
                                        <?php if ($item['text'] !== '') : ?>
                                            <span class="ctp-release__detail"><?php echo esc_html($item['text']); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Landing tab (see DEFAULT_TAB): bundles what was previously scattered
     * across the Verbindung/Sync/Updates tabs into a single at-a-glance
     * overview, per the "Welcome/Status-Seite"-idea in plan.md.
     */
    private function renderStatusOverview(): void
    {
        $facts = self::statusFacts();
        $settings = $facts['settings'];
        $lastError = SyncEngine::getLastError();

        // Derselbe Befund, den SyncHealthNotice auf jeder anderen Admin-Seite
        // meldet - hier, weil dieser Tab das Ziel seines Links ist und der
        // Hinweis sich dort ausblendet. Ohne das waere die Uebersicht die eine
        // Seite, auf der ein stehengebliebener Sync unerwaehnt bleibt.
        $health = SyncHealthNotice::problem($settings);
        $update = self::updateStatus();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Verbindung & Betrieb', 'churchtools-plugin'); ?></h2>
            <?php
            /*
             * Aktionsleiste direkt unter der Ueberschrift, nicht mehr am Fuss
             * des Panels: dieselbe Position, an der auch die Tabs „Kalender“
             * und „Synchronisation“ ihre Aktion anbieten (siehe
             * renderActionBar()).
             */
            self::renderActionBar(
                'ctp-run-sync',
                __('Jetzt synchronisieren', 'churchtools-plugin'),
                __('Holt die Termine aller aktiven Kalender sofort, unabhängig vom eingestellten Intervall.', 'churchtools-plugin')
            );
            ?>
            <?php if ($lastError !== null) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the sync last failed, 2: error message */
                            esc_html__('Letzter Sync-Fehler (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date($facts['date_format'], $lastError['time'])),
                            // Client::excerpt() kuerzt neue Meldungen bereits an
                            // der Quelle; in ctp_last_sync_error kann aus der Zeit
                            // davor aber noch eine komplette HTML-Fehlerseite
                            // liegen, und die schoebe diesen Kasten ueber die
                            // ganze Seite.
                            esc_html(wp_html_excerpt($lastError['message'], 600, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php elseif (!$facts['configured']) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to the "Verbindung" tab */
                            esc_html__('Noch keine Instanz/API-Key hinterlegt. Im %s eintragen.', 'churchtools-plugin'),
                            '<a href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'connection'], admin_url('admin.php'))) . '">'
                                . esc_html__('Verbindung-Tab', 'churchtools-plugin') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ($health !== null) : ?>
                <div class="notice notice-<?php echo esc_attr($health['type']); ?> inline">
                    <p><?php echo esc_html($health['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php $calendarError = SyncEngine::getLastCalendarError(); ?>
            <?php if ($calendarError !== null) : ?>
                <?php
                /*
                 * Eigener Kasten neben dem Sync-Fehler darueber, nicht statt
                 * seiner: Der Kalenderabgleich scheitert unabhaengig vom
                 * Terminabgleich und haelt ihn nicht auf (siehe
                 * SyncEngine::refreshCalendarList()). Bisher stand dieser
                 * Befund nur im Tab „Kalender“ - wer hier nachsah, warum
                 * nichts synchronisiert wird, fand eine Seite ohne jeden
                 * Fehler, waehrend die Kalenderliste in Wahrheit seit Stunden
                 * nicht mehr geholt werden konnte.
                 */
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the calendar refresh last failed, 2: error message */
                            esc_html__('Der Kalenderabgleich ist zuletzt fehlgeschlagen (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date($facts['date_format'], $calendarError['time'])),
                            esc_html(wp_html_excerpt($calendarError['message'], 600, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php esc_html_e('WP-Cron ist per DISABLE_WP_CRON deaktiviert. Der geplante Sync läuft dann nur, wenn ein System-Cronjob wp-cron.php regelmäßig aufruft (siehe readme.txt).', 'churchtools-plugin'); ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php
            /*
             * Die naechsten Handgriffe als Linkzeile statt als Fliesstext: von
             * der Uebersicht aus fuehrt jeder Weg ohnehin in einen anderen Tab,
             * und der Weg dorthin war bisher nur der Tab-Reiter selbst.
             */
            ?>
            <p class="ctp-quicklinks">
                <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'calendars'], admin_url('admin.php'))); ?>">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Kalender auswählen', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'sync'], admin_url('admin.php'))); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Sync-Einstellungen', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::eventsOverviewUrl()); ?>">
                    <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                    <?php esc_html_e('Gespeicherte Termine ansehen', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'design'], admin_url('admin.php'))); ?>">
                    <span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
                    <?php esc_html_e('Darstellung anpassen', 'churchtools-plugin'); ?>
                </a>
            </p>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Version', 'churchtools-plugin'); ?></h2>
            <table class="widefat striped ctp-borderless ctp-keyvalue-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Installiert', 'churchtools-plugin'); ?></th>
                        <td><?php echo esc_html(CTP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Verfügbar', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if ($update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>')) : ?>
                                <?php
                                printf(
                                    /* translators: %s: newer version number available via GitHub */
                                    esc_html__('%s (Update über die Plugins-Übersicht einspielen)', 'churchtools-plugin'),
                                    esc_html($update['version'])
                                );
                                ?>
                            <?php else : ?>
                                <?php esc_html_e('aktuell', 'churchtools-plugin'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php $latest = self::changelogReleases(1, 5); ?>
            <?php if ($latest !== []) : ?>
                <h3><?php esc_html_e('Letzte Änderungen', 'churchtools-plugin'); ?></h3>
                <?php // Nur die Kurzfassung - die Erklaerung dazu steht im Tab „Updates“. ?>
                <ul class="ctp-changelog-excerpt">
                    <?php foreach ($latest[0]['items'] as $item) : ?>
                        <li><?php echo esc_html($item['lead']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="description">
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'updates'], admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Alle Änderungen im Tab „Updates“', 'churchtools-plugin'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Liest die obersten Release-Bloecke der CHANGELOG.md (liegt im
     * Release-Zip, siehe .github/release-excludes.txt) und gibt sie als
     * Version, Datum und Stichpunkte zurueck.
     *
     * Kein Markdown-Betrachter, sondern genau so viel Auswertung, wie die
     * beiden Ansichten brauchen: die Uebersicht zeigt den obersten Block als
     * kurze Liste, der Tab „Updates“ die letzten drei Versionen mit
     * Ueberschrift. Erwartete Ueberschriftform ist die von Keep a Changelog,
     * die diese Datei durchgehend benutzt: `## [0.9.2] - 2026-08-18`.
     * Die `### Added`/`### Changed`-Zwischenueberschriften werden bewusst
     * uebergangen - im Backend interessiert, *was* sich geaendert hat, nicht
     * in welche Kategorie es faellt.
     *
     * @return array<int, array{version: string, date: string, items: array<int, array{lead: string, text: string}>}>
     */
    private static function changelogReleases(int $maxReleases = 3, int $maxItems = 6): array
    {
        $path = CTP_PLUGIN_DIR . 'CHANGELOG.md';
        if (!is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $releases = [];
        $current = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $releases[] = $current;

                    if (count($releases) >= $maxReleases) {
                        return $releases;
                    }
                }

                preg_match('/^## \[?([^\]\s]+)\]?(?:\s*[-–]\s*(.+))?$/u', $line, $matches);
                $current = [
                    'version' => trim($matches[1] ?? ''),
                    'date' => trim($matches[2] ?? ''),
                    'items' => [],
                ];

                continue;
            }

            if ($current !== null && str_starts_with($line, '- ') && count($current['items']) < $maxItems) {
                $current['items'][] = self::splitChangelogLine(substr($line, 2));
            }
        }

        if ($current !== null) {
            $releases[] = $current;
        }

        return $releases;
    }

    /**
     * Zerlegt eine Changelog-Zeile in Kurzfassung und Erklaerung.
     *
     * Jeder Eintrag in der CHANGELOG.md ist nach demselben Muster gebaut:
     * ein fett gesetzter Satz, der sagt was sich geaendert hat, dann der
     * Absatz, der sagt warum. Genau an dieser Naht wird hier getrennt - die
     * Uebersicht zeigt nur die Kurzfassung, der Tab „Updates“ beides.
     *
     * Vorher lief beides als eine Zeichenkette durch: die Sternchen und
     * Backticks standen roh im Text, und weil ein einzelner Eintrag einen
     * ganzen Absatz lang sein kann, musste hart nach 160 Zeichen abgeschnitten
     * werden - womit auf beiden Seiten die Haelfte fehlte.
     *
     * Zwei Schreibweisen kommen in der Datei vor, der Punkt innerhalb der
     * Fettung (`**… sagt es.**`) und der Doppelpunkt danach
     * (`**… funktionslos**:`); die Trennstelle liegt in beiden Faellen an den
     * schliessenden Sternchen.
     *
     * @return array{lead: string, text: string}
     */
    private static function splitChangelogLine(string $line): array
    {
        // Backticks weg: Markdown-Code-Auszeichnung, die im Backend nichts
        // auszeichnet, sondern nur als Zeichen dasteht.
        $line = trim((string) preg_replace('/`(.+?)`/u', '$1', $line));

        if (preg_match('/^\*\*(.+?)\*\*(.*)$/us', $line, $matches) !== 1) {
            // Auch hier Fettungen entfernen, nicht nur im Zweig darunter: Eine
            // Zeile, die *nicht* mit einem fetten Satz beginnt, kann trotzdem
            // eine mittendrin haben - und die stand vorher als "**" im Backend.
            $line = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $line);

            return ['lead' => mb_strimwidth($line, 0, 200, '…'), 'text' => ''];
        }

        $lead = trim($matches[1]);
        // Fuehrende Satzzeichen des Uebergangs (":" oder ",") gehoeren zur
        // Naht, nicht zum Folgesatz.
        $text = trim((string) preg_replace('/^[\s:,–-]+/u', '', $matches[2]));
        // Weitere Fettungen mitten im Text tragen im Backend nichts bei.
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);

        // Der fette Satz endet in der Datei mal mit Punkt, mal ohne - hier
        // immer mit, damit die Kurzfassungen untereinander gleich aussehen.
        if ($lead !== '' && !preg_match('/[.!?:]$/u', $lead)) {
            $lead .= '.';
        }

        return ['lead' => $lead, 'text' => $text];
    }

    /** Rows per page in the Events tab's table (see renderEventsOverview()). */
    private const EVENTS_PER_PAGE = 25;

    /**
     * The Events tab's current filter state, read straight off the query
     * string. Every value is whitelisted/clamped here rather than in the
     * template below, so the same normalized array can drive both the query
     * and the "keep my filters" links in the pager.
     *
     * @return array{scope: string, calendar_id: int, search: string, paged: int}
     */
    private static function eventsFilters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation state (which slice of the table to display), not a state change; same pattern as currentTab()'s $_GET['tab'] read.
        $scope = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : 'upcoming';
        $calendarId = isset($_GET['calendar_id']) ? absint($_GET['calendar_id']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'series';

        return [
            'scope' => in_array($scope, ['upcoming', 'past', 'all'], true) ? $scope : 'upcoming',
            'calendar_id' => $calendarId,
            'search' => $search,
            'paged' => max(1, $paged),
            // Series is the default: a recurring "Gottesdienst" occupying 50
            // near-identical rows is noise when you are looking for one specific
            // appointment. Occurrence view stays one click away.
            'view' => $view === 'occurrences' ? 'occurrences' : 'series',
        ];
    }

    /**
     * Read-only overview of the actually synced wp_ctp_events rows, so an admin
     * can verify the sync really pulled the right appointments without needing
     * DB access.
     *
     * Was a flat "next 200 upcoming rows" dump, which stopped being usable once
     * a handful of weekly series filled the sync horizon with several hundred
     * occurrences: no way to find one specific appointment, no way to look at a
     * single calendar, no way to see anything past the 200th row, and a footer
     * count that reported *all* stored events while the table only ever showed
     * upcoming ones. Now: headline counts, a filter bar (Zeitraum/Kalender/
     * Suche), month-grouped rows and real paging — all server-side, so it
     * works on a table of any size.
     */
    private function renderEventsOverview(): void
    {
        $repository = new EventRepository();
        $filters = self::eventsFilters();
        $stats = $repository->stats();
        $calendars = self::get()['calendars'];

        $isSeriesView = $filters['view'] === 'series';
        $totalMatching = $isSeriesView
            ? $repository->countSeriesForAdmin($filters)
            : $repository->countForAdmin($filters);
        $lastPage = max(1, (int) ceil($totalMatching / self::EVENTS_PER_PAGE));
        // A filter change can leave "paged" pointing past the end of the new
        // result set — clamp rather than render an empty table with a pager
        // that offers no way back.
        $paged = min($filters['paged'], $lastPage);
        $offset = ($paged - 1) * self::EVENTS_PER_PAGE;
        $events = $isSeriesView
            ? $repository->findSeriesForAdmin($filters, self::EVENTS_PER_PAGE, $offset)
            : $repository->findForAdmin($filters, self::EVENTS_PER_PAGE, $offset);

        // Dieselbe Statuszeile wie auf jedem anderen Tab, aus derselben
        // Funktion und an derselben Stelle: ueber dem Panel, nicht darin.
        self::renderStatStrip([
            [
                'icon' => 'database',
                'value' => (string) $stats['total'],
                'label' => __('Gesamt', 'churchtools-plugin'),
            ],
            [
                'icon' => 'calendar-alt',
                'value' => (string) $stats['upcoming'],
                'label' => __('Kommend', 'churchtools-plugin'),
            ],
            [
                'icon' => 'backup',
                'value' => (string) $stats['past'],
                'label' => __('Vergangen (in Aufbewahrung)', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => (string) $stats['with_image'],
                'label' => __('Mit importiertem Bild', 'churchtools-plugin'),
            ],
        ]);
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Gespeicherte Termine', 'churchtools-plugin'); ?></h2>
            <?php $this->renderEventsFilterBar($filters, $calendars); ?>

            <?php if ($events === []) : ?>
                <p class="ctp-empty-state">
                    <?php if ($stats['total'] === 0) : ?>
                        <?php esc_html_e('Noch keine Termine synchronisiert.', 'churchtools-plugin'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Keine Termine passen zu diesem Filter.', 'churchtools-plugin'); ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <table class="widefat striped ctp-borderless ctp-events-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Titel', 'churchtools-plugin'); ?></th>
                            <th>
                                <?php
                                echo $isSeriesView
                                    ? esc_html__('Termine der Serie', 'churchtools-plugin')
                                    : esc_html__('Zeitraum', 'churchtools-plugin');
                                ?>
                            </th>
                            <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($isSeriesView) : ?>
                            <?php foreach ($events as $series) : ?>
                                <?php $this->renderSeriesOverviewRow($series, $calendars); ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php $currentMonth = null; ?>
                            <?php foreach ($events as $event) : ?>
                                <?php $month = mysql2date('Y-m', $event['start_date']); ?>
                                <?php if ($month !== $currentMonth) : ?>
                                    <?php $currentMonth = $month; ?>
                                    <tr class="ctp-events-table__month">
                                        <th colspan="3" scope="colgroup">
                                            <?php echo esc_html(date_i18n('F Y', (int) mysql2date('U', $event['start_date']))); ?>
                                        </th>
                                    </tr>
                                <?php endif; ?>
                                <?php $this->renderEventOverviewRow($event, $calendars); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php $this->renderEventsPager($filters, $paged, $lastPage, $totalMatching); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Zeitraum / Kalender / Suche, as a plain GET form — no AJAX, so the
     * resulting URL is bookmarkable and the browser's back button works.
     * The calendar select lists every calendar known from the last fetch, not
     * just the enabled ones: rows for a calendar that was switched off are
     * still in the table until retention removes them, and being unable to
     * look at exactly those would defeat the point of this screen.
     */
    private function renderEventsFilterBar(array $filters, array $calendars): void
    {
        $scopes = [
            'upcoming' => __('Kommende', 'churchtools-plugin'),
            'past' => __('Vergangene', 'churchtools-plugin'),
            'all' => __('Alle', 'churchtools-plugin'),
        ];
        uasort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ctp-events-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <input type="hidden" name="tab" value="events" />

            <label class="screen-reader-text" for="ctp-events-scope"><?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-scope" name="scope">
                <?php foreach ($scopes as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['scope'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="ctp-events-calendar"><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-calendar" name="calendar_id">
                <option value="0"><?php esc_html_e('Alle Kalender', 'churchtools-plugin'); ?></option>
                <?php foreach ($calendars as $id => $calendar) : ?>
                    <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($filters['calendar_id'], (int) $id); ?>>
                        <?php echo esc_html($calendar['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="ctp-events-search"><?php esc_html_e('Termine durchsuchen', 'churchtools-plugin'); ?></label>
            <input
                type="search"
                id="ctp-events-search"
                name="s"
                value="<?php echo esc_attr($filters['search']); ?>"
                placeholder="<?php esc_attr_e('Titel, Untertitel oder Ort …', 'churchtools-plugin'); ?>"
            />

            <label class="screen-reader-text" for="ctp-events-view"><?php esc_html_e('Ansicht', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-view" name="view">
                <option value="series" <?php selected($filters['view'], 'series'); ?>>
                    <?php esc_html_e('Serien zusammengefasst', 'churchtools-plugin'); ?>
                </option>
                <option value="occurrences" <?php selected($filters['view'], 'occurrences'); ?>>
                    <?php esc_html_e('Einzeltermine', 'churchtools-plugin'); ?>
                </option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filtern', 'churchtools-plugin'); ?></button>
            <?php if ($filters['scope'] !== 'upcoming' || $filters['calendar_id'] > 0 || $filters['search'] !== '' || $filters['view'] !== 'series') : ?>
                <a class="button-link" href="<?php echo esc_url(self::eventsOverviewUrl()); ?>">
                    <?php esc_html_e('Zurücksetzen', 'churchtools-plugin'); ?>
                </a>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Prev/next plus "Seite X von Y", carrying the current filters through so
     * paging never silently resets them.
     */
    private function renderEventsPager(array $filters, int $paged, int $lastPage, int $total): void
    {
        $pageUrl = static fn (int $page): string => self::eventsOverviewUrl([
            'scope' => $filters['scope'],
            'calendar_id' => $filters['calendar_id'] ?: null,
            's' => $filters['search'] !== '' ? $filters['search'] : null,
            'view' => $filters['view'] !== 'series' ? $filters['view'] : null,
            'paged' => $page > 1 ? $page : null,
        ]);
        ?>
        <div class="ctp-events-pager">
            <span class="ctp-muted-text">
                <?php
                printf(
                    /* translators: 1: current page number, 2: total number of pages, 3: number of matching rows, 4: what those rows are ("Serien" or "Termine") */
                    esc_html__('Seite %1$d von %2$d – %3$d %4$s', 'churchtools-plugin'),
                    (int) $paged,
                    (int) $lastPage,
                    (int) $total,
                    $filters['view'] === 'series'
                        ? esc_html__('Serien', 'churchtools-plugin')
                        : esc_html__('Termine', 'churchtools-plugin')
                );
                ?>
            </span>
            <?php if ($lastPage > 1) : ?>
                <span class="ctp-events-pager__links">
                    <?php if ($paged > 1) : ?>
                        <a class="button" href="<?php echo esc_url($pageUrl($paged - 1)); ?>">&larr; <?php esc_html_e('Zurück', 'churchtools-plugin'); ?></a>
                    <?php endif; ?>
                    <?php if ($paged < $lastPage) : ?>
                        <a class="button" href="<?php echo esc_url($pageUrl($paged + 1)); ?>"><?php esc_html_e('Weiter', 'churchtools-plugin'); ?> &rarr;</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderEventOverviewRow(array $event, array $calendars): void
    {
        $calendarId = (int) $event['ct_calendar_id'];
        $calendar = $calendars[$calendarId] ?? null;
        $calendarName = $calendar['name'] ?? sprintf('#%d', $calendarId);
        $isPast = $event['end_date'] < current_time('mysql');
        ?>
        <tr<?php echo $isPast ? ' class="ctp-events-table__row--past"' : ''; ?>>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $event['id'])); ?>">
                    <?php echo esc_html($event['title']); ?>
                </a>
                <?php if (!empty($event['attachment_id'])) : ?>
                    <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                <?php endif; ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <br /><span class="ctp-muted-text"><?php echo esc_html($event['subtitle']); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($event['all_day'])) : ?>
                    <?php echo esc_html(mysql2date(get_option('date_format'), $event['start_date'])); ?>
                    (<?php esc_html_e('ganztägig', 'churchtools-plugin'); ?>)
                <?php else : ?>
                    <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $event['start_date'])); ?>
                    &ndash;
                    <?php echo esc_html(mysql2date(get_option('time_format'), $event['end_date'])); ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($calendar['color'])) : ?>
                    <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo esc_html($calendarName); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * One collapsed series: how many occurrences, the span they cover, and a
     * link into the occurrence view filtered to exactly this series — so
     * "collapsed" never means "unreachable".
     */
    private function renderSeriesOverviewRow(array $series, array $calendars): void
    {
        $calendarId = (int) $series['ct_calendar_id'];
        $calendar = $calendars[$calendarId] ?? null;
        $calendarName = $calendar['name'] ?? sprintf('#%d', $calendarId);
        $count = (int) $series['occurrences'];
        $dateFormat = get_option('date_format');
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $series['sample_id'])); ?>">
                    <?php echo esc_html($series['title']); ?>
                </a>
                <?php if (!empty($series['attachment_id'])) : ?>
                    <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                <?php endif; ?>
                <?php if ($series['subtitle'] !== '' && $series['subtitle'] !== null) : ?>
                    <br /><span class="ctp-muted-text"><?php echo esc_html($series['subtitle']); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($count === 1) : ?>
                    <?php echo esc_html(mysql2date($dateFormat, $series['first_start'])); ?>
                <?php else : ?>
                    <?php
                    printf(
                        /* translators: 1: number of occurrences, 2: first date, 3: last date */
                        esc_html__('%1$d Termine, %2$s bis %3$s', 'churchtools-plugin'),
                        (int) $count,
                        esc_html(mysql2date($dateFormat, $series['first_start'])),
                        esc_html(mysql2date($dateFormat, $series['last_start']))
                    );
                    ?>
                    <br />
                    <a class="ctp-muted-text" href="<?php echo esc_url(self::eventsOverviewUrl(['view' => 'occurrences', 's' => $series['title']])); ?>">
                        <?php esc_html_e('Einzeltermine anzeigen', 'churchtools-plugin'); ?>
                    </a>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($calendar['color'])) : ?>
                    <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo esc_html($calendarName); ?>
            </td>
        </tr>
        <?php
    }

    private static function eventDetailUrl(int $id): string
    {
        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => 'events', 'event_id' => $id],
            admin_url('admin.php')
        );
    }

    /**
     * @param array<string, string|int|null> $extra Filter state to carry along;
     *        null entries are dropped so a default-valued filter doesn't end up
     *        in the URL.
     */
    private static function eventsOverviewUrl(array $extra = []): string
    {
        $args = ['page' => self::PAGE_SLUG, 'tab' => 'events'];

        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '') {
                $args[$key] = $value;
            }
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Detail view for a single synced occurrence, reached by clicking a title in
     * renderEventsOverview(). Shows every field the sync stores, including ones the
     * public frontend template deliberately leaves out (description, raw image_url)
     * — this is an admin-only, manage_options-gated view, not public output.
     */
    private function renderEventDetail(int $id): void
    {
        $event = (new EventRepository())->find($id);
        $backUrl = self::eventsOverviewUrl();

        if ($event === null) {
            printf('<div class="ctp-panel"><p>%s</p>', esc_html__('Termin nicht gefunden.', 'churchtools-plugin'));
            printf(
                '<p class="ctp-back-link"><a href="%s"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>%s</a></p></div>',
                esc_url($backUrl),
                esc_html__('Zurück zur Übersicht', 'churchtools-plugin')
            );

            return;
        }

        $calendarId = (int) $event['ct_calendar_id'];
        $calendar = self::get()['calendars'][$calendarId] ?? null;

        // Prefer the imported WP attachment over the raw ChurchTools image_url — see
        // EventListRenderer::withCalendarMeta() for why (avoids hotlinking the
        // ChurchTools domain from the admin's browser too).
        $attachmentId = (int) ($event['attachment_id'] ?? 0);
        $displayImageUrl = $attachmentId > 0 ? wp_get_attachment_image_url($attachmentId, 'large') : false;
        if ($displayImageUrl === false) {
            $displayImageUrl = $event['image_url'];
        }
        ?>
        <p class="ctp-back-link">
            <a href="<?php echo esc_url($backUrl); ?>">
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Zurück zur Übersicht', 'churchtools-plugin'); ?>
            </a>
        </p>
        <div class="ctp-panel">
            <h2 class="ctp-event-detail-title">
                <?php echo esc_html($event['title']); ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <span class="ctp-event-detail-subtitle">
                        <?php echo esc_html($event['subtitle']); ?>
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($displayImageUrl !== '') : ?>
                <p>
                    <img
                        src="<?php echo esc_url($displayImageUrl); ?>"
                        alt=""
                        class="ctp-event-detail-image"
                    />
                </p>
            <?php endif; ?>

            <table class="widefat striped ctp-borderless ctp-keyvalue-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if (!empty($event['all_day'])) : ?>
                                <?php echo esc_html(mysql2date(get_option('date_format'), $event['start_date'])); ?>
                                (<?php esc_html_e('ganztägig', 'churchtools-plugin'); ?>)
                            <?php else : ?>
                                <?php
                                $dateTimeFormat = get_option('date_format') . ' ' . get_option('time_format');
                                echo esc_html(mysql2date($dateTimeFormat, $event['start_date']));
                                ?>
                                &ndash;
                                <?php echo esc_html(mysql2date($dateTimeFormat, $event['end_date'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if (!empty($calendar['color'])) : ?>
                                <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php echo esc_html($calendar['name'] ?? sprintf('#%d', $calendarId)); ?>
                        </td>
                    </tr>
                    <?php if ($event['location'] !== '') : ?>
                        <tr>
                            <th><?php esc_html_e('Ort', 'churchtools-plugin'); ?></th>
                            <td><?php echo esc_html($event['location']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($event['description'] !== '') : ?>
                        <tr>
                            <th><?php esc_html_e('Beschreibung', 'churchtools-plugin'); ?></th>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses_post() before adding any markup of its own (see its docblock). ?>
                            <td><?php echo EventFormatter::descriptionHtml($event['description']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('ChurchTools-ID', 'churchtools-plugin'); ?></th>
                        <td><?php echo (int) $event['ct_event_id']; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = self::currentTab();
        $icons = self::tabIcons();
        ?>
        <div class="wrap ctp-admin">
            <div class="ctp-admin-header">
                <span class="ctp-admin-logo" aria-hidden="true">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </span>
                <h1><?php esc_html_e('ChurchTools Events', 'churchtools-plugin'); ?></h1>
            </div>
            <p class="ctp-admin-tagline">
                <?php esc_html_e('Kalender-Events aus ChurchTools synchronisieren, gestalten und anzeigen.', 'churchtools-plugin'); ?>
            </p>
            <?php
            /*
             * Die Reiter liegen in einem eigenen Raster innerhalb des
             * nav-tab-wrapper, statt direkt darin: das Raster gibt allen
             * Reitern die Breite des breitesten („Synchronisation“), waehrend
             * der Wrapper seine durchgehende Linie ueber die volle Seite
             * behaelt. Vorher richtete sich jeder Reiter nach seiner
             * Beschriftung, und die Reihe sah aus wie ein Flickenteppich -
             * „Design“ halb so breit wie sein Nachbar. Siehe .ctp-tabs in
             * admin.css.
             */
            ?>
            <nav class="nav-tab-wrapper">
                <div class="ctp-tabs">
                    <?php foreach (self::tabs() as $tabSlug => $label) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tabSlug], admin_url('admin.php'))); ?>"
                            class="nav-tab <?php echo $tab === $tabSlug ? 'nav-tab-active' : ''; ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($icons[$tabSlug] ?? 'admin-generic'); ?>" aria-hidden="true"></span>
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>

            <?php
            /*
             * Die Statuszeile steht auf jedem Tab, an dem Daten aus ChurchTools
             * hereinkommen, an genau derselben Stelle: zwischen Tab-Navigation
             * und Inhalt. renderTabStatus() entscheidet, welche Kacheln der
             * jeweilige Tab braucht.
             *
             * Der Tab „Events“ ist ausgenommen, weil er seine Zeile aus den
             * Zahlen baut, die er fuer die Tabelle ohnehin schon geladen hat
             * (siehe renderEventsOverview()) - und der Detailblick auf einen
             * einzelnen Termin bekommt gar keine: dort geht es um eine Zeile,
             * nicht um den Bestand.
             */
            if ($tab !== 'events') {
                self::renderTabStatus($tab);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which event to display), not a state change; same pattern as currentTab()'s $_GET['tab'] read above.
            $eventId = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
            ?>

            <?php if ($tab === 'status') : ?>
                <?php $this->renderStatusOverview(); ?>
            <?php elseif ($tab === 'embed') : ?>
                <?php $this->renderEmbedTab(); ?>
            <?php elseif ($tab === 'events') : ?>
                <?php
                if ($eventId > 0) {
                    $this->renderEventDetail($eventId);
                } else {
                    $this->renderEventsOverview();
                }
                ?>
            <?php elseif ($tab === 'calendars') : ?>
                <?php $this->renderCalendarsTab(); ?>
            <?php elseif ($tab === 'rooms') : ?>
                <?php $this->renderRoomsTab(); ?>
            <?php elseif ($tab === 'updates') : ?>
                <?php $this->renderUpdatesTab(); ?>
            <?php elseif ($tab === 'design') : ?>
                <?php
                /*
                 * One form around everything, with the layout grid *inside* it,
                 * so each drag&drop editor and the preview it drives sit in the
                 * same grid row and are visible together while dragging. The
                 * previews contain no form fields, so wrapping them costs
                 * nothing. See .ctp-design-layout in admin.css.
                 */
                ?>
                <form method="post" action="options.php" class="ctp-settings-form">
                    <?php settings_fields(self::PAGE_SLUG); ?>
                    <?php // Volle Breite ueber den Paaren: die Stil-Grundlage, auf der beide Vorschauen aufsetzen. ?>
                    <div class="ctp-panel ctp-design-style">
                        <?php do_settings_sections(self::PAGE_SLUG . '_design_style'); ?>
                    </div>
                    <div class="ctp-design-layout">
                        <div class="ctp-panel">
                            <?php do_settings_sections(self::PAGE_SLUG . '_design_tile'); ?>
                        </div>
                        <?php $this->renderDesignPreview(); ?>

                        <div class="ctp-panel">
                            <?php do_settings_sections(self::PAGE_SLUG . '_design_detail'); ?>
                        </div>
                        <?php $this->renderDetailPreview(); ?>
                    </div>
                    <div class="ctp-panel">
                        <?php do_settings_sections(self::PAGE_SLUG . '_design_list'); ?>
                    </div>
                    <?php $this->renderSaveBar(); ?>
                </form>
            <?php else : ?>
                <form method="post" action="options.php" class="ctp-settings-form">
                    <div class="ctp-panel">
                        <?php
                        settings_fields(self::PAGE_SLUG);
                        do_settings_sections(self::PAGE_SLUG . '_' . $tab);
                        ?>
                    </div>
                    <?php $this->renderSaveBar(); ?>
                </form>
            <?php endif; ?>
        </div>
        <script>
        /*
         * Sobald ein Feld angefasst wurde, sagt die Leiste am Fuss des
         * Formulars, dass etwas offen ist. Ohne diesen Hinweis ist der Zustand
         * nicht zu erkennen: Im Design-Tab schalten die beiden Vorschauen beim
         * Wechseln der Vorlage sofort um, die Seite sieht also fertig aus,
         * obwohl noch nichts gespeichert ist.
         *
         * Hier im geteilten Skriptblock und nicht in admin-design.js, weil die
         * Leiste auf jedem Tab mit einem Einstellungsformular steht und jenes
         * Skript nur im Design-Tab geladen wird.
         *
         * Bewusst kein beforeunload-Dialog: Der ist auf einer
         * Einstellungsseite mehr Bevormundung als Hilfe, und die Leiste steht
         * ohnehin immer im Bild.
         */
        Array.prototype.forEach.call(document.querySelectorAll('.ctp-settings-form'), function (form) {
            var markDirty = function () {
                form.classList.add('ctp-settings-form--dirty');
            };
            form.addEventListener('change', markDirty);
            form.addEventListener('input', markDirty);
            // Die Reihenfolge-Editoren aendern ein verstecktes Feld per Skript,
            // und dabei feuert `change` nicht von selbst - der Zug mit der Maus
            // ist die Aenderung, die man am ehesten vergisst zu speichern.
            form.addEventListener('drop', markDirty);
        });

        // The instance/API-key inputs live on the "Verbindung" tab, but
        // "Kalender laden" sits on the "Kalender" tab — reading .value off a
        // getElementById() that returned null threw a TypeError there and left
        // the button stuck on "Lade…" forever. Both fields are optional to
        // begin with: effectiveConnection() falls back to the stored values
        // whenever a field wasn't submitted.
        function ctpFieldValue(id) {
            var field = document.getElementById(id);

            return field ? field.value : '';
        }

        /*
         * Die eine Rueckmeldung, die jede Aktion im Backend benutzt. Vorher
         * setzte jeder der drei Knoepfe nur .textContent auf einem nackten
         * <span>: „Verbindung erfolgreich“ und „Verbindung fehlgeschlagen“
         * sahen identisch aus, und ob gerade noch etwas laeuft, war nur am
         * Wortlaut zu erkennen. state ist '', 'busy', 'success' oder 'error'
         * und steuert Farbe und Symbol (siehe .ctp-inline-status in admin.css).
         */
        function ctpSetStatus(element, state, message) {
            if (!element) {
                return;
            }

            element.className = 'ctp-inline-status' + (state ? ' is-' + state : '');
            element.textContent = message;
        }

        document.getElementById('ctp-test-connection')?.addEventListener('click', function () {
            var result = document.getElementById('ctp-test-connection-result');
            ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Prüfe…', 'churchtools-plugin')); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_test_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_test_connection')); ?>',
                    instance: ctpFieldValue('ctp-instance'),
                    api_key: ctpFieldValue('ctp-api-key'),
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var fallback = data.success
                        ? '<?php echo esc_js(__('Verbindung erfolgreich', 'churchtools-plugin')); ?>'
                        : '<?php echo esc_js(__('Verbindung fehlgeschlagen', 'churchtools-plugin')); ?>';

                    ctpSetStatus(
                        result,
                        data.success ? 'success' : 'error',
                        (data.data && data.data.message) ? data.data.message : fallback
                    );
                });
        });

        document.getElementById('ctp-fetch-calendars')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-fetch-calendars-result');
            button.disabled = true;
            ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Lade…', 'churchtools-plugin')); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_fetch_calendars',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_fetch_calendars')); ?>',
                    instance: ctpFieldValue('ctp-instance'),
                    api_key: ctpFieldValue('ctp-api-key'),
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    button.disabled = false;
                    ctpSetStatus(result, 'error', (data.data && data.data.message)
                        ? data.data.message
                        : '<?php echo esc_js(__('Laden fehlgeschlagen', 'churchtools-plugin')); ?>');
                });
        });

        document.getElementById('ctp-fetch-resources')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-fetch-resources-result');
            button.disabled = true;
            ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Lade…', 'churchtools-plugin')); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_fetch_resources',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_fetch_resources')); ?>',
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    button.disabled = false;
                    ctpSetStatus(result, 'error', (data.data && data.data.message)
                        ? data.data.message
                        : '<?php echo esc_js(__('Laden fehlgeschlagen', 'churchtools-plugin')); ?>');
                });
        });

        /*
         * Der Tab „Raeume": uebernimmt nach dem Speichern von selbst. Eigener
         * Handler statt des Knopfs unten, weil der bei Erfolg die Seite neu
         * laedt - nach einem Speichern truege die Adresse dann weiterhin
         * `settings-updated`, und der Lauf startete endlos neu. Hier wird
         * stattdessen die Zeile darunter aktualisiert.
         */
        (function () {
            var block = document.querySelector('.ctp-rooms-apply');
            var button = document.getElementById('ctp-sync-rooms');

            if (!block || !button) {
                return;
            }

            var result = document.getElementById('ctp-sync-rooms-result');
            var summary = document.getElementById('ctp-rooms-summary');

            function uebernehmen() {
                button.disabled = true;
                ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Übernehme…', 'churchtools-plugin')); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ctp_run_sync',
                        nonce: '<?php echo esc_js(wp_create_nonce('ctp_run_sync')); ?>',
                    }),
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        button.disabled = false;

                        if (data.success) {
                            ctpSetStatus(result, 'ok', '<?php echo esc_js(__('Übernommen', 'churchtools-plugin')); ?>');

                            if (summary && data.data && data.data.location_summary) {
                                summary.textContent = data.data.location_summary;
                            }

                            return;
                        }

                        ctpSetStatus(result, 'error', (data.data && data.data.message)
                            ? data.data.message
                            : '<?php echo esc_js(__('Übernehmen fehlgeschlagen', 'churchtools-plugin')); ?>');
                    })
                    .catch(function () {
                        button.disabled = false;
                        ctpSetStatus(result, 'error', '<?php echo esc_js(__('Übernehmen fehlgeschlagen', 'churchtools-plugin')); ?>');
                    });
            }

            button.addEventListener('click', uebernehmen);

            if (block.dataset.auto === '1') {
                uebernehmen();
            }
        })();

        document.getElementById('ctp-run-sync')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-run-sync-result');
            button.disabled = true;
            ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Synchronisiere…', 'churchtools-plugin')); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_run_sync',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_run_sync')); ?>',
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    button.disabled = false;
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    ctpSetStatus(result, 'error', (data.data && data.data.message)
                        ? data.data.message
                        : '<?php echo esc_js(__('Synchronisation fehlgeschlagen', 'churchtools-plugin')); ?>');
                });
        });

        /*
         * Color fields (calendar rows + the Design tab's accent color): a
         * native <input type="color"> swatch paired with a plain text field
         * for the hex code, kept in sync in both directions. Only the swatch
         * is submitted; the text field is pure input convenience, so an
         * unparseable value can simply snap back instead of needing its own
         * server-side validation.
         *
         * Delegated on document rather than bound per field so it also covers
         * the Design tab's single accent field and anything added later,
         * without either tab needing its own copy.
         */
        function ctpApplyColor(field, value) {
            var swatch = field.querySelector('.ctp-color-input');
            var hex = field.querySelector('.ctp-color-hex');

            if (swatch) {
                swatch.value = value;
                // The Design tab's live preview listens for "input" on the
                // swatch (see assets/js/admin-design.js) — assigning .value
                // from script doesn't fire one on its own.
                swatch.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (hex) {
                hex.value = value;
            }
        }

        document.addEventListener('input', function (event) {
            var field = event.target.closest ? event.target.closest('.ctp-color-field') : null;
            if (!field) {
                return;
            }

            if (event.target.classList.contains('ctp-color-input')) {
                var hex = field.querySelector('.ctp-color-hex');
                // Not while the hex field is being typed in: the swatch echoes
                // back a normalized (lowercased) value, and rewriting the input
                // mid-keystroke would jump the caret to the end.
                if (hex && document.activeElement !== hex) {
                    hex.value = event.target.value;
                }

                return;
            }

            if (event.target.classList.contains('ctp-color-hex')) {
                var typed = event.target.value.trim();
                if (typed.charAt(0) !== '#') {
                    typed = '#' + typed;
                }
                // Only mirror a complete, well-formed value — otherwise every
                // keystroke of a half-typed code would repaint the swatch.
                if (/^#[0-9a-f]{6}$/i.test(typed)) {
                    var swatch = field.querySelector('.ctp-color-input');
                    if (swatch) {
                        swatch.value = typed.toLowerCase();
                        swatch.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        });

        // Leaving the field with something unparseable in it: restore the hex
        // text from the swatch, which still holds the last valid value.
        document.addEventListener('focusout', function (event) {
            if (!event.target.classList || !event.target.classList.contains('ctp-color-hex')) {
                return;
            }

            var field = event.target.closest('.ctp-color-field');
            var swatch = field ? field.querySelector('.ctp-color-input') : null;
            if (swatch) {
                event.target.value = swatch.value;
            }
        });

        document.addEventListener('click', function (event) {
            var button = event.target.closest('.ctp-color-reset');
            if (!button) {
                return;
            }

            event.preventDefault();
            var field = button.closest('.ctp-color-field');
            if (field) {
                ctpApplyColor(field, button.dataset.defaultColor);
            }
        });

        document.querySelectorAll('.ctp-image-select').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var cell = button.closest('.ctp-image-field');
                var input = cell.querySelector('.ctp-image-id');
                var preview = cell.querySelector('.ctp-image-preview');
                var removeButton = cell.querySelector('.ctp-image-remove');

                var frame = wp.media({
                    title: '<?php echo esc_js(__('Standardbild wählen', 'churchtools-plugin')); ?>',
                    multiple: false,
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    input.value = attachment.id;
                    preview.src = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                    preview.hidden = false;
                    removeButton.hidden = false;
                    button.textContent = '<?php echo esc_js(__('Ersetzen', 'churchtools-plugin')); ?>';
                });

                frame.open();
            });
        });

        document.querySelectorAll('.ctp-image-remove').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var cell = button.closest('.ctp-image-field');
                cell.querySelector('.ctp-image-id').value = '0';
                var preview = cell.querySelector('.ctp-image-preview');
                preview.hidden = true;
                preview.src = '';
                button.hidden = true;

                var selectButton = cell.querySelector('.ctp-image-select');
                if (selectButton) {
                    selectButton.textContent = '<?php echo esc_js(__('Bild wählen', 'churchtools-plugin')); ?>';
                }
            });
        });

        document.getElementById('ctp-check-updates')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-check-updates-result');
            button.disabled = true;
            ctpSetStatus(result, 'busy', '<?php echo esc_js(__('Prüfe…', 'churchtools-plugin')); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_check_updates',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_check_updates')); ?>',
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    button.disabled = false;
                    ctpSetStatus(
                        result,
                        data.success ? 'success' : 'error',
                        (data.data && data.data.message)
                            ? data.data.message
                            : '<?php echo esc_js(__('Prüfung fehlgeschlagen', 'churchtools-plugin')); ?>'
                    );
                    // Neu laden, damit Statuszeile und Hinweis oben denselben
                    // Stand zeigen wie die eben eingeholte Auskunft - aber erst,
                    // nachdem die Meldung kurz lesbar war.
                    if (data.success) {
                        setTimeout(function () { window.location.reload(); }, 1200);
                    }
                });
        });

        /*
         * Kalenderkacheln (Tab „Kalender“): der gedimmte Zustand einer
         * inaktiven Kachel wird beim Klick sofort mitgezogen, statt erst nach
         * dem Speichern zu erscheinen. Delegiert am Dokument, damit dieselbe
         * Zeile auch fuer die Sammelschalter unten gilt.
         */
        function ctpSyncCalendarCard(checkbox) {
            var card = checkbox.closest('.ctp-calendar-card');
            if (card) {
                card.classList.toggle('is-disabled', !checkbox.checked);
            }
        }

        document.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('ctp-calendar-enabled')) {
                ctpSyncCalendarCard(event.target);
            }
        });

        document.querySelectorAll('.ctp-calendar-bulk').forEach(function (button) {
            button.addEventListener('click', function () {
                var enable = button.dataset.enable === '1';
                // Nur was gerade sichtbar ist: steht ein Suchbegriff im Filter,
                // bedeutet „Alle aktivieren“ die gefilterte Auswahl - sonst
                // veraendert ein Klick stillschweigend auch Kalender, die man
                // in diesem Moment gar nicht vor sich hat.
                document.querySelectorAll('.ctp-calendar-card:not([hidden]) .ctp-calendar-enabled').forEach(function (checkbox) {
                    checkbox.checked = enable;
                    ctpSyncCalendarCard(checkbox);
                });
            });
        });

        document.getElementById('ctp-calendar-search')?.addEventListener('input', function () {
            var needle = this.value.trim().toLowerCase();
            var emptyHint = document.getElementById('ctp-calendar-no-match');
            var visible = 0;

            document.querySelectorAll('.ctp-calendar-card').forEach(function (card) {
                // Name *oder* ID: die ID steht im Shortcode und in
                // Fehlermeldungen, danach sucht man genauso.
                var match = needle === ''
                    || card.dataset.name.indexOf(needle) !== -1
                    || card.dataset.id.indexOf(needle) !== -1;
                card.hidden = !match;
                if (match) {
                    visible++;
                }
            });

            if (emptyHint) {
                emptyHint.hidden = visible !== 0;
            }
        });

        document.querySelectorAll('.ctp-copy-shortcode').forEach(function (button) {
            // navigator.clipboard needs a secure context (HTTPS, or localhost for
            // local testing) — simply not offering the button's function is a safer
            // degrade than the deprecated document.execCommand('copy') fallback.
            if (!navigator.clipboard) {
                return;
            }

            button.addEventListener('click', function () {
                navigator.clipboard.writeText(button.dataset.shortcode).then(function () {
                    var original = button.textContent;
                    button.textContent = '<?php echo esc_js(__('Kopiert!', 'churchtools-plugin')); ?>';
                    setTimeout(function () {
                        button.textContent = original;
                    }, 1500);
                });
            });
        });
        </script>
        <?php
    }

    public function ajaxTestConnection(): void
    {
        check_ajax_referer('ctp_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['api_key'] === '' && self::apiKeyDecryptionFailed()) {
            wp_send_json_error(['message' => self::apiKeyDecryptionErrorMessage()]);
        }

        if ($connection['instance'] === '' || $connection['api_key'] === '') {
            wp_send_json_error(['message' => __('Bitte Instanz und API-Key eingeben.', 'churchtools-plugin')]);
        }

        try {
            $client = new Client($connection['base_url'], $connection['api_key']);
            $person = $client->whoami();
            $name = trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? ''));

            wp_send_json_success([
                /* translators: %s: full name of the authenticated ChurchTools person */
                'message' => $name !== ''
                    ? sprintf(__('Verbunden als %s', 'churchtools-plugin'), $name)
                    : __('Verbindung erfolgreich', 'churchtools-plugin'),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public function ajaxFetchResources(): void
    {
        check_ajax_referer('ctp_fetch_resources', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['api_key'] === '' && self::apiKeyDecryptionFailed()) {
            wp_send_json_error(['message' => self::apiKeyDecryptionErrorMessage()]);
        }

        if ($connection['instance'] === '' || $connection['api_key'] === '') {
            wp_send_json_error(['message' => __('Bitte Instanz und API-Key eingeben.', 'churchtools-plugin')]);
        }

        try {
            $result = self::refreshResources(new Client($connection['base_url'], $connection['api_key']));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);

            return;
        }

        wp_send_json_success(['count' => $result['count']]);
    }

    public function ajaxFetchCalendars(): void
    {
        check_ajax_referer('ctp_fetch_calendars', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['api_key'] === '' && self::apiKeyDecryptionFailed()) {
            wp_send_json_error(['message' => self::apiKeyDecryptionErrorMessage()]);
        }

        if ($connection['instance'] === '' || $connection['api_key'] === '') {
            wp_send_json_error(['message' => __('Bitte Instanz und API-Key eingeben.', 'churchtools-plugin')]);
        }

        try {
            $result = self::refreshCalendars(new Client($connection['base_url'], $connection['api_key']));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);

            return;
        }

        if ($result['status'] === 'empty') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success(['count' => $result['count']]);
    }

    /**
     * Holt die Kalenderliste von ChurchTools und schreibt sie zurueck - der
     * eine Weg fuer beide Aufrufer: den Knopf „Kalender von ChurchTools laden“
     * und den planmaessigen Sync (siehe SyncEngine::run()).
     *
     * Vorher lag diese Logik nur im AJAX-Handler, und die Kalenderliste
     * veraenderte sich damit ausschliesslich dann, wenn ein Mensch daran
     * dachte. Ein in ChurchTools umbenannter Kalender behielt im Plugin
     * monatelang seinen alten Namen, eine dort geaenderte Farbe kam nie an,
     * und ein neu angelegter Kalender tauchte in der Auswahl gar nicht erst
     * auf - waehrend der Sync daneben stuendlich lief.
     *
     * Wirft nur, was der Client wirft (Netz, HTTP-Fehler, unerwartete
     * Antwort). Die leere Antwort ist kein Fehler, sondern ein eigener
     * Zustand: mergeCalendars() baut die Liste ausschliesslich aus der Antwort
     * neu auf, eine leere Antwort loeschte sie also samt eingestellter Farben
     * und Standardbilder. Ein kaputter Body wirft inzwischen in
     * Client::request(), ein wohlgeformtes data: [] kaeme aber weiterhin bis
     * hierher. Sind die Kalender wirklich alle weg, bleiben sie abwaehlbar in
     * der Liste stehen - ihre Termine raeumt der Sync ueber seinen eigenen
     * Schutz ab.
     *
     * @return array{status: 'updated'|'empty', count: int, changed: bool, message: string}
     */
    /**
     * ChurchTools benennt die Ressourcentypen ueber Uebersetzungsschluessel;
     * `resource.type.room` ist der Raum. Am Schluessel erkannt und nicht am
     * angezeigten Namen, weil der uebersetzt und umbenannt werden kann.
     */
    private const ROOM_TYPE_KEY = 'resource.type.room';

    /** Erlaubte Werte fuer `rooms_mode` - alles andere faellt auf den Standard zurueck. */
    private const ROOM_MODES = [RoomLookup::MODE_EXCLUSIVE, RoomLookup::MODE_SINGLE, RoomLookup::MODE_ALL];

    /**
     * Zwilling von refreshCalendars(). Zwei Unterschiede zum Kalenderabgleich,
     * beide beabsichtigt:
     *
     * Eine leere Antwort ist hier *kein* Alarmfall. Ein API-Key ohne Freigabe
     * fuer Ressourcen bekommt legitim eine leere Liste, und der Normalzustand
     * dieses Plugins ist, dass niemand Raeume ausgewaehlt hat. Anders als bei
     * den Kalendern haengt an der Liste auch nichts, was verloren gehen koennte
     * (keine Farben, keine Standardbilder) - der Haken ueberlebt in $existing,
     * solange die ID wiederkommt.
     *
     * Und gefiltert wird auf Raeume: Gegenstaende sind nie eine Ortsangabe.
     * Findet sich kein Raumtyp - eine Instanz, die ihre Typen anders benannt
     * hat -, gelten alle Typen, damit die Liste nicht wortlos leer bleibt.
     */
    public static function refreshResources(Client $client): array
    {
        $settings = self::get();
        $masterdata = $client->getResourceMasterdata();

        $roomTypeIds = [];

        foreach ($masterdata['resourceTypes'] as $type) {
            if ((string) ($type['name'] ?? '') === self::ROOM_TYPE_KEY) {
                $roomTypeIds[] = (int) ($type['id'] ?? 0);
            }
        }

        if ($roomTypeIds === []) {
            $roomTypeIds = array_map(static fn (array $type): int => (int) ($type['id'] ?? 0), $masterdata['resourceTypes']);
        }

        $merged = self::mergeResources($settings['resources'] ?? [], $masterdata['resources'], $roomTypeIds);
        $changed = $merged !== ($settings['resources'] ?? []);

        if ($changed) {
            // Wie bei refreshCalendars(): sanitizeSettings() haengt an jedem
            // update_option() dieser Option und wuerde frisch geholte, noch
            // unbekannte IDs an der eigenen Allowlist wieder herausfiltern.
            remove_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);
            update_option(self::OPTION_KEY, array_merge($settings, ['resources' => $merged]));
            add_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);
        }

        update_option(self::OPTION_RESOURCES_FETCHED, current_time('mysql'));

        return [
            'status' => 'updated',
            'count' => count($merged),
            'changed' => $changed,
        ];
    }

    public static function refreshCalendars(Client $client): array
    {
        $settings = self::get();
        $merged = self::mergeCalendars($settings['calendars'], $client->getCalendars());

        if ($merged === [] && $settings['calendars'] !== []) {
            return [
                'status' => 'empty',
                'count' => 0,
                'changed' => false,
                'message' => __('ChurchTools hat keine Kalender zurückgeliefert. Die gespeicherte Kalenderliste bleibt deshalb unverändert, damit eingestellte Farben und Standardbilder nicht verloren gehen – bitte die Berechtigungen des API-Keys prüfen. Sind die Kalender dort wirklich alle entfernt worden, lassen sie sich in der Liste einzeln abwählen.', 'churchtools-plugin'),
            ];
        }

        // Nur bei echter Aenderung schreiben. Der Sync ruft das jetzt
        // stuendlich auf, und ein update_option() mit unveraendertem Inhalt
        // waere zwar folgenlos, aber onSettingsUpdated() haengt an diesem
        // Hook - und der Frontend-Cache unten soll nicht stuendlich ohne Grund
        // verworfen werden.
        $changed = $merged !== $settings['calendars'];

        if ($changed) {
            // register_setting() hooks sanitize_option_{option} onto every
            // update_option() call for this option, not just Settings API form
            // submissions — without removing it here, sanitizeSettings() would run
            // $merged (already-trusted, freshly-fetched data) back through
            // sanitizeCalendars()'s "only IDs already known" allowlist and silently
            // drop every calendar on the very first fetch (nothing was "known" yet).
            remove_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);
            update_option(self::OPTION_KEY, array_merge($settings, ['calendars' => $merged]));
            add_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);
        }

        // Auch ohne Aenderung: der Zeitstempel beantwortet „wann wurde zuletzt
        // nachgesehen“, nicht „wann hat sich zuletzt etwas geaendert“.
        update_option(self::OPTION_CALENDARS_FETCHED, current_time('mysql'));

        return [
            'status' => 'updated',
            'count' => count($merged),
            'changed' => $changed,
            'message' => '',
        ];
    }

    /**
     * Zwingt WordPress zu einer frischen Update-Pruefung.
     *
     * Ohne diesen Knopf haengt der Tab „Updates“ am Zwischenspeicher von
     * WordPress (`update_plugins`, ueblicherweise zwoelf Stunden alt) - wer
     * gerade ein Release veroeffentlicht hat und nachsehen will, ob es
     * ankommt, konnte nur warten. Gefragt wird dabei genau eine Quelle - die
     * Metadatendatei dieses Plugins - und nicht mehr ueber
     * wp_update_plugins() der Update-Dienst von WordPress nach saemtlichen
     * installierten Plugins (siehe GitHubUpdateChecker::checkNow()).
     */
    public function ajaxCheckUpdates(): void
    {
        check_ajax_referer('ctp_check_updates', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $update = GitHubUpdateChecker::checkNow();

        if ($update === null) {
            wp_send_json_error(['message' => __('Die Update-Prüfung steht auf dieser Installation nicht zur Verfügung.', 'churchtools-plugin')]);
        }

        if ($update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>')) {
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: newer version number available via GitHub */
                    __('Version %s steht bereit.', 'churchtools-plugin'),
                    $update['version']
                ),
            ]);
        }

        wp_send_json_success(['message' => __('Das Plugin ist auf dem aktuellen Stand.', 'churchtools-plugin')]);
    }

    public function ajaxRunSync(): void
    {
        check_ajax_referer('ctp_run_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $settings = self::get();
        $calendarIds = self::getEnabledCalendarIds();

        if ($settings['instance'] === '' || $settings['api_key'] === '') {
            wp_send_json_error(['message' => __('Bitte zuerst die Verbindung zu ChurchTools einrichten.', 'churchtools-plugin')]);
        }

        // Ohne aktiven Kalender ist dieser Lauf kein Abgleich mehr, sondern ein
        // Aufraeumen (siehe SyncEngine::run()) - und genau dafuer ist diese
        // Schaltflaeche die Bedienung. Vorher stand hier eine Fehlermeldung,
        // wodurch die Termine des zuletzt abgewaehlten Kalenders bis zum
        // naechsten planmaessigen Lauf im Tab "Events" stehen blieben, ohne
        // dass sich daran etwas machen liess.
        if ($calendarIds === []) {
            SyncEngine::run();

            // Auch das Aufraeumen meldet einen Fehler nicht mehr durch eine
            // Ausnahme, sondern ueber die Option (siehe unten).
            $cleanUpError = SyncEngine::getLastError();

            if ($cleanUpError !== null) {
                wp_send_json_error(['message' => $cleanUpError['message']]);
            }

            // „Kein Kalender aktiv“ kann auch heissen: Die Liste liess sich
            // gar nicht erst holen - ein abgelaufener API-Key etwa -, und
            // deshalb ist keiner aktiv. Das ist der Unterschied zwischen
            // „nichts zu tun“ und „kommt nicht an ChurchTools heran“, und er
            // stand bisher nur im Tab „Kalender“, waehrend dieser Knopf
            // Erfolg meldete.
            $calendarError = SyncEngine::getLastCalendarError();

            if ($calendarError !== null) {
                wp_send_json_error(['message' => $calendarError['message']]);
            }

            wp_send_json_success([
                'message' => __('Kein Kalender ist aktiv – die gespeicherten Termine wurden entfernt.', 'churchtools-plugin'),
                'count' => (new EventRepository())->count(),
                'location_summary' => self::locationSummary(),
                'last_sync' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) get_option('ctp_last_sync', '')),
            ]);
        }

        // SyncEngine::run() catches its own exceptions and persists them (see its
        // docblock) so an unattended WP-Cron run never fatals — that means a failure
        // here no longer surfaces as a thrown exception, it has to be read back via
        // getLastError() instead.
        SyncEngine::run();
        $lastError = SyncEngine::getLastError();

        if ($lastError !== null) {
            wp_send_json_error(['message' => $lastError['message']]);
        }

        wp_send_json_success([
            'message' => __('Synchronisation abgeschlossen.', 'churchtools-plugin'),
            'count' => (new EventRepository())->count(),
            'location_summary' => self::locationSummary(),
            'last_sync' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) get_option('ctp_last_sync', '')),
        ]);
    }

    /**
     * Ein Satz statt zweier Zahlen: Er beantwortet die Frage, mit der jemand den
     * Tab „Raeume" verlaesst - hat die Auswahl etwas bewirkt? Zahl-neutral
     * formuliert, weil bin/make-pot.php keine Plurale kann.
     */
    private static function locationSummary(): string
    {
        $repository = new EventRepository();
        $gesamt = $repository->count();

        if ($gesamt === 0) {
            return __('Es sind noch keine Termine gespeichert.', 'churchtools-plugin');
        }

        return sprintf(
            /* translators: 1: number of events with a location, 2: total number of stored events */
            __('Ortsangabe vorhanden: %1$d von %2$d gespeicherten Terminen.', 'churchtools-plugin'),
            $repository->countWithLocation(),
            $gesamt
        );
    }

    /**
     * Keeps enabled/color/default_image_id for calendars that still exist remotely,
     * seeds new ones as disabled with ChurchTools' own color, and drops ones that
     * were removed on the ChurchTools side — that a *single* calendar disappearing
     * takes its settings with it is deliberate: from here a revoked read permission
     * and a deleted calendar look identical, and "verschwindet aus der Liste" is the
     * right answer to both. Only the all-or-nothing case is guarded, by the caller
     * (see ajaxFetchCalendars()), because there the same ambiguity costs every
     * calendar at once. `default_color` is always overwritten
     * with ChurchTools' current value (never carried over from $existing) so the
     * "Auf Standardfarbe zurücksetzen" button (renderCalendarCard()) keeps pointing
     * at ChurchTools' actual color even if it changed there since the last fetch.
     */
    /**
     * Zwilling von mergeCalendars(). Der Haken bleibt beim Betreiber, Name und
     * Sortierschluessel kommen bei jedem Abgleich frisch aus ChurchTools - ein
     * umbenannter Raum heisst damit auch hier neu, ohne dass jemand etwas tun
     * muss.
     *
     * Gegenstaende bleiben draussen. `/api/resource/masterdata` fuehrt neben
     * Raeumen auch Technik und Aehnliches; als Ortsangabe kommt davon nichts in
     * Frage, und eine Liste, in der man sie erst wegsehen muss, waere schlechter
     * als eine kurze. Erkannt wird das am Typ, nicht am Namen.
     *
     * @param int[] $roomTypeIds IDs der Ressourcentypen, die Raeume sind
     */
    private static function mergeResources(array $existing, array $remoteResources, array $roomTypeIds): array
    {
        $merged = [];
        $rooms = array_flip(array_map('intval', $roomTypeIds));

        foreach ($remoteResources as $resource) {
            $id = (int) ($resource['id'] ?? 0);

            if ($id === 0 || !isset($rooms[(int) ($resource['resourceTypeId'] ?? 0)])) {
                continue;
            }

            $merged[$id] = [
                'name' => (string) ($resource['name'] ?? ''),
                'enabled' => (bool) ($existing[$id]['enabled'] ?? false),
                // ChurchTools' eigene Ordnung, nur zum Sortieren der Liste im
                // Backend - grosse Raeume oben, Testressourcen unten. Sie
                // entscheidet nichts, siehe RoomLookup.
                'sort_key' => (int) ($resource['sortKey'] ?? 0),
            ];
        }

        return $merged;
    }

    /**
     * Alle bekannten Raeume, angehakt oder nicht. Gebraucht wird das fuer den
     * strengen Modus: Um zu wissen, ob *nebenher* noch ein Raum belegt ist, muss
     * der Sync auch die Buchungen der nicht angehakten Raeume sehen.
     *
     * @return int[]
     */
    public static function knownResourceIds(): array
    {
        return array_map('intval', array_keys(self::get()['resources'] ?? []));
    }

    /**
     * Wie die Ortsangabe aus den Buchungen gebildet wird - siehe die
     * Beschreibung im Tab „Raeume" und die MODE_*-Konstanten.
     *
     * Der Rueckfall auf `rooms_exclusive` ist die Bruecke aus 1.12.0, wo an
     * dieser Stelle noch ein Kaestchen stand: Eine Installation, die damals
     * streng eingestellt war, bleibt es.
     */
    public static function roomsMode(): string
    {
        return self::resolveRoomsMode(self::get());
    }

    private static function resolveRoomsMode(array $settings): string
    {
        $mode = (string) ($settings['rooms_mode'] ?? '');

        if (in_array($mode, self::ROOM_MODES, true)) {
            return $mode;
        }

        return !empty($settings['rooms_exclusive']) ? RoomLookup::MODE_EXCLUSIVE : RoomLookup::MODE_SINGLE;
    }

    /**
     * Der Reiter schickt das Feld immer mit (das Formular traegt die ganze
     * Raumauswahl), ein fehlendes `resources` heisst deshalb „von einem anderen
     * Reiter gespeichert" und laesst die Einstellung unangetastet.
     */
    private static function sanitizeRoomsMode(array $input, array $existing): string
    {
        // Ueber resolveRoomsMode() und nicht direkt aus $existing: Sonst
        // schriebe das Speichern eines *anderen* Reiters die Bruecke aus 1.12.0
        // still auf den Standard um.
        $bestehend = self::resolveRoomsMode($existing);

        if (!array_key_exists('resources', $input)) {
            return $bestehend;
        }

        $gewaehlt = (string) ($input['rooms_mode'] ?? '');

        return in_array($gewaehlt, self::ROOM_MODES, true) ? $gewaehlt : $bestehend;
    }

    /**
     * Die im Backend angehakten Raeume. Ist nichts angehakt, fragt der Sync die
     * Buchungen gar nicht erst ab - das Ressourcenmodul kostet dann nichts.
     *
     * @return int[]
     */
    public static function enabledResourceIds(): array
    {
        $resources = array_filter(self::get()['resources'] ?? [], static fn (array $r): bool => !empty($r['enabled']));

        // In ChurchTools' eigener Ordnung, weil diese Reihenfolge im Modus
        // „alle Raeume nennen" die Anzeigereihenfolge ist - sonst haengt die
        // Zeile daran, wer wann gebucht hat.
        uasort($resources, static fn (array $a, array $b): int
            => [$a['sort_key'] ?? 0, $a['name']] <=> [$b['sort_key'] ?? 0, $b['name']]);

        return array_map('intval', array_keys($resources));
    }

    private static function mergeCalendars(array $existing, array $remoteCalendars): array
    {
        $merged = [];

        foreach ($remoteCalendars as $calendar) {
            $id = (int) ($calendar['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $remoteColor = (string) ($calendar['color'] ?? '#3388ff');

            $merged[$id] = [
                'name' => (string) ($calendar['name'] ?? ''),
                'enabled' => (bool) ($existing[$id]['enabled'] ?? false),
                'color' => (string) ($existing[$id]['color'] ?? $remoteColor),
                'default_color' => $remoteColor,
                'default_image_id' => (int) ($existing[$id]['default_image_id'] ?? 0),
                // ChurchTools' eigene Einschaetzung, nicht unsere: Ein Kalender
                // ohne `isPublic` ist dort nicht zur Veroeffentlichung gedacht.
                // Fehlt das Feld ganz (aeltere Instanz, geaenderte Antwortform),
                // gilt bewusst `true` - ein Fehlalarm auf jedem Kalender waere
                // schlimmer als ein ausbleibender Hinweis.
                'is_public' => (bool) ($calendar['isPublic'] ?? true),
            ];
        }

        return $merged;
    }
}
