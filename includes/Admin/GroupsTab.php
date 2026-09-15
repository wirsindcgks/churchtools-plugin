<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Settings;
use Throwable;

/**
 * Der Bereich „Gruppen" im Backend: Gruppenliste, Homepages,
 * Synchronisation, Einbinden - dazu das Gruppen-Panel der Uebersicht.
 *
 * Aufgebaut wie der Bereich „Events" (Nutzerwunsch 2026-09-15: „Das Plugin
 * soll sich egal ob Events oder Gruppen gleich oder mindestens aehnlich
 * verhalten"): dieselben Reiter in derselben Reihenfolge, dieselben Kacheln in
 * der Statuszeile, dieselben Knoepfe und Hinweise. Wer hier etwas aendert,
 * sieht beim Gegenstueck in SettingsPage nach.
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
     * Was die Statuszeilen und das Uebersichts-Panel gemeinsam brauchen, einmal
     * je Seitenaufbau - das Gegenstueck zu SettingsPage::statusFacts().
     *
     * @return array{settings: array, enabled: array, group_count: int, image_count: int, groups_from_enabled: int, last_sync: string, last_sync_label: string, next_sync_label: string, next_sync_scheduled: bool, failed: bool, date_format: string}
     */
    private static function facts(): array
    {
        $settings = GroupSettings::get();
        $enabled = GroupSettings::enabledHomepages($settings);
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        // Verschiedene Gruppen, nicht Eintraege: Dieselbe Gruppe kann auf zwei
        // Homepages stehen (an der Referenzinstanz zwei von 17).
        $groupIds = [];
        $entries = 0;

        foreach (array_keys($enabled) as $id) {
            foreach (GroupSync::groupsFor((int) $id) as $group) {
                $groupIds[(int) $group['id']] = true;
                $entries++;
            }
        }

        $lastSync = (string) get_option(GroupSync::LAST_SYNC_OPTION, '');
        $next = wp_next_scheduled(GroupSync::HOOK);

        return [
            'settings' => $settings,
            'enabled' => $enabled,
            'group_count' => count($groupIds),
            'image_count' => count(array_intersect_key(GroupSync::imageMap(), $groupIds)),
            'groups_from_enabled' => $entries,
            'last_sync' => $lastSync,
            'last_sync_label' => $lastSync !== ''
                ? (string) mysql2date($dateFormat, $lastSync)
                : __('noch nie', 'churchtools-plugin'),
            'next_sync_label' => $next !== false
                ? (string) wp_date($dateFormat, $next)
                : __('nicht geplant', 'churchtools-plugin'),
            'next_sync_scheduled' => $next !== false,
            'failed' => GroupSync::getLastError() !== null,
            'date_format' => $dateFormat,
        ];
    }

    /** Verschiedene gespeicherte Gruppen der aktiven Homepages - fuer die Kachel der Uebersicht. */
    public static function storedGroupCount(): int
    {
        return self::facts()['group_count'];
    }

    /**
     * Statuszeile der Gruppenliste - wie die der Terminliste: was gespeichert
     * ist, nicht wie es dorthin kam.
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function listCards(): array
    {
        $facts = self::facts();

        return [
            [
                'icon' => 'database',
                'value' => (string) $facts['group_count'],
                'label' => __('Gesamt', 'churchtools-plugin'),
            ],
            [
                'icon' => 'groups',
                'value' => (string) count($facts['enabled']),
                'label' => __('Aktive Homepages', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => (string) $facts['image_count'],
                'label' => __('Mit importiertem Bild', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Statuszeile des Reiters „Homepages" - wie die des Reiters „Kalender".
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function selectionCards(): array
    {
        $facts = self::facts();
        $known = count($facts['settings']['homepages']);
        $fetched = (string) get_option(GroupSync::HOMEPAGES_FETCHED_OPTION, '');

        return [
            [
                'icon' => 'groups',
                'value' => (string) $known,
                'label' => __('Bekannte Homepages', 'churchtools-plugin'),
            ],
            [
                'icon' => 'yes-alt',
                'value' => sprintf(
                    /* translators: 1: number of enabled group homepages, 2: total number of known group homepages */
                    __('%1$d von %2$d', 'churchtools-plugin'),
                    count($facts['enabled']),
                    $known
                ),
                'label' => __('Zur Synchronisation aktiviert', 'churchtools-plugin'),
                // Anders als bei den Kalendern nicht gelb ohne Auswahl: Die
                // meisten Installationen zeigen keine Gruppen, und das ist
                // kein Fehler.
                'tone' => $facts['enabled'] !== [] ? 'ok' : '',
            ],
            [
                'icon' => 'list-view',
                'value' => (string) $facts['groups_from_enabled'],
                'label' => __('Gruppen aus aktiven Homepages', 'churchtools-plugin'),
            ],
            [
                'icon' => 'download',
                'value' => $fetched !== ''
                    ? (string) mysql2date($facts['date_format'], $fetched)
                    : __('noch nie', 'churchtools-plugin'),
                'label' => __('Homepage-Liste zuletzt geladen', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Statuszeile des Reiters „Synchronisation" - wie die der Termine, ohne
     * Zeitraum und Aufbewahrung, die es nur dort gibt.
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function syncCards(): array
    {
        $facts = self::facts();

        return [
            [
                'icon' => 'update',
                'value' => $facts['last_sync_label'],
                'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                'tone' => SettingsPage::lastSyncTone($facts['failed'], $facts['last_sync']),
            ],
            [
                'icon' => 'clock',
                'value' => $facts['next_sync_label'],
                'label' => sprintf(
                    /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                    __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                    SettingsPage::syncIntervalLabels()[$facts['settings']['sync_interval']] ?? $facts['settings']['sync_interval']
                ),
                // Ohne aktive Homepage gibt es bewusst keinen Zeitplan (siehe
                // Installer::ensureSchedules()) - das ist dann nicht rot.
                'tone' => $facts['next_sync_scheduled'] || $facts['enabled'] === [] ? '' : 'error',
            ],
            [
                'icon' => 'list-view',
                'value' => (string) $facts['group_count'],
                'label' => __('Gespeicherte Gruppen', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Der Reiter „Homepages" - aufgebaut wie der Reiter „Kalender": Knopf zum
     * Laden, Auswahl, Hinweis, was mit einer abgewaehlten passiert. Die
     * Synchronisation hat seit 2026-09-15 einen eigenen Reiter.
     */
    public static function render(): void
    {
        $settings = GroupSettings::get();
        $homepages = $settings['homepages'];
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(GroupSettings::OPTION_GROUP); ?>
                <h2><?php esc_html_e('Homepage-Auswahl', 'churchtools-plugin'); ?></h2>

                <p class="description">
                    <?php esc_html_e('Zeigt die Gruppen einer Gruppen-Homepage aus ChurchTools in der Optik des Plugins – als Ersatz für den iframe. Welche Gruppen erscheinen, entscheidet die Homepage in ChurchTools. Übernommen werden nur Name, Beschreibung, Treffzeit, Zielgruppe, Plätze und Bild – keine Leiter und keine Angaben über Personen.', 'churchtools-plugin'); ?>
                </p>

                <?php
                SettingsPage::renderActionBar(
                    'ctp-fetch-group-homepages',
                    __('Homepages von ChurchTools laden', 'churchtools-plugin'),
                    __('Jede Synchronisation gleicht die Liste automatisch mit ab – dieser Button holt sie sofort.', 'churchtools-plugin')
                );
                ?>

                <?php if ($homepages === []) : ?>
                    <p class="ctp-empty-state"><?php esc_html_e('Noch keine Homepages geladen.', 'churchtools-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped ctp-rooms-table ctp-group-homepages">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Aktiv', 'churchtools-plugin'); ?></th>
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
                        <?php esc_html_e('Nur aktive Homepages werden synchronisiert. Gruppen einer gerade deaktivierten Homepage entfernt der nächste Sync samt Bildern.', 'churchtools-plugin'); ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Der Reiter „Synchronisation" der Gruppen - aufgebaut wie der der
     * Termine: Knopf und Befund oben, darunter die Einstellungen. Bis
     * 2026-09-15 stand das als zweites Panel unter der Homepage-Tabelle, erst
     * nach dem Scrollen sichtbar.
     *
     * Eigenes Formular derselben Option wie „Homepages": GroupSettings::sanitize()
     * uebernimmt, was nicht im Formular steht, aus dem Bestand.
     */
    public static function renderSync(): void
    {
        $settings = GroupSettings::get();
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(GroupSettings::OPTION_GROUP); ?>
                <h2><?php esc_html_e('Sync-Einstellungen', 'churchtools-plugin'); ?></h2>

                <?php
                SettingsPage::renderSyncHead(
                    'ctp-run-group-sync',
                    SyncHealthNotice::groupProblem(),
                    GroupSync::getImageWarning(),
                    'groups'
                );
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Sync-Intervall', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php
                            SettingsPage::renderIntervalSelect(
                                GroupSettings::OPTION_KEY . '[sync_interval]',
                                $settings['sync_interval'],
                                __('Die freien Plätze auf den Kacheln sind so alt wie der letzte Lauf. Die Anmeldung in ChurchTools zeigt immer den echten Stand.', 'churchtools-plugin')
                            );
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Der Reiter „Gruppenliste": was gerade gespeichert ist und auf der
     * Website erscheinen kann - das Gegenstueck zur Terminliste, und wie dort
     * der erste Reiter des Bereichs (Nutzerwunsch 2026-09-14: „die primaeren
     * Infos in die erste Position").
     *
     * Ein Panel je angehakter Homepage statt einer gemeinsamen Tabelle: Ein
     * Shortcode zeigt immer genau eine Homepage, und so steht hier dieselbe
     * Liste in derselben Reihenfolge wie dort. Eine Gruppe auf zwei Homepages
     * steht deshalb zweimal - so, wie sie auf der Website auch zweimal stehen
     * kann.
     *
     * Nur Anzeige, keine Bedienung: Geaendert wird eine Gruppe in ChurchTools,
     * der Name fuehrt deshalb dorthin.
     */
    public static function renderList(): void
    {
        $enabled = GroupSettings::enabledHomepages();
        $stored = GroupSync::storedData();
        $imageMap = GroupSync::imageMap();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        ?>
        <?php if ($enabled === []) : ?>
            <div class="ctp-panel">
                <h2><?php esc_html_e('Gespeicherte Gruppen', 'churchtools-plugin'); ?></h2>
                <p class="ctp-empty-state">
                    <?php
                    printf(
                        /* translators: %s: link to the "Homepages" tab */
                        esc_html__('Es werden keine Gruppen übernommen. Unter %s lässt sich eine Gruppen-Homepage aus ChurchTools auswählen.', 'churchtools-plugin'),
                        '<a href="' . esc_url(SettingsPage::tabUrl('groups')) . '">' . esc_html__('Gruppen → Homepages', 'churchtools-plugin') . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <?php foreach ($enabled as $id => $homepage) : ?>
            <?php
            $entry = $stored[(int) $id] ?? null;
            $groups = GroupListRenderer::prepareGroups(GroupSync::groupsFor((int) $id), $imageMap);
            ?>
            <div class="ctp-panel">
                <h2><?php echo esc_html($homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $id)); ?></h2>
                <p class="description">
                    <?php if ($entry === null || (string) ($entry['fetched'] ?? '') === '') : ?>
                        <?php esc_html_e('Noch nicht synchronisiert.', 'churchtools-plugin'); ?>
                    <?php else : ?>
                        <?php
                        printf(
                            // „Gruppen: 1" statt „1 Gruppen" - bin/make-pot.php kennt keine Plurale.
                            /* translators: 1: number of groups, 2: date and time of the last successful fetch */
                            esc_html__('Gruppen: %1$d, Stand %2$s.', 'churchtools-plugin'),
                            count($groups),
                            esc_html(mysql2date($dateFormat, (string) $entry['fetched']))
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ((int) ($entry['empty_runs'] ?? 0) > 0) : ?>
                        <?php
                        // Der Leer-Antwort-Schutz haelt gerade den Bestand -
                        // ohne diesen Satz saehe die Liste aktuell aus.
                        esc_html_e('ChurchTools liefert für diese Homepage zurzeit keine Gruppen; die zuletzt geladenen bleiben vorerst stehen.', 'churchtools-plugin');
                        ?>
                    <?php endif; ?>
                </p>

                <?php if ($groups === []) : ?>
                    <p class="ctp-empty-state"><?php esc_html_e('Auf dieser Homepage stehen keine Gruppen.', 'churchtools-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped ctp-borderless ctp-events-table ctp-group-list">
                        <thead>
                            <tr>
                                <th scope="col" class="ctp-group-list__id"><?php esc_html_e('ID', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Gruppe', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Treffen', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Plätze', 'churchtools-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group) : ?>
                                <tr>
                                    <?php // Die ID ist das, was `groups` im Shortcode erwartet - hier liest man sie ab. ?>
                                    <td class="ctp-group-list__id"><code><?php echo (int) $group['id']; ?></code></td>
                                    <td>
                                        <a href="<?php echo esc_url($group['url']); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html($group['name']); ?>
                                        </a>
                                        <?php if ($group['image_src'] !== '') : ?>
                                            <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                                        <?php endif; ?>
                                        <?php if ($group['excerpt'] !== '') : ?>
                                            <br /><span class="ctp-muted-text"><?php echo esc_html($group['excerpt']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $group['schedule'] !== '' ? esc_html($group['schedule']) : '&ndash;'; ?></td>
                                    <td><?php echo $group['places_label'] !== '' ? esc_html($group['places_label']) : '&ndash;'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Das Panel „Gruppen" auf der Uebersicht - aufgebaut wie das Panel
     * „Events" darueber: Knopf, Befund, dieselbe Tabelle, dieselben Links.
     *
     * Ohne aktive Homepage bleibt es bei einem Satz und einem Verweis:
     * Die meisten Installationen zeigen keine Gruppen, und vier Zahlen, die
     * alle „nichts" sagen, waeren auf ihrer Uebersicht nur Rauschen.
     */
    public static function renderOverviewPanel(): void
    {
        $facts = self::facts();
        $problem = SyncHealthNotice::groupProblem();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Gruppen', 'churchtools-plugin'); ?></h2>
            <?php if ($facts['enabled'] === []) : ?>
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
                    __('Jetzt synchronisieren', 'churchtools-plugin'),
                    __('Holt die Gruppen aller aktiven Homepages sofort, unabhängig vom Intervall.', 'churchtools-plugin')
                );
                ?>
                <?php if ($problem !== null) : ?>
                    <div class="notice notice-<?php echo esc_attr($problem['type']); ?> inline">
                        <p><?php echo esc_html($problem['message']); ?></p>
                    </div>
                <?php endif; ?>
                <?php SettingsPage::renderImageWarning(GroupSync::getImageWarning(), 'groups'); ?>
                <?php
                SettingsPage::renderOverviewRows([
                    [
                        'label' => __('Aktive Homepages', 'churchtools-plugin'),
                        'value' => sprintf(
                            /* translators: 1: number of enabled group homepages, 2: total number of known group homepages */
                            __('%1$d von %2$d', 'churchtools-plugin'),
                            count($facts['enabled']),
                            count($facts['settings']['homepages'])
                        ),
                    ],
                    [
                        'label' => __('Gespeicherte Gruppen', 'churchtools-plugin'),
                        'value' => (string) $facts['group_count'],
                    ],
                    [
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                        'value' => $facts['last_sync_label'],
                    ],
                    [
                        'label' => sprintf(
                            /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                            __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                            SettingsPage::syncIntervalLabels()[$facts['settings']['sync_interval']] ?? $facts['settings']['sync_interval']
                        ),
                        'value' => $facts['next_sync_label'],
                    ],
                ]);

                SettingsPage::renderQuicklinks([
                    ['url' => SettingsPage::tabUrl('group_list'), 'icon' => 'list-view', 'label' => __('Gespeicherte Gruppen ansehen', 'churchtools-plugin')],
                    ['url' => SettingsPage::tabUrl('groups'), 'icon' => 'groups', 'label' => __('Homepages auswählen', 'churchtools-plugin')],
                    ['url' => SettingsPage::tabUrl('group_sync'), 'icon' => 'update', 'label' => __('Sync-Einstellungen', 'churchtools-plugin')],
                    ['url' => SettingsPage::tabUrl('group_embed'), 'icon' => 'editor-code', 'label' => __('Gruppen einbinden', 'churchtools-plugin')],
                ]);
                ?>
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
     * Die Beispiele nennen die aktiven Homepages beim Namen, damit sie
     * ohne Anpassen funktionieren; ohne aktive Homepage steht statt ihrer
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
            $examples[] = [
                'label' => __('Raster mit Gruppenfinder und Suche', 'churchtools-plugin'),
                'code' => sprintf('[ctp_groups homepage="%s" finder="1" search="1"]', $firstRef),
            ];
        }

        // Mit echten IDs dieser Instanz, damit das Beispiel ohne Anpassen etwas
        // zeigt - welche Gruppe welche ID hat, steht in der Gruppenliste.
        $someIds = array_slice(array_keys(GroupSync::selectableGroups()), 0, 2);

        if ($someIds !== []) {
            $examples[] = [
                'label' => __('Einzelne Gruppen hervorheben', 'churchtools-plugin'),
                'code' => sprintf('[ctp_groups groups="%s" layout="featured"]', implode(',', $someIds)),
            ];
        }
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Drei Wege, dieselbe Darstellung', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Gruppen lassen sich per Shortcode, über den Gutenberg-Block „ChurchTools Gruppen“ oder über das WPBakery-Element „ChurchTools Gruppen“ einbinden. Alle drei zeigen dieselben Kacheln; Vorlage, Farben und Ecken kommen aus „Einstellungen → Design“.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Block und WPBakery-Element bieten die aktiven Homepages und ihre Gruppen als Auswahl an. Unter jeder Gruppe steht der Button „In ChurchTools ansehen“; er führt zur Gruppe in ChurchTools, wo man sich anmeldet. Die Kachel selbst ist nicht klickbar.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Einzeln wählbar sind die Gruppen der aktiven Homepages – eine Gruppe, die auf keiner steht, entscheidet ChurchTools nicht als öffentlich und erscheint deshalb auch hier nicht.', 'churchtools-plugin'); ?>
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
                            esc_html__('Noch keine Gruppen-Homepage aktiv. Unter %s laden und aktivieren, dann stehen hier fertige Shortcodes.', 'churchtools-plugin'),
                            '<a href="' . esc_url(SettingsPage::tabUrl('groups')) . '">' . esc_html__('Gruppen → Homepages', 'churchtools-plugin') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e('Fertige Shortcodes mit den aktiven Homepages dieser Instanz.', 'churchtools-plugin'); ?>
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
                        <td><?php esc_html_e('Name oder ID der Gruppen-Homepage. Leer = die einzige aktive Homepage.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>groups</code></td>
                        <td><?php esc_html_e('Einzelne Gruppen nach ID, kommagetrennt, in dieser Reihenfolge (IDs stehen unter „Gruppen → Gruppenliste“). Gilt statt homepage. Nur Gruppen der aktiven Homepages.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>source</code></td>
                        <td><?php esc_html_e('homepage oder groups: welche der beiden Angaben gilt. Leer = groups, sobald Gruppen angegeben sind, sonst homepage. Das WPBakery-Element setzt es selbst.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>layout</code></td>
                        <td><?php esc_html_e('grid: Kachelraster mit Auszug. featured: je Gruppe eine große Kachel mit dem ganzen Text, Bild daneben.', 'churchtools-plugin'); ?></td>
                        <td><code>grid</code></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Höchstens so viele Spalten (2–6), wie in den Inhaltsbereich passen – je Kachel mindestens 240px. Nur bei grid.', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
                    </tr>
                    <tr>
                        <td><code>search</code></td>
                        <td><?php esc_html_e('Freitext-Suchleiste anzeigen (nur grid, durchsucht Name, Kategorie, Wochentag, Zielgruppe und Beschreibung)', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>finder</code></td>
                        <td><?php esc_html_e('Gruppenfinder: Knöpfe für Kategorie, Wochentag und Zielgruppe (nur grid). Mit search steht das Suchfeld im Gruppenfinder.', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
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

        $baseUrl = Settings::getBaseUrl();

        if ($baseUrl === '') {
            wp_send_json_error(['message' => __('Bitte zuerst unter „Einstellungen → Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin')]);
        }

        if (!ApiKey::isUsable()) {
            wp_send_json_error(['message' => ApiKey::unusableMessage()]);
        }

        try {
            $result = GroupSync::refreshHomepageList(new Client($baseUrl, ApiKey::current()));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
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
        }

        wp_send_json_success();
    }
}
