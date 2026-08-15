<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\Icons;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\SyncEngine;
use Throwable;

final class SettingsPage
{
    private const OPTION_KEY = 'ctp_settings';
    private const PAGE_SLUG = 'churchtools-plugin';
    private const DEFAULT_TAB = 'connection';

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

        $designPage = self::PAGE_SLUG . '_design';
        add_settings_section('ctp_design_order', __('Reihenfolge der Kartenelemente', 'churchtools-plugin'), '__return_false', $designPage);
        add_settings_field('element_order', __('Reihenfolge', 'churchtools-plugin'), [$this, 'renderElementOrderField'], $designPage, 'ctp_design_order');
        add_settings_section('ctp_design_corners', __('Eckenstil', 'churchtools-plugin'), '__return_false', $designPage);
        add_settings_field('corner_style', __('Ecken', 'churchtools-plugin'), [$this, 'renderCornerStyleField'], $designPage, 'ctp_design_corners');

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
            'element_order' => CardDesign::DEFAULT_ORDER,
            'corner_style' => 'rounded',
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
            'element_order' => array_key_exists('element_order', $input)
                ? self::sanitizeElementOrder((string) $input['element_order'])
                : $existing['element_order'],
            'corner_style' => $cornerStyle,
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
        $style = CardDesign::styleAttribute($settings['element_order'], $settings['corner_style']);
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
                            <div class="ctp-events__media">
                                <span class="ctp-events__date-badge" aria-hidden="true">
                                    <span class="ctp-events__day">24</span>
                                    <span class="ctp-events__month"><?php esc_html_e('Dez', 'churchtools-plugin'); ?></span>
                                </span>
                            </div>
                            <div class="ctp-events__content" id="ctp-design-preview-content">
                                <span class="ctp-events__eyebrow">
                                    <span class="ctp-events__color-dot" aria-hidden="true"></span>
                                    <?php esc_html_e('Beispiel-Kalender', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__title">
                                    <?php esc_html_e('Beispiel-Termin', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__subtitle"><?php esc_html_e('Untertitel-Beispiel', 'churchtools-plugin'); ?></span>
                                <span class="ctp-events__meta">
                                    <span class="ctp-events__meta-item">
                                        <?php echo Icons::clock(); ?>
                                        24.12.2026, 18:00
                                    </span>
                                    <span class="ctp-events__meta-item">
                                        <?php echo Icons::location(); ?>
                                        <?php esc_html_e('Gemeindehaus', 'churchtools-plugin'); ?>
                                    </span>
                                </span>
                                <p class="ctp-events__excerpt">
                                    <?php esc_html_e('Kurzer Auszug aus der Terminbeschreibung, wie er auf der Kachel erscheint …', 'churchtools-plugin'); ?>
                                </p>
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
                    ? sprintf('[ctp_events calendar="%s" layout="list" limit="10"]', $exampleCalendar)
                    : '[ctp_events layout="list" limit="10"]',
            ],
            [
                'label' => __('Grid', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" columns="3" limit="8"]',
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
                        <td><?php esc_html_e('Anzahl angezeigter Termine', 'churchtools-plugin'); ?></td>
                        <td><code>10</code></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Nur bei Grid-Layout relevant: Spaltenzahl auf breiten Bildschirmen (2–6)', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
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

    private function renderSyncStatus(): void
    {
        $lastSync = get_option('ctp_last_sync', '');
        $lastError = SyncEngine::getLastError();
        $eventCount = (new EventRepository())->count();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Status', 'churchtools-plugin'); ?></h2>
            <?php if ($lastError !== null) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the sync last failed, 2: error message */
                            esc_html__('Letzter Sync-Fehler (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $lastError['time'])),
                            esc_html($lastError['message'])
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <p>
                <?php
                printf(
                    /* translators: %s: date/time of last sync, or a dash if none happened yet */
                    esc_html__('Letzte Synchronisation: %s', 'churchtools-plugin'),
                    $lastSync !== '' ? esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $lastSync)) : '—'
                );
                ?>
                <br />
                <?php
                printf(
                    /* translators: %d: number of events currently stored locally */
                    esc_html__('Gespeicherte Termine: %d', 'churchtools-plugin'),
                    (int) $eventCount
                );
                ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="ctp-run-sync">
                    <?php esc_html_e('Jetzt synchronisieren', 'churchtools-plugin'); ?>
                </button>
                <span id="ctp-run-sync-result"></span>
            </p>
        </div>
        <?php
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
                <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
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

            <?php if ($tab === 'sync') : ?>
                <?php $this->renderSyncStatus(); ?>
            <?php endif; ?>

            <?php if ($tab === 'events') : ?>
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
                    <form method="post" action="options.php" class="ctp-panel">
                        <?php
                        settings_fields(self::PAGE_SLUG);
                        do_settings_sections(self::PAGE_SLUG . '_' . $tab);
                        submit_button();
                        ?>
                    </form>
                    <?php $this->renderDesignPreview(); ?>
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
