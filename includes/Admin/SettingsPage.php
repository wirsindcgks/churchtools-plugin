<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Frontend\CardDesign;
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
        ];
    }

    private static function currentTab(): string
    {
        $tab = sanitize_key((string) ($_GET['tab'] ?? self::DEFAULT_TAB));

        return array_key_exists($tab, self::tabs()) ? $tab : self::DEFAULT_TAB;
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->pageHook) {
            return;
        }

        wp_enqueue_media();

        // Own handle rather than reusing Assets::STYLE_HANDLE — that class'
        // enqueue is conditioned on the current *frontend* request using the
        // shortcode/block, an unrelated concern to whether the admin's Design
        // tab preview needs the stylesheet.
        if (self::currentTab() === 'design') {
            wp_enqueue_style('ctp-admin-design', CTP_PLUGIN_URL . 'assets/css/frontend.css', [], CTP_VERSION);
            wp_enqueue_script('ctp-admin-design', CTP_PLUGIN_URL . 'assets/js/admin-design.js', [], CTP_VERSION, true);
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
    }

    public static function defaults(): array
    {
        return [
            'instance' => '',
            'api_key' => '',
            /**
             * Keyed by ChurchTools calendar ID:
             * [ 'name' => string, 'enabled' => bool, 'color' => '#rrggbb', 'default_image_id' => int (attachment ID) ]
             */
            'calendars' => [],
            'sync_interval' => 'hourly',
            'sync_days_ahead' => 180,
            'retention_days' => 30,
            'element_order' => CardDesign::DEFAULT_ORDER,
            'corner_style' => 'rounded',
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
        $apiKey = self::get()['api_key'];

        return $apiKey === '' ? '' : Crypto::decrypt($apiKey);
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
        ];
    }

    /**
     * The Design tab's hidden input submits the drag&drop order as a
     * comma-separated string. Unlike every other field in this method, an
     * invalid value here does NOT fall back to $existing — a *present but
     * malformed* value (JS bug, tampered POST, a duplicate/missing/unknown
     * key) snaps straight to CardDesign::DEFAULT_ORDER instead. Falling back
     * to $existing would risk silently keeping a half-applied permutation;
     * the known-good default is the safer failure mode. The ordinary "key
     * entirely absent from $input" case (a different tab's form was
     * submitted) still falls back to $existing in sanitizeSettings() above,
     * same as every other field.
     */
    private static function sanitizeElementOrder(string $raw): array
    {
        $keys = array_filter(array_map('trim', explode(',', $raw)));

        $isValidPermutation = count($keys) === count(CardDesign::ELEMENT_KEYS)
            && count($keys) === count(array_unique($keys))
            && array_diff($keys, CardDesign::ELEMENT_KEYS) === [];

        return $isValidPermutation ? array_values($keys) : CardDesign::DEFAULT_ORDER;
    }

    /**
     * Accepts either a bare instance name ("cg-ks") or a full URL a user might
     * paste by habit ("https://cg-ks.church.tools/") and normalizes both to "cg-ks".
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
                'default_image_id' => absint($row['default_image_id'] ?? 0),
            ];
        }

        return $calendars;
    }

    public function renderInstanceField(): void
    {
        printf(
            '<span style="display:inline-flex;align-items:center;gap:4px;">'
            . '<code>https://</code>'
            . '<input type="text" id="ctp-instance" name="%1$s[instance]" value="%2$s" class="regular-text" style="width:160px;" placeholder="cg-ks" pattern="[a-z0-9-]+" />'
            . '<code>.church.tools</code>'
            . '</span>'
            . '<p class="description">%3$s</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr(self::get()['instance']),
            esc_html__('Nur der Instanz-Name eintragen, z. B. „cg-ks“ für https://cg-ks.church.tools', 'churchtools-plugin')
        );
    }

    public function renderApiKeyField(): void
    {
        $hasKey = self::get()['api_key'] !== '';

        printf(
            '<input type="password" id="ctp-api-key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />'
            . ' <button type="button" class="button" id="ctp-test-connection">%3$s</button>'
            . ' <span id="ctp-test-connection-result"></span>',
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
            <p class="description"><?php esc_html_e('Noch keine Kalender geladen.', 'churchtools-plugin'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:760px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e('Aktiv', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Farbe', 'churchtools-plugin'); ?></th>
                        <th style="width:240px;"><?php esc_html_e('Standardbild', 'churchtools-plugin'); ?></th>
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
                <br /><code style="opacity:.6;">ID: <?php echo (int) $id; ?></code>
            </td>
            <td>
                <input type="color" name="<?php echo esc_attr($fieldBase); ?>[color]" value="<?php echo esc_attr($calendar['color']); ?>" />
            </td>
            <td class="ctp-image-field">
                <input type="hidden" class="ctp-image-id" name="<?php echo esc_attr($fieldBase); ?>[default_image_id]" value="<?php echo esc_attr((string) $imageId); ?>" />
                <img class="ctp-image-preview" src="<?php echo esc_url($imageUrl); ?>" style="max-width:60px;max-height:40px;vertical-align:middle;<?php echo $imageUrl ? '' : 'display:none;'; ?>" alt="" />
                <button type="button" class="button ctp-image-select"><?php esc_html_e('Bild wählen', 'churchtools-plugin'); ?></button>
                <button type="button" class="button-link ctp-image-remove" style="<?php echo $imageUrl ? '' : 'display:none;'; ?>">
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
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'meta' => __('Datum & Ort', 'churchtools-plugin'),
        ];
    }

    public function renderElementOrderField(): void
    {
        $labels = self::elementOrderLabels();
        $order = self::get()['element_order'];
        $rowStyle = 'display:flex;align-items:center;gap:0.5em;padding:0.6em 0.8em;margin-bottom:4px;'
            . 'border:1px solid #dcdcde;border-radius:4px;background:#fff;cursor:move;';
        ?>
        <ul id="ctp-design-order" style="max-width:320px;margin:0;padding:0;list-style:none;">
            <?php foreach ($order as $key) : ?>
                <li draggable="true" data-key="<?php echo esc_attr($key); ?>" style="<?php echo esc_attr($rowStyle); ?>">
                    <span class="dashicons dashicons-menu" aria-hidden="true" style="opacity:.5;"></span>
                    <?php echo esc_html($labels[$key] ?? $key); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <input
            type="hidden"
            id="ctp-design-order-input"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[element_order]"
            value="<?php echo esc_attr(implode(',', $order)); ?>"
        />
        <p class="description">
            <?php esc_html_e('Reihenfolge per Drag&Drop ändern (Maus/Trackpad – Touch-Sortierung wird derzeit nicht unterstützt). Die Bild-Position bestimmt nur, ob das Bild über oder unter dem Textblock erscheint, nicht zwischen einzelnen Textzeilen.', 'churchtools-plugin'); ?>
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
                '<label style="margin-right:1.5em;"><input type="radio" name="%1$s[corner_style]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_KEY),
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label)
            );
        }
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
        <div class="card" style="max-width:760px;margin:16px 0;">
            <h2 style="margin-top:0;"><?php esc_html_e('Vorschau', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Vorschau als Grid-Kachel – die Einstellung gilt gleichermaßen für Grid, Liste und „Nächster Termin".', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-events ctp-events--grid" id="ctp-design-preview" style="max-width:280px;<?php echo esc_attr($style); ?>">
                <ul class="ctp-events__list" style="display:block;">
                    <li>
                        <article class="ctp-events__card" style="--ctp-accent:#2563eb;">
                            <div class="ctp-events__media">
                                <span class="ctp-events__date-badge" aria-hidden="true">
                                    <span class="ctp-events__day">24</span>
                                    <span class="ctp-events__month"><?php esc_html_e('Dez', 'churchtools-plugin'); ?></span>
                                </span>
                            </div>
                            <div class="ctp-events__content">
                                <span class="ctp-events__title">
                                    <span class="ctp-events__color-dot" aria-hidden="true"></span>
                                    <?php esc_html_e('Beispiel-Termin', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__subtitle"><?php esc_html_e('Untertitel-Beispiel', 'churchtools-plugin'); ?></span>
                                <span class="ctp-events__meta">
                                    <span>24.12.2026, 18:00</span>
                                    <span><?php esc_html_e('Gemeindehaus', 'churchtools-plugin'); ?></span>
                                </span>
                            </div>
                        </article>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    private function renderSyncStatus(): void
    {
        $lastSync = get_option('ctp_last_sync', '');
        $lastError = SyncEngine::getLastError();
        $eventCount = (new EventRepository())->count();
        ?>
        <div class="card" style="max-width:760px;margin:16px 0;">
            <h2 style="margin-top:0;"><?php esc_html_e('Status', 'churchtools-plugin'); ?></h2>
            <?php if ($lastError !== null) : ?>
                <div class="notice notice-error inline" style="margin:0 0 12px;">
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
            <p><?php esc_html_e('Noch keine Termine synchronisiert.', 'churchtools-plugin'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
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
        <?php
    }

    private function renderEventOverviewRow(array $event, array $calendars): void
    {
        $calendarId = (int) $event['ct_calendar_id'];
        $calendarName = $calendars[$calendarId]['name'] ?? sprintf('#%d', $calendarId);
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $event['id'])); ?>">
                    <?php echo esc_html($event['title']); ?>
                </a>
                <?php if ($event['subtitle'] !== '') : ?>
                    <br /><span style="opacity:.6;"><?php echo esc_html($event['subtitle']); ?></span>
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
            <td><?php echo esc_html($calendarName); ?></td>
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
            printf('<p>%s</p>', esc_html__('Termin nicht gefunden.', 'churchtools-plugin'));
            printf(
                '<p><a href="%s">&larr; %s</a></p>',
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
        <p><a href="<?php echo esc_url($backUrl); ?>">&larr; <?php esc_html_e('Zurück zur Übersicht', 'churchtools-plugin'); ?></a></p>
        <div class="card" style="max-width:760px;">
            <h2 style="margin-top:0;">
                <?php echo esc_html($event['title']); ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <br />
                    <span style="font-weight:normal;opacity:.7;font-size:.7em;">
                        <?php echo esc_html($event['subtitle']); ?>
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($displayImageUrl !== '') : ?>
                <p>
                    <img
                        src="<?php echo esc_url($displayImageUrl); ?>"
                        alt=""
                        style="max-width:100%;height:auto;border-radius:4px;"
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
                                <span style="display:inline-block;width:.8em;height:.8em;border-radius:50%;
                                    background-color:<?php echo esc_attr($calendar['color']); ?>;vertical-align:middle;"></span>
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ChurchTools Events', 'churchtools-plugin'); ?></h1>
            <nav class="nav-tab-wrapper">
                <?php foreach (self::tabs() as $tabSlug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tabSlug], admin_url('admin.php'))); ?>"
                        class="nav-tab <?php echo $tab === $tabSlug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'sync') : ?>
                <?php $this->renderSyncStatus(); ?>
            <?php endif; ?>

            <?php if ($tab === 'design') : ?>
                <?php $this->renderDesignPreview(); ?>
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
            <?php else : ?>
                <form method="post" action="options.php">
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
                    preview.style.display = 'inline-block';
                    removeButton.style.display = 'inline';
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
                preview.style.display = 'none';
                preview.src = '';
                button.style.display = 'none';
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
     * were removed on the ChurchTools side.
     */
    private static function mergeCalendars(array $existing, array $remoteCalendars): array
    {
        $merged = [];

        foreach ($remoteCalendars as $calendar) {
            $id = (int) ($calendar['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $merged[$id] = [
                'name' => (string) ($calendar['name'] ?? ''),
                'enabled' => (bool) ($existing[$id]['enabled'] ?? false),
                'color' => (string) ($existing[$id]['color'] ?? ($calendar['color'] ?? '#3388ff')),
                'default_image_id' => (int) ($existing[$id]['default_image_id'] ?? 0),
            ];
        }

        return $merged;
    }
}
