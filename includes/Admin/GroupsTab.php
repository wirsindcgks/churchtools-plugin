<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use Throwable;

/**
 * Der Reiter „Gruppen": Homepage-Auswahl, eigenes Sync-Intervall, Status.
 *
 * Eine eigene Klasse statt weiterer Methoden in SettingsPage - die steht bei
 * gut 5.300 Zeilen, und die Gruppen teilen mit ihr nur den Rahmen (Reiterreihe,
 * Statuszeile, Speicherleiste). Dieselbe Trennung wie bei der Option (siehe
 * GroupSettings).
 */
final class GroupsTab
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('wp_ajax_ctp_fetch_group_homepages', [$this, 'ajaxFetchHomepages']);
        add_action('wp_ajax_ctp_run_group_sync', [$this, 'ajaxRunSync']);
    }

    public function registerSetting(): void
    {
        register_setting(GroupSettings::OPTION_GROUP, GroupSettings::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [GroupSettings::class, 'sanitize'],
            'default' => GroupSettings::defaults(),
        ]);
    }

    /**
     * Deutsche Beschriftungen fuer GroupSettings::INTERVALS.
     *
     * @return array<string, string>
     */
    public static function intervalLabels(): array
    {
        return [
            'hourly' => __('Stündlich', 'churchtools-plugin'),
            'twicedaily' => __('Zweimal täglich', 'churchtools-plugin'),
            'daily' => __('Täglich', 'churchtools-plugin'),
            'weekly' => __('Wöchentlich', 'churchtools-plugin'),
        ];
    }

    /**
     * Die Kacheln der Statuszeile ueber dem Reiter.
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function statusCards(): array
    {
        $settings = GroupSettings::get();
        $enabled = GroupSettings::enabledHomepages($settings);
        // Verschiedene Gruppen, nicht Eintraege: Dieselbe Gruppe kann auf zwei
        // Homepages stehen (an der Referenzinstanz zwei von 17).
        $groupIds = [];

        foreach (array_keys($enabled) as $id) {
            foreach (GroupSync::groupsFor((int) $id) as $group) {
                $groupIds[(int) $group['id']] = true;
            }
        }

        $lastSync = (string) get_option(GroupSync::LAST_SYNC_OPTION, '');
        $error = GroupSync::getLastError();

        return [
            [
                'icon' => 'groups',
                'value' => sprintf(
                    /* translators: 1: number of enabled group homepages, 2: total number of known group homepages */
                    __('%1$d von %2$d', 'churchtools-plugin'),
                    count($enabled),
                    count($settings['homepages'])
                ),
                'label' => __('Aktive Homepages', 'churchtools-plugin'),
            ],
            [
                'icon' => 'id-alt',
                'value' => (string) count($groupIds),
                'label' => __('Gespeicherte Gruppen', 'churchtools-plugin'),
            ],
            [
                'icon' => 'update',
                'value' => $lastSync !== ''
                    ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $lastSync)
                    : __('noch nie', 'churchtools-plugin'),
                'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                'tone' => $error !== null ? 'error' : ($lastSync !== '' ? 'ok' : ''),
            ],
            [
                'icon' => 'clock',
                'value' => self::intervalLabels()[$settings['sync_interval']] ?? $settings['sync_interval'],
                'label' => __('Sync-Intervall', 'churchtools-plugin'),
            ],
        ];
    }

    public static function render(): void
    {
        $settings = GroupSettings::get();
        $homepages = $settings['homepages'];
        $fetched = (string) get_option(GroupSync::HOMEPAGES_FETCHED_OPTION, '');
        $error = GroupSync::getLastError();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(GroupSettings::OPTION_GROUP); ?>
                <h2><?php esc_html_e('Gruppen-Homepages', 'churchtools-plugin'); ?></h2>

                <p class="description">
                    <?php esc_html_e('Zeigt die Gruppen einer Gruppen-Homepage aus ChurchTools in der Optik des Plugins – als Ersatz für den iframe. Welche Gruppen erscheinen, entscheidet die Homepage in ChurchTools.', 'churchtools-plugin'); ?>
                </p>

                <?php
                SettingsPage::renderActionBar(
                    'ctp-fetch-group-homepages',
                    __('Homepages von ChurchTools laden', 'churchtools-plugin'),
                    __('Braucht keinen API-Key: Geladen wird, was ChurchTools ohne Anmeldung zeigt.', 'churchtools-plugin')
                );
                ?>

                <?php if ($error !== null) : ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <?php
                            printf(
                                /* translators: 1: date/time the group sync last failed, 2: error message */
                                esc_html__('Die letzte Synchronisation der Gruppen ist fehlgeschlagen (%1$s): %2$s. Bereits geladene Gruppen bleiben stehen.', 'churchtools-plugin'),
                                esc_html(mysql2date($dateFormat, $error['time'])),
                                esc_html(wp_html_excerpt($error['message'], 400, '…'))
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($homepages === []) : ?>
                    <p class="ctp-empty-state"><?php esc_html_e('Noch keine Gruppen-Homepages geladen.', 'churchtools-plugin'); ?></p>
                <?php else : ?>
                    <?php if ($fetched !== '') : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: date and time the homepage list was last fetched */
                                esc_html__('Zuletzt geladen: %s', 'churchtools-plugin'),
                                esc_html(mysql2date($dateFormat, $fetched))
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <table class="widefat striped ctp-rooms-table ctp-group-homepages">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Übernehmen', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Homepage', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Gruppen', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Shortcode', 'churchtools-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($homepages as $id => $homepage) : ?>
                                <?php
                                $fieldName = sprintf('%s[homepages][%d][enabled]', GroupSettings::OPTION_KEY, (int) $id);
                                // Der Name liest sich besser als die ID; wo er den Shortcode
                                // zerbraeche (Anfuehrungszeichen, eckige Klammer), steht die ID.
                                $usableName = $homepage['name'] !== '' && strpbrk($homepage['name'], '"[]') === false;
                                $shortcode = sprintf('[ctp_groups homepage="%s"]', $usableName ? $homepage['name'] : (string) $id);
                                $isEnabled = !empty($homepage['enabled']);
                                ?>
                                <tr>
                                    <td>
                                        <?php // Verstecktes Feld vor dem Kaestchen, damit das Abwaehlen der letzten Homepage speicherbar ist (siehe Reiter „Räume"). ?>
                                        <input type="hidden" name="<?php echo esc_attr($fieldName); ?>" value="0">
                                        <input
                                            type="checkbox"
                                            id="ctp-group-homepage-<?php echo esc_attr((string) $id); ?>"
                                            name="<?php echo esc_attr($fieldName); ?>"
                                            value="1"
                                            <?php checked($isEnabled); ?>
                                        >
                                    </td>
                                    <td>
                                        <label for="ctp-group-homepage-<?php echo esc_attr((string) $id); ?>">
                                            <?php echo esc_html($homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $id)); ?>
                                        </label>
                                    </td>
                                    <td>
                                        <?php echo $isEnabled ? esc_html((string) count(GroupSync::groupsFor((int) $id))) : '—'; ?>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($shortcode); ?></code>
                                        <button type="button" class="button button-small ctp-copy-shortcode" data-shortcode="<?php echo esc_attr($shortcode); ?>">
                                            <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description">
                        <?php esc_html_e('Nur angehakte Homepages werden synchronisiert. Bilder landen dabei in der Mediathek. Beim Abwählen entfernt der nächste Lauf die Gruppen samt Bildern.', 'churchtools-plugin'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="ctp-panel">
                <h2><?php esc_html_e('Synchronisation der Gruppen', 'churchtools-plugin'); ?></h2>

                <?php
                SettingsPage::renderActionBar(
                    'ctp-run-group-sync',
                    __('Gruppen jetzt synchronisieren', 'churchtools-plugin'),
                    __('Unabhängig vom Termin-Sync, mit eigenem Intervall.', 'churchtools-plugin')
                );
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ctp-group-sync-interval"><?php esc_html_e('Sync-Intervall', 'churchtools-plugin'); ?></label>
                        </th>
                        <td>
                            <select id="ctp-group-sync-interval" name="<?php echo esc_attr(GroupSettings::OPTION_KEY); ?>[sync_interval]">
                                <?php foreach (self::intervalLabels() as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['sync_interval'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Die freien Plätze auf den Kacheln sind so alt wie der letzte Lauf. Die Anmeldung in ChurchTools zeigt immer den echten Stand.', 'churchtools-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Das Panel „Gruppen" auf der Uebersicht - die Uebersicht zeigt seit der
     * Teilung in Bereiche den Zustand von beidem, und ein dauerhaft
     * scheiternder Gruppen-Sync fiel bis dahin nur im Reiter der Gruppen auf.
     *
     * Ohne angehakte Homepage bleibt es bei einem Satz und einem Verweis:
     * Die meisten Installationen zeigen keine Gruppen, und vier Zahlen, die
     * alle „nichts" sagen, waeren auf ihrer Uebersicht nur Rauschen.
     */
    public static function renderOverviewPanel(): void
    {
        $settings = GroupSettings::get();
        $enabled = GroupSettings::enabledHomepages($settings);
        $error = GroupSync::getLastError();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Gruppen', 'churchtools-plugin'); ?></h2>
            <?php if ($enabled === []) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the "Homepages" tab */
                        esc_html__('Es werden keine Gruppen übernommen. Unter %s lässt sich eine Gruppen-Homepage aus ChurchTools auswählen.', 'churchtools-plugin'),
                        '<a href="' . esc_url(SettingsPage::tabUrl('groups')) . '">' . esc_html__('Gruppen → Homepages', 'churchtools-plugin') . '</a>'
                    );
                    ?>
                </p>
            <?php else : ?>
                <?php
                SettingsPage::renderActionBar(
                    'ctp-run-group-sync',
                    __('Gruppen jetzt synchronisieren', 'churchtools-plugin'),
                    __('Unabhängig vom Termin-Sync, mit eigenem Intervall.', 'churchtools-plugin')
                );
                ?>
                <?php if ($error !== null) : ?>
                    <div class="notice notice-error inline">
                        <p>
                            <?php
                            printf(
                                /* translators: 1: date/time the group sync last failed, 2: error message */
                                esc_html__('Letzter Fehler beim Gruppen-Sync (%1$s): %2$s', 'churchtools-plugin'),
                                esc_html(mysql2date($dateFormat, $error['time'])),
                                esc_html(wp_html_excerpt($error['message'], 600, '…'))
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php $next = wp_next_scheduled(GroupSync::HOOK); ?>
                <table class="widefat striped ctp-borderless ctp-keyvalue-table">
                    <tbody>
                        <?php foreach (self::statusCards() as $card) : ?>
                            <tr>
                                <th><?php echo esc_html($card['label']); ?></th>
                                <td><?php echo esc_html($card['value']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th><?php esc_html_e('Nächste Synchronisation', 'churchtools-plugin'); ?></th>
                            <td>
                                <?php
                                echo esc_html($next !== false
                                    ? (string) wp_date($dateFormat, $next)
                                    : __('nicht geplant', 'churchtools-plugin'));
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="ctp-quicklinks">
                    <a href="<?php echo esc_url(SettingsPage::tabUrl('groups')); ?>">
                        <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                        <?php esc_html_e('Homepages auswählen', 'churchtools-plugin'); ?>
                    </a>
                    <a href="<?php echo esc_url(SettingsPage::tabUrl('group_embed')); ?>">
                        <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                        <?php esc_html_e('Gruppen einbinden', 'churchtools-plugin'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Der Reiter „Einbinden" der Gruppen - aufgebaut wie der der Events:
     * erst die Wege, dann fertige Shortcodes, dann alle Attribute. Bis zur
     * Teilung in Bereiche stand die Gruppen-Referenz als letztes Panel im
     * Einbinden-Reiter der Termine, wo sie niemand suchte, der gerade Gruppen
     * einrichtet.
     *
     * Die Beispiele nennen die angehakten Homepages beim Namen, damit sie
     * ohne Anpassen funktionieren; ohne angehakte Homepage steht statt ihrer
     * der Weg dorthin - ein Beispiel mit erfundenem Namen erzeugte beim
     * Einfuegen nur eine leere Liste.
     */
    public static function renderEmbed(): void
    {
        $enabled = GroupSettings::enabledHomepages();
        $examples = [];
        $firstRef = null;

        foreach ($enabled as $id => $homepage) {
            $name = $homepage['name'] !== '' && strpbrk($homepage['name'], '"[]') === false
                ? $homepage['name']
                : (string) $id;

            $firstRef ??= $name;
            $examples[] = [
                /* translators: %s: name of a group homepage */
                'label' => sprintf(__('Gruppen der Homepage „%s“', 'churchtools-plugin'), $homepage['name'] !== '' ? $homepage['name'] : $name),
                'code' => sprintf('[ctp_groups homepage="%s"]', $name),
            ];
        }

        if ($firstRef !== null) {
            $examples[] = [
                'label' => __('Zwei Spalten, etwa neben einem Text', 'churchtools-plugin'),
                'code' => sprintf('[ctp_groups homepage="%s" columns="2"]', $firstRef),
            ];
        }
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Drei Wege, dieselbe Darstellung', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Gruppen lassen sich per Shortcode, über den Gutenberg-Block „ChurchTools Gruppen“ oder über das WPBakery-Element „ChurchTools Gruppen“ einbinden. Alle drei zeigen dieselben Kacheln; Vorlage, Farben und Ecken kommen aus „Einstellungen → Design“.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Block und WPBakery-Element bieten die angehakten Homepages als Auswahl an. Ein Klick auf eine Kachel führt zur Gruppe in ChurchTools, wo man sich anmeldet.', 'churchtools-plugin'); ?>
            </p>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Beispiele zum Kopieren', 'churchtools-plugin'); ?></h2>
            <?php if ($examples === []) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to the "Homepages" tab */
                            esc_html__('Noch keine Gruppen-Homepage angehakt. Unter %s laden und anhaken, dann stehen hier fertige Shortcodes.', 'churchtools-plugin'),
                            '<a href="' . esc_url(SettingsPage::tabUrl('groups')) . '">' . esc_html__('Homepages', 'churchtools-plugin') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e('Fertige Shortcodes mit den angehakten Homepages dieser Instanz.', 'churchtools-plugin'); ?>
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
            <?php endif; ?>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Alle Attribute', 'churchtools-plugin'); ?></h2>
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
                        <td><code>homepage</code></td>
                        <td><?php esc_html_e('Name oder ID der Gruppen-Homepage. Leer = die einzige angehakte Homepage.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Spaltenzahl auf breiten Bildschirmen (2–6)', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajaxFetchHomepages(): void
    {
        check_ajax_referer('ctp_fetch_group_homepages', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $baseUrl = SettingsPage::getBaseUrl();

        if ($baseUrl === '') {
            wp_send_json_error(['message' => __('Bitte zuerst unter „Einstellungen → Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin')]);
        }

        try {
            $result = GroupSync::refreshHomepageList(new Client($baseUrl, ''));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);

            return;
        }

        if ($result['status'] === 'empty') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success(['count' => $result['count']]);
    }

    public function ajaxRunSync(): void
    {
        check_ajax_referer('ctp_run_group_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        try {
            GroupSync::runNow();
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);

            return;
        }

        wp_send_json_success();
    }
}
