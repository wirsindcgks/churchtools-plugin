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

    public function ajaxFetchHomepages(): void
    {
        check_ajax_referer('ctp_fetch_group_homepages', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $baseUrl = SettingsPage::getBaseUrl();

        if ($baseUrl === '') {
            wp_send_json_error(['message' => __('Bitte zuerst im Reiter „Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin')]);
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
