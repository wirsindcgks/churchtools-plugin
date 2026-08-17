<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\EventWindow;
use ChurchToolsPlugin\Frontend\Icons;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\SyncEngine;
use Throwable;

final class SettingsPage
{
    private const OPTION_KEY = 'ctp_settings';
    private const PAGE_SLUG = 'churchtools-plugin';
    private const DEFAULT_TAB = 'status';

    private string $pageHook = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_ctp_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_ctp_fetch_calendars', [$this, 'ajaxFetchCalendars']);
        add_action('wp_ajax_ctp_run_sync', [$this, 'ajaxRunSync']);
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
            'sync' => __('Synchronisation', 'churchtools-plugin'),
            'design' => __('Design', 'churchtools-plugin'),
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
            'sync' => 'update',
            'design' => 'admin-appearance',
            'events' => 'list-view',
            'updates' => 'cloud-upload',
        ];
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->pageHook) {
            return;
        }

        wp_enqueue_media();
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
        $calendarsPage = self::PAGE_SLUG . '_calendars';
        $syncPage = self::PAGE_SLUG . '_sync';

        add_settings_section('ctp_instance', __('ChurchTools-Instanz', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('instance', __('Instanz', 'churchtools-plugin'), [$this, 'renderInstanceField'], $connectionPage, 'ctp_instance');

        add_settings_section('ctp_api', __('API-Key & Verbindungstest', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('api_key', __('API-Key', 'churchtools-plugin'), [$this, 'renderApiKeyField'], $connectionPage, 'ctp_api');

        add_settings_section('ctp_calendars', '', '__return_false', $calendarsPage);
        add_settings_field('calendars', __('Kalender', 'churchtools-plugin'), [$this, 'renderCalendarsField'], $calendarsPage, 'ctp_calendars');

        add_settings_section('ctp_sync', __('Sync-Einstellungen', 'churchtools-plugin'), '__return_false', $syncPage);
        add_settings_field('sync_interval', __('Sync-Intervall', 'churchtools-plugin'), [$this, 'renderSyncIntervalField'], $syncPage, 'ctp_sync');
        add_settings_field('sync_days_ahead', __('Sync-Zeitraum (Tage in die Zukunft)', 'churchtools-plugin'), [$this, 'renderSyncDaysAheadField'], $syncPage, 'ctp_sync');
        add_settings_field('retention_days', __('Aufbewahrung nach Event-Ende (Tage)', 'churchtools-plugin'), [$this, 'renderRetentionField'], $syncPage, 'ctp_sync');
        add_settings_field('keep_data_on_uninstall', __('Beim Deinstallieren', 'churchtools-plugin'), [$this, 'renderKeepDataOnUninstallField'], $syncPage, 'ctp_sync');

        // Two page slugs instead of one so renderPage() can wrap each in its own
        // .ctp-panel box — "Kachel" (how the card itself looks) and "Popup /
        // eigene Seite" (what a click does + how that detail view looks) are
        // different concerns that were previously stacked in a single
        // undifferentiated form, mirroring the two separate preview panels on
        // the right (renderDesignPreview()/renderDetailPreview()). The page
        // slug only affects which do_settings_sections() call renders a
        // section — save behavior is governed solely by settings_fields(
        // self::PAGE_SLUG) in renderPage(), unaffected by this split.
        $designTilePage = self::PAGE_SLUG . '_design_tile';
        add_settings_section('ctp_design_order', __('Reihenfolge der Kartenelemente', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('element_order', __('Reihenfolge', 'churchtools-plugin'), [$this, 'renderElementOrderField'], $designTilePage, 'ctp_design_order');
        add_settings_section('ctp_design_corners', __('Eckenstil', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('corner_style', __('Ecken', 'churchtools-plugin'), [$this, 'renderCornerStyleField'], $designTilePage, 'ctp_design_corners');
        add_settings_section('ctp_design_visibility', __('Sichtbare Felder', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('hidden_elements', __('Felder', 'churchtools-plugin'), [$this, 'renderFieldVisibilityField'], $designTilePage, 'ctp_design_visibility');
        add_settings_section('ctp_design_media', __('Bildgröße', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('media_aspect_ratio', __('Seitenverhältnis', 'churchtools-plugin'), [$this, 'renderMediaAspectRatioField'], $designTilePage, 'ctp_design_media');
        add_settings_section('ctp_design_accent', __('Akzentfarbe', 'churchtools-plugin'), '__return_false', $designTilePage);
        add_settings_field('accent_color', __('Akzentfarbe', 'churchtools-plugin'), [$this, 'renderAccentColorField'], $designTilePage, 'ctp_design_accent');

        $designDetailPage = self::PAGE_SLUG . '_design_detail';
        add_settings_section('ctp_design_click', __('Klickverhalten', 'churchtools-plugin'), '__return_false', $designDetailPage);
        add_settings_field('click_behavior', __('Bei Klick auf eine Kachel', 'churchtools-plugin'), [$this, 'renderClickBehaviorField'], $designDetailPage, 'ctp_design_click');
        add_settings_section('ctp_design_detail_order', __('Reihenfolge der Detailansicht', 'churchtools-plugin'), '__return_false', $designDetailPage);
        add_settings_field('detail_element_order', __('Reihenfolge', 'churchtools-plugin'), [$this, 'renderDetailElementOrderField'], $designDetailPage, 'ctp_design_detail_order');

        // Third box on the Design tab: neither how a card looks nor what a click
        // does, but how much of the calendar a list shows at once — see
        // EventWindow/EventPager for the mechanics.
        $designListPage = self::PAGE_SLUG . '_design_list';
        add_settings_section('ctp_design_paging', __('Angezeigter Zeitraum', 'churchtools-plugin'), '__return_false', $designListPage);
        add_settings_field('paging_months', __('Zeitraum pro Seite', 'churchtools-plugin'), [$this, 'renderPagingMonthsField'], $designListPage, 'ctp_design_paging');

        $updatesPage = self::PAGE_SLUG . '_updates';
        add_settings_section('ctp_updates', __('Plugin-Updates über GitHub', 'churchtools-plugin'), '__return_false', $updatesPage);
        add_settings_field('github_token', __('GitHub-Token', 'churchtools-plugin'), [$this, 'renderGitHubTokenField'], $updatesPage, 'ctp_updates');
    }

    public static function defaults(): array
    {
        return [
            'instance' => '',
            'api_key' => '',
            /**
             * Keyed by ChurchTools calendar ID:
             * [ 'name' => string, 'enabled' => bool, 'color' => '#rrggbb',
             *   'default_color' => '#rrggbb' (ChurchTools' own color, for the "reset" button, see renderCalendarRow()),
             *   'default_image_id' => int (attachment ID) ]
             */
            'calendars' => [],
            'sync_interval' => 'hourly',
            'sync_days_ahead' => 180,
            'retention_days' => 30,
            'keep_data_on_uninstall' => false,
            'element_order' => CardDesign::DEFAULT_ORDER,
            'corner_style' => 'rounded',
            'hidden_elements' => [],
            'media_aspect_ratio' => 'wide',
            'accent_color_enabled' => false,
            // Matches frontend.css's own --ctp-accent fallback, so the color
            // picker starts on the value that's already visually in effect
            // rather than on an arbitrary, surprising default.
            'accent_color' => '#2563eb',
            'click_behavior' => 'popup',
            'detail_element_order' => DetailDesign::DEFAULT_ORDER,
            'paging_months' => EventWindow::DEFAULT_MONTHS,
            'github_token' => '',
        ];
    }

    public static function get(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());
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

    public static function getDecryptedApiKey(): string
    {
        $stored = self::get()['api_key'];
        if ($stored === '') {
            return '';
        }

        $decrypted = Crypto::decrypt($stored);

        return self::isPlausibleApiKey($decrypted) ? $decrypted : '';
    }

    /**
     * Unlike getDecryptedApiKey(), no "plausible token" check: a GitHub token going
     * missing after an AUTH_KEY rotation just means update checks silently stop
     * finding updates, not a broken sync — much lower stakes than a wrong
     * Authorization header reaching the ChurchTools API on every hourly cron run.
     */
    public static function getDecryptedGitHubToken(): string
    {
        $stored = self::get()['github_token'];

        return $stored === '' ? '' : Crypto::decrypt($stored);
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
        $stored = self::get()['api_key'];
        if ($stored === '') {
            return false;
        }

        return !self::isPlausibleApiKey(Crypto::decrypt($stored));
    }

    private static function isPlausibleApiKey(string $token): bool
    {
        return $token !== '' && strlen($token) <= 512 && ctype_print($token);
    }

    public static function apiKeyDecryptionErrorMessage(): string
    {
        return __('Der gespeicherte API-Key lässt sich nicht mehr entschlüsseln (z. B. nach einer Änderung von AUTH_KEY) – bitte im Tab „Verbindung" neu eingeben.', 'churchtools-plugin');
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
        $githubToken = trim((string) ($input['github_token'] ?? ''));

        $syncInterval = $existing['sync_interval'];
        if (array_key_exists('sync_interval', $input) && in_array($input['sync_interval'], ['hourly', 'twicedaily', 'daily'], true)) {
            $syncInterval = $input['sync_interval'];
        }

        $cornerStyle = $existing['corner_style'];
        if (array_key_exists('corner_style', $input) && in_array($input['corner_style'], CardDesign::CORNER_STYLES, true)) {
            $cornerStyle = $input['corner_style'];
        }

        $clickBehavior = $existing['click_behavior'];
        if (array_key_exists('click_behavior', $input) && in_array($input['click_behavior'], ['none', 'popup', 'page'], true)) {
            $clickBehavior = $input['click_behavior'];
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

        return [
            'instance' => array_key_exists('instance', $input)
                ? self::sanitizeInstance((string) $input['instance'])
                : $existing['instance'],
            'api_key' => $apiKey === '' ? $existing['api_key'] : Crypto::encrypt($apiKey),
            'calendars' => array_key_exists('calendars', $input)
                ? self::sanitizeCalendars((array) $input['calendars'], $existing['calendars'])
                : $existing['calendars'],
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
            'element_order' => array_key_exists('element_order', $input)
                ? self::sanitizeElementOrder((string) $input['element_order'])
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
            'click_behavior' => $clickBehavior,
            'detail_element_order' => array_key_exists('detail_element_order', $input)
                ? self::sanitizeDetailElementOrder((string) $input['detail_element_order'])
                : $existing['detail_element_order'],
            'paging_months' => array_key_exists('paging_months', $input)
                ? EventWindow::sanitizeMonths((int) $input['paging_months'])
                : $existing['paging_months'],
            'github_token' => $githubToken === '' ? $existing['github_token'] : Crypto::encrypt($githubToken),
        ];
    }

    /**
     * The Design tab's hidden input submits the drag&drop order (the six fixed
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
     * view's own (separator-free) six-key order.
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

        printf(
            '<span class="ctp-field-with-button">'
            . '<input type="password" id="ctp-api-key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />'
            . '<button type="button" class="button" id="ctp-test-connection">%3$s</button>'
            . '<span id="ctp-test-connection-result"></span>'
            . '</span>',
            esc_attr(self::OPTION_KEY),
            $hasKey ? esc_attr__('Hinterlegt – zum Ändern neuen Key eingeben', 'churchtools-plugin') : '',
            esc_html__('Verbindung testen', 'churchtools-plugin')
        );
    }

    public function renderCalendarsField(): void
    {
        $calendars = self::get()['calendars'];
        uasort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        ?>
        <p>
            <button type="button" class="button" id="ctp-fetch-calendars">
                <?php esc_html_e('Kalender von ChurchTools laden', 'churchtools-plugin'); ?>
            </button>
            <span id="ctp-fetch-calendars-result"></span>
        </p>
        <?php if ($calendars === []) : ?>
            <p class="ctp-empty-state"><?php esc_html_e('Noch keine Kalender geladen.', 'churchtools-plugin'); ?></p>
        <?php else : ?>
            <table class="widefat striped ctp-calendars-table ctp-borderless">
                <thead>
                    <tr>
                        <th class="ctp-col-active"><?php esc_html_e('Aktiv', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        <th class="ctp-col-color"><?php esc_html_e('Farbe', 'churchtools-plugin'); ?></th>
                        <th class="ctp-col-image"><?php esc_html_e('Standardbild', 'churchtools-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calendars as $id => $calendar) : ?>
                        <?php $this->renderCalendarRow((int) $id, $calendar); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e('Aktive Kalender werden synchronisiert. Ansprechbar im Shortcode per ID oder Name, z. B.', 'churchtools-plugin'); ?>
                <code>[ctp_events calendar="<?php echo esc_html((string) array_key_first($calendars)); ?>,<?php echo esc_html(reset($calendars)['name']); ?>"]</code>
            </p>
            <p class="description">
                <?php esc_html_e('Das Standardbild wird angezeigt, sobald ein Termin dieses Kalenders kein eigenes Bild hat.', 'churchtools-plugin'); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    private function renderCalendarRow(int $id, array $calendar): void
    {
        $fieldBase = sprintf('%s[calendars][%d]', self::OPTION_KEY, $id);
        $imageId = (int) $calendar['default_image_id'];
        $imageUrl = $imageId ? (string) wp_get_attachment_image_url($imageId, 'thumbnail') : '';
        ?>
        <tr>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($fieldBase); ?>[enabled]" value="1" <?php checked(!empty($calendar['enabled'])); ?> />
            </td>
            <td>
                <?php echo esc_html($calendar['name']); ?>
                <br /><code class="ctp-muted-code">ID: <?php echo (int) $id; ?></code>
            </td>
            <td class="ctp-color-field">
                <input type="color" class="ctp-color-input" name="<?php echo esc_attr($fieldBase); ?>[color]" value="<?php echo esc_attr($calendar['color']); ?>" />
                <button type="button" class="button-link ctp-color-reset" data-default-color="<?php echo esc_attr($calendar['default_color'] ?? $calendar['color']); ?>" title="<?php esc_attr_e('Auf ChurchTools-Standardfarbe zurücksetzen', 'churchtools-plugin'); ?>">
                    <?php esc_html_e('Zurücksetzen', 'churchtools-plugin'); ?>
                </button>
            </td>
            <td class="ctp-image-field">
                <input type="hidden" class="ctp-image-id" name="<?php echo esc_attr($fieldBase); ?>[default_image_id]" value="<?php echo esc_attr((string) $imageId); ?>" />
                <img class="ctp-image-preview" src="<?php echo esc_url($imageUrl); ?>" alt="" <?php echo $imageUrl ? '' : 'hidden'; ?> />
                <button type="button" class="button ctp-image-select"><?php esc_html_e('Bild wählen', 'churchtools-plugin'); ?></button>
                <button type="button" class="button-link ctp-image-remove" <?php echo $imageUrl ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Entfernen', 'churchtools-plugin'); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    public function renderSyncIntervalField(): void
    {
        $current = self::get()['sync_interval'];
        $options = [
            'hourly' => __('Stündlich', 'churchtools-plugin'),
            'twicedaily' => __('Zweimal täglich', 'churchtools-plugin'),
            'daily' => __('Täglich', 'churchtools-plugin'),
        ];

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[sync_interval]">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
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
            . esc_html__('Liste und Grid zeigen zunächst den angebrochenen aktuellen Monat plus so viele weitere Monate; ein Klick auf „Weitere Termine laden" hängt jeweils den nächsten Zeitraum an. Kürzere Zeiträume laden schneller. Ohne Termine im Zeitraum springt die Ansicht automatisch zum nächsten Monat mit Terminen.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Für die Ansicht „Nächster Termin" ohne Wirkung – sie zeigt weiterhin eine feste Anzahl Termine (Attribut „limit").', 'churchtools-plugin')
            . '</p>';
    }

    public function renderRetentionField(): void
    {
        printf(
            '<input type="number" min="0" name="%1$s[retention_days]" value="%2$s" class="small-text" /> %3$s',
            esc_attr(self::OPTION_KEY),
            esc_attr((string) self::get()['retention_days']),
            esc_html__('Tage', 'churchtools-plugin')
        );
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
            'media' => __('Bild / Datum', 'churchtools-plugin'),
            'calendar' => __('Kalendername', 'churchtools-plugin'),
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'excerpt' => __('Beschreibungsauszug', 'churchtools-plugin'),
            'meta' => __('Datum & Ort', 'churchtools-plugin'),
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
        <ul id="ctp-design-order" class="ctp-order-list">
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
        </p>
        <p class="description">
            <?php esc_html_e('Reihenfolge per Drag&Drop ändern (Maus/Trackpad – Touch-Sortierung wird derzeit nicht unterstützt). Die Bild-Position bestimmt nur, ob das Bild über oder unter dem Textblock erscheint, nicht zwischen einzelnen Textzeilen. Trennlinien und Abstände lassen sich beliebig oft einfügen und wie jedes andere Element per Drag&Drop verschieben oder über das „×“ wieder entfernen.', 'churchtools-plugin'); ?>
        </p>
        <?php
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
            . esc_html__('Angehakte Felder werden auf der Kachel nicht mehr angezeigt. Der Titel bleibt immer sichtbar. Gilt nur für die Kachel, nicht für Popup/eigene Seite (dort weiterhin über die Reihenfolge unten steuerbar).', 'churchtools-plugin')
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
            . esc_html__('Seitenverhältnis des Bildes in Grid-Kachel und Hero („Nächster Termin"). Ohne Wirkung in der Listenansicht, die kein Bild zeigt.', 'churchtools-plugin')
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
        printf(
            '<p><input type="color" id="ctp-design-accent-color" name="%1$s[accent_color]" value="%2$s" %3$s /></p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['accent_color']),
            disabled(empty($settings['accent_color_enabled']), true, false)
        );
        echo '<p class="description">'
            . esc_html__('Ersetzt die vom Theme übernommene Standardfarbe für Icons, Datumsbadges und Ränder sowie die aktiven Buttons des Eventfinders. Termine, deren Kalender bereits eine eigene Farbe hat, behalten weiterhin diese Kalenderfarbe.', 'churchtools-plugin')
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
            'meta' => __('Datum & Ort', 'churchtools-plugin'),
            'description' => __('Beschreibung', 'churchtools-plugin'),
        ];
    }

    public function renderDetailElementOrderField(): void
    {
        $labels = self::detailElementOrderLabels();
        $order = self::get()['detail_element_order'];
        ?>
        <ul id="ctp-design-detail-order" class="ctp-order-list">
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
        <p class="description">
            <?php esc_html_e('Reihenfolge der Felder in Popup und eigener Seite, per Drag&Drop änderbar (Maus/Trackpad).', 'churchtools-plugin'); ?>
        </p>
        <?php
    }

    public function renderGitHubTokenField(): void
    {
        $hasToken = self::get()['github_token'] !== '';

        printf(
            '<input type="password" name="%1$s[github_token]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />'
            . '<p class="description">%3$s</p>',
            esc_attr(self::OPTION_KEY),
            $hasToken ? esc_attr__('Hinterlegt – zum Ändern neuen Token eingeben', 'churchtools-plugin') : '',
            esc_html__('Nur nötig, da das GitHub-Repository privat ist: ein Personal Access Token mit Lesezugriff auf Releases, damit das Plugin neue Versionen erkennen und installieren kann.', 'churchtools-plugin')
        );
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
            $settings['accent_color_enabled'] ? $settings['accent_color'] : ''
        );
        $hidden = $settings['hidden_elements'];
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Vorschau als Grid-Kachel – die Einstellung gilt gleichermaßen für Grid, Liste und „Nächster Termin".', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-events ctp-events--grid ctp-design-preview-frame" id="ctp-design-preview" style="<?php echo esc_attr($style); ?>">
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
                                    <span class="ctp-events__color-dot" aria-hidden="true"></span>
                                    <?php esc_html_e('Beispiel-Kalender', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__title">
                                    <?php esc_html_e('Beispiel-Termin', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__subtitle" data-key="subtitle" <?php echo in_array('subtitle', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Untertitel-Beispiel', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__meta" data-key="meta" <?php echo in_array('meta', $hidden, true) ? 'hidden' : ''; ?>>
                                    <span class="ctp-events__meta-item">
                                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                        <?php echo Icons::clock(); ?>
                                        24.12.2026, 18:00
                                    </span>
                                    <span class="ctp-events__meta-item">
                                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                        <?php echo Icons::location(); ?>
                                        <?php esc_html_e('Gemeindehaus', 'churchtools-plugin'); ?>
                                    </span>
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

        $blocks = [
            'media' => '<div class="ctp-events__detail-media ctp-design-preview-block__media" aria-hidden="true"></div>',
            'calendar' => '<span class="ctp-events__eyebrow"><span class="ctp-events__color-dot" aria-hidden="true"></span>'
                . esc_html__('Beispiel-Kalender', 'churchtools-plugin') . '</span>',
            'title' => '<h2 class="ctp-events__detail-title">' . esc_html__('Beispiel-Termin', 'churchtools-plugin') . '</h2>',
            'subtitle' => '<p class="ctp-events__subtitle">' . esc_html__('Untertitel-Beispiel', 'churchtools-plugin') . '</p>',
            'meta' => '<p class="ctp-events__meta"><span class="ctp-events__meta-item">' . Icons::clock() . ' 24.12.2026, 18:00</span>'
                . '<span class="ctp-events__meta-item">' . Icons::location() . ' ' . esc_html__('Gemeindehaus', 'churchtools-plugin') . '</span></p>',
            'description' => '<div class="ctp-events__detail-description"><p>'
                . esc_html__('Vollständige Terminbeschreibung, wie sie in Popup und eigener Seite erscheint …', 'churchtools-plugin') . '</p></div>',
        ];
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau Detailansicht', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Gilt gleichermaßen für Popup und eigene Seite, sofern das Klickverhalten oben nicht auf „Keine" steht.', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-design-preview-backdrop">
                <div class="ctp-events ctp-events__detail ctp-design-preview-frame" id="ctp-design-detail-preview">
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
        <div class="ctp-panel">
            <h2><?php esc_html_e('Verwendung: Shortcode', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Termine per Shortcode in eine Seite oder einen Beitrag einbinden – dieselbe Rendering-Basis wie der Gutenberg-Block „ChurchTools Events" und das WPBakery-Element. Die Kartengestaltung oben (Reihenfolge, Eckenstil) gilt automatisch für jeden Shortcode, ohne zusätzliches Attribut.', 'churchtools-plugin'); ?>
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
                                esc_html__('Zeitraum pro Seite in Monaten (nur list/grid). 0 = globale Einstellung oben (aktuell %d).', 'churchtools-plugin'),
                                (int) self::get()['paging_months']
                            );
                            ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>paging</code></td>
                        <td><?php esc_html_e('Button „Weitere Termine laden" anzeigen (nur list/grid). 0 = nur der erste Zeitraum, ohne Nachladen.', 'churchtools-plugin'); ?></td>
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
                            &ndash; <?php esc_html_e('überschreibt das Klickverhalten oben nur für diesen Shortcode', 'churchtools-plugin'); ?>
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
                            <?php esc_html_e('Geführte „Du suchst …“-Buttons für Kalender/Zeitraum plus Suche (nur list/grid); ersetzt filter/search statt zusätzlich dazu angezeigt zu werden', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                </tbody>
            </table>

            <h3><?php esc_html_e('Beispiele', 'churchtools-plugin'); ?></h3>
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
            <p class="description">
                <?php esc_html_e('Weitere Details zu Gutenberg-Block, WPBakery-Element und Theme-Overrides: siehe readme.txt.', 'churchtools-plugin'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Landing tab (see DEFAULT_TAB): bundles what was previously scattered
     * across the Verbindung/Sync/Updates tabs into a single at-a-glance
     * overview, per the "Welcome/Status-Seite"-idea in plan.md.
     */
    private function renderStatusOverview(): void
    {
        $settings = self::get();
        $configured = $settings['instance'] !== '' && self::getDecryptedApiKey() !== '';
        $calendars = $settings['calendars'];
        $enabledCalendars = array_filter($calendars, static fn (array $calendar): bool => !empty($calendar['enabled']));
        $lastSync = get_option('ctp_last_sync', '');
        $lastError = SyncEngine::getLastError();
        $eventCount = (new EventRepository())->count();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        // Reads WP's own update-check cache (populated by GitHubUpdateChecker
        // via plugin-update-checker, see includes/Update/) instead of forcing
        // a fresh remote request on every admin page load.
        $pluginFile = plugin_basename(CTP_PLUGIN_FILE);
        $updatePlugins = get_site_transient('update_plugins');
        $availableVersion = null;
        if (is_object($updatePlugins) && isset($updatePlugins->response[$pluginFile]->new_version)) {
            $availableVersion = $updatePlugins->response[$pluginFile]->new_version;
        }
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Verbindung & Betrieb', 'churchtools-plugin'); ?></h2>
            <?php if ($lastError !== null) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the sync last failed, 2: error message */
                            esc_html__('Letzter Sync-Fehler (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date($dateFormat, $lastError['time'])),
                            esc_html($lastError['message'])
                        );
                        ?>
                    </p>
                </div>
            <?php elseif (!$configured) : ?>
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
            <?php endif; ?>
            <div class="ctp-stat-grid">
                <div class="ctp-stat-card">
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <span class="ctp-stat-card__value"><?php echo $settings['instance'] !== '' ? esc_html($settings['instance']) : '—'; ?></span>
                    <span class="ctp-stat-card__label"><?php esc_html_e('Instanz', 'churchtools-plugin'); ?></span>
                </div>
                <div class="ctp-stat-card">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <span class="ctp-stat-card__value">
                        <?php
                        printf(
                            /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                            esc_html__('%1$d von %2$d', 'churchtools-plugin'),
                            count($enabledCalendars),
                            count($calendars)
                        );
                        ?>
                    </span>
                    <span class="ctp-stat-card__label"><?php esc_html_e('Aktive Kalender', 'churchtools-plugin'); ?></span>
                </div>
                <div class="ctp-stat-card">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <span class="ctp-stat-card__value">
                        <?php
                        echo $lastSync !== ''
                            ? esc_html(mysql2date($dateFormat, $lastSync))
                            : esc_html__('noch nie', 'churchtools-plugin');
                        ?>
                    </span>
                    <span class="ctp-stat-card__label"><?php esc_html_e('Letzte Synchronisation', 'churchtools-plugin'); ?></span>
                </div>
                <div class="ctp-stat-card">
                    <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                    <span class="ctp-stat-card__value"><?php echo (int) $eventCount; ?></span>
                    <span class="ctp-stat-card__label"><?php esc_html_e('Gespeicherte Termine', 'churchtools-plugin'); ?></span>
                </div>
            </div>
            <p class="ctp-status-actions">
                <button type="button" class="button button-primary" id="ctp-run-sync">
                    <?php esc_html_e('Jetzt synchronisieren', 'churchtools-plugin'); ?>
                </button>
                <span id="ctp-run-sync-result"></span>
            </p>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Version', 'churchtools-plugin'); ?></h2>
            <table class="widefat striped ctp-status-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Installiert', 'churchtools-plugin'); ?></th>
                        <td><?php echo esc_html(CTP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Verfügbar', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if ($availableVersion !== null && version_compare($availableVersion, CTP_VERSION, '>')) : ?>
                                <?php
                                printf(
                                    /* translators: %s: newer version number available via GitHub */
                                    esc_html__('%s (Update über die Plugins-Übersicht einspielen)', 'churchtools-plugin'),
                                    esc_html($availableVersion)
                                );
                                ?>
                            <?php else : ?>
                                <?php esc_html_e('aktuell', 'churchtools-plugin'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php $changelog = self::changelogExcerpt(); ?>
            <?php if ($changelog !== []) : ?>
                <h3><?php esc_html_e('Letzte Änderungen', 'churchtools-plugin'); ?></h3>
                <ul class="ctp-changelog-excerpt">
                    <?php foreach ($changelog as $item) : ?>
                        <li><?php echo esc_html($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Reads the first release block of CHANGELOG.md (bundled with the plugin,
     * not excluded from the release zip — see .github/release-excludes.txt)
     * and returns its top-level bullet items as plain text. A lightweight
     * excerpt for the status page, not a markdown renderer.
     *
     * @return string[]
     */
    private static function changelogExcerpt(int $maxItems = 5): array
    {
        $path = CTP_PLUGIN_DIR . 'CHANGELOG.md';
        if (!is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $items = [];
        $inFirstRelease = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if ($inFirstRelease) {
                    break;
                }
                $inFirstRelease = true;
                continue;
            }
            if ($inFirstRelease && str_starts_with($line, '- ')) {
                $items[] = substr($line, 2);
                if (count($items) >= $maxItems) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Read-only overview of the actually synced wp_ctp_events rows, so an admin can
     * verify the sync really pulled the right appointments without needing DB
     * access. Reuses findUpcoming() (soonest first, capped) rather than a plain
     * "all rows" query, since a year-long sync window can hold hundreds of
     * recurring-series occurrences and the near-term ones are what matters here.
     */
    private function renderEventsOverview(): void
    {
        $repository = new EventRepository();
        $events = $repository->findUpcoming([], 200);
        $totalCount = $repository->count();
        $calendars = self::get()['calendars'];
        ?>
        <div class="ctp-panel">
            <p class="description">
                <?php
                printf(
                    /* translators: 1: number of events shown below, 2: total number of stored events */
                    esc_html__('Zeigt die nächsten %1$d von insgesamt %2$d gespeicherten Terminen.', 'churchtools-plugin'),
                    (int) count($events),
                    (int) $totalCount
                );
                ?>
            </p>
            <?php if ($events === []) : ?>
                <p class="ctp-empty-state"><?php esc_html_e('Noch keine Termine synchronisiert.', 'churchtools-plugin'); ?></p>
            <?php else : ?>
                <table class="widefat striped ctp-borderless">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Titel', 'churchtools-plugin'); ?></th>
                            <th><?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?></th>
                            <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event) : ?>
                            <?php $this->renderEventOverviewRow($event, $calendars); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderEventOverviewRow(array $event, array $calendars): void
    {
        $calendarId = (int) $event['ct_calendar_id'];
        $calendar = $calendars[$calendarId] ?? null;
        $calendarName = $calendar['name'] ?? sprintf('#%d', $calendarId);
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $event['id'])); ?>">
                    <?php echo esc_html($event['title']); ?>
                </a>
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

    private static function eventDetailUrl(int $id): string
    {
        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => 'events', 'event_id' => $id],
            admin_url('admin.php')
        );
    }

    private static function eventsOverviewUrl(): string
    {
        return add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'events'], admin_url('admin.php'));
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
                '<p><a class="ctp-back-link" href="%s">&larr; %s</a></p></div>',
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
        <p><a class="ctp-back-link" href="<?php echo esc_url($backUrl); ?>">&larr; <?php esc_html_e('Zurück zur Übersicht', 'churchtools-plugin'); ?></a></p>
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

            <table class="form-table">
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
                            <td><?php echo wp_kses_post($event['description']); ?></td>
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
            <nav class="nav-tab-wrapper">
                <?php foreach (self::tabs() as $tabSlug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tabSlug], admin_url('admin.php'))); ?>"
                        class="nav-tab <?php echo $tab === $tabSlug ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr($icons[$tabSlug] ?? 'admin-generic'); ?>" aria-hidden="true"></span>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'status') : ?>
                <?php $this->renderStatusOverview(); ?>
            <?php elseif ($tab === 'events') : ?>
                <?php
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which event to display), not a state change; same pattern as currentTab()'s $_GET['tab'] read above.
                $eventId = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
                if ($eventId > 0) {
                    $this->renderEventDetail($eventId);
                } else {
                    $this->renderEventsOverview();
                }
                ?>
            <?php elseif ($tab === 'design') : ?>
                <?php // Settings form on the left, live preview on the right — see .ctp-design-layout in admin.css. ?>
                <div class="ctp-design-layout">
                    <form method="post" action="options.php" class="ctp-design-form">
                        <?php settings_fields(self::PAGE_SLUG); ?>
                        <?php // Two boxes, one per concern — see the registerSettings() comment on $designTilePage/$designDetailPage. ?>
                        <div class="ctp-panel">
                            <?php do_settings_sections(self::PAGE_SLUG . '_design_tile'); ?>
                        </div>
                        <div class="ctp-panel">
                            <?php do_settings_sections(self::PAGE_SLUG . '_design_detail'); ?>
                        </div>
                        <div class="ctp-panel">
                            <?php do_settings_sections(self::PAGE_SLUG . '_design_list'); ?>
                            <?php submit_button(); ?>
                        </div>
                    </form>
                    <div class="ctp-design-previews">
                        <?php $this->renderDesignPreview(); ?>
                        <?php $this->renderDetailPreview(); ?>
                    </div>
                </div>
                <?php $this->renderShortcodeReference(); ?>
            <?php else : ?>
                <form method="post" action="options.php" class="ctp-panel">
                    <?php
                    settings_fields(self::PAGE_SLUG);
                    do_settings_sections(self::PAGE_SLUG . '_' . $tab);
                    submit_button();
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <script>
        document.getElementById('ctp-test-connection')?.addEventListener('click', function () {
            var result = document.getElementById('ctp-test-connection-result');
            result.textContent = '<?php echo esc_js(__('Prüfe…', 'churchtools-plugin')); ?>';

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_test_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_test_connection')); ?>',
                    instance: document.getElementById('ctp-instance').value,
                    api_key: document.getElementById('ctp-api-key').value,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var fallback = data.success
                        ? '<?php echo esc_js(__('Verbindung erfolgreich', 'churchtools-plugin')); ?>'
                        : '<?php echo esc_js(__('Verbindung fehlgeschlagen', 'churchtools-plugin')); ?>';

                    result.textContent = (data.data && data.data.message) ? data.data.message : fallback;
                });
        });

        document.getElementById('ctp-fetch-calendars')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-fetch-calendars-result');
            button.disabled = true;
            result.textContent = '<?php echo esc_js(__('Lade…', 'churchtools-plugin')); ?>';

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ctp_fetch_calendars',
                    nonce: '<?php echo esc_js(wp_create_nonce('ctp_fetch_calendars')); ?>',
                    instance: document.getElementById('ctp-instance').value,
                    api_key: document.getElementById('ctp-api-key').value,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    button.disabled = false;
                    result.textContent = (data.data && data.data.message)
                        ? data.data.message
                        : '<?php echo esc_js(__('Laden fehlgeschlagen', 'churchtools-plugin')); ?>';
                });
        });

        document.getElementById('ctp-run-sync')?.addEventListener('click', function () {
            var button = this;
            var result = document.getElementById('ctp-run-sync-result');
            button.disabled = true;
            result.textContent = '<?php echo esc_js(__('Synchronisiere…', 'churchtools-plugin')); ?>';

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
                    result.textContent = (data.data && data.data.message)
                        ? data.data.message
                        : '<?php echo esc_js(__('Synchronisation fehlgeschlagen', 'churchtools-plugin')); ?>';
                });
        });

        document.querySelectorAll('.ctp-color-reset').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var cell = button.closest('.ctp-color-field');
                cell.querySelector('.ctp-color-input').value = button.dataset.defaultColor;
            });
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
            });
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
            $client = new Client($connection['base_url'], $connection['api_key']);
            $settings = self::get();
            $merged = self::mergeCalendars($settings['calendars'], $client->getCalendars());

            // register_setting() hooks sanitize_option_{option} onto every
            // update_option() call for this option, not just Settings API form
            // submissions — without removing it here, sanitizeSettings() would run
            // $merged (already-trusted, freshly-fetched data) back through
            // sanitizeCalendars()'s "only IDs already known" allowlist and silently
            // drop every calendar on the very first fetch (nothing was "known" yet).
            remove_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);
            update_option(self::OPTION_KEY, array_merge($settings, ['calendars' => $merged]));
            add_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitizeSettings']);

            wp_send_json_success(['count' => count($merged)]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public function ajaxRunSync(): void
    {
        check_ajax_referer('ctp_run_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $settings = self::get();
        $calendarIds = self::getEnabledCalendarIds();

        if ($settings['instance'] === '' || $settings['api_key'] === '' || $calendarIds === []) {
            wp_send_json_error(['message' => __('Bitte zuerst Verbindung und mindestens einen aktiven Kalender einrichten.', 'churchtools-plugin')]);
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
            'last_sync' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) get_option('ctp_last_sync', '')),
        ]);
    }

    /**
     * Keeps enabled/color/default_image_id for calendars that still exist remotely,
     * seeds new ones as disabled with ChurchTools' own color, and drops ones that
     * were removed on the ChurchTools side. `default_color` is always overwritten
     * with ChurchTools' current value (never carried over from $existing) so the
     * "Auf Standardfarbe zurücksetzen" button (renderCalendarRow()) keeps pointing
     * at ChurchTools' actual color even if it changed there since the last fetch.
     */
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
            ];
        }

        return $merged;
    }
}
