<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Db;

use ChurchToolsPlugin\Admin\SettingsPage;

final class Installer
{
    public const DB_VERSION = '1.5.0';

    /**
     * The three recurrences the "Sync-Intervall" select offers — kept here
     * rather than in SettingsPage because this class is what actually hands
     * them to wp_schedule_event(); the select's own whitelist in
     * sanitizeSettings() validates against this same list.
     */
    public const SYNC_INTERVALS = ['hourly', 'twicedaily', 'daily'];

    public static function registerHooks(): void
    {
        // The Sync tab's interval select only writes an option — WP-Cron keeps
        // running on whatever recurrence the event was originally scheduled
        // with until something actually reschedules it. Without this the
        // select silently did nothing at all (every install stayed on the
        // "hourly" activate() picked), which is exactly the kind of setting
        // that looks like it works.
        add_action('update_option_ctp_settings', [self::class, 'onSettingsUpdated'], 10, 2);

        // Self-heal on any admin page load: a cron event can go missing
        // entirely (a plugin that flushes the cron array, a partially restored
        // DB backup, a migration between hosts), and a sync that silently
        // never runs again is the worst failure mode this plugin has.
        // Admin-only so a frontend request never pays for it.
        add_action('admin_init', [self::class, 'ensureSchedules']);
    }

    public static function activate(): void
    {
        self::createTables();
        update_option('ctp_db_version', self::DB_VERSION);
        self::ensureSchedules();
    }

    /**
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public static function onSettingsUpdated($oldValue, $newValue): void
    {
        $previous = is_array($oldValue) ? ($oldValue['sync_interval'] ?? null) : null;
        $current = is_array($newValue) ? ($newValue['sync_interval'] ?? null) : null;

        if ($previous !== $current) {
            self::ensureSchedules();
        }

        if (self::roomSettingsChanged($oldValue, $newValue)) {
            self::scheduleImmediateSync();
        }
    }

    /**
     * Die Raumangabe entsteht beim Sync und steht danach als Wert in der
     * Termintabelle - anders als etwa eine Kalenderfarbe, die bei jedem
     * Seitenaufruf neu gerendert wird. Wer die Auswahl oder die Regel aendert,
     * sah deshalb bis zum naechsten planmaessigen Lauf gar nichts, bei
     * stuendlichem Sync also bis zu eine Stunde lang (Nutzerbefund 2026-09-02:
     * „Der Wechsel des neuen Radio Buttons zeigt nicht direkt zu greifen").
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public static function roomSettingsChanged($oldValue, $newValue): bool
    {
        $old = is_array($oldValue) ? $oldValue : [];
        $new = is_array($newValue) ? $newValue : [];

        // Nur die Haken vergleichen: Name und Sortierschluessel kommen bei jedem
        // Abgleich frisch aus ChurchTools, und ein dort umbenannter Raum ist
        // kein Grund, ausser der Reihe zu synchronisieren.
        $ticks = static function (array $settings): array {
            $enabled = [];

            foreach (($settings['resources'] ?? []) as $id => $resource) {
                if (!empty($resource['enabled'])) {
                    $enabled[] = (int) $id;
                }
            }

            sort($enabled);

            return $enabled;
        };

        if ($ticks($old) !== $ticks($new)) {
            return true;
        }

        return (string) ($old['rooms_mode'] ?? '') !== (string) ($new['rooms_mode'] ?? '');
    }

    /**
     * Ein einzelner Lauf gleich nach dem Speichern, nicht der Sync im selben
     * Request: Der holt Termine und Buchungen und braucht dafuer Sekunden - die
     * Einstellungsseite haette sie zu warten.
     *
     * Der planmaessige Lauf bleibt davon unberuehrt; WordPress vertraegt einen
     * Einzeltermin auf demselben Hook. Zehn Sekunden Abstand, damit das
     * Speichern samt Weiterleitung sicher durch ist, bevor der Lauf beginnt.
     */
    private static function scheduleImmediateSync(): void
    {
        wp_schedule_single_event(time() + 10, 'ctp_run_sync');
    }

    /**
     * Makes both cron events exist with the recurrence they're supposed to
     * have, rescheduling only when something actually differs — wp_schedule_event()
     * is a DB write, so this must not fire on every admin page load.
     */
    public static function ensureSchedules(): void
    {
        $interval = SettingsPage::get()['sync_interval'];
        if (!in_array($interval, self::SYNC_INTERVALS, true)) {
            $interval = 'hourly';
        }

        self::scheduleIfNeeded('ctp_run_sync', $interval);
        self::scheduleIfNeeded('ctp_run_retention_cleanup', 'daily');
    }

    /**
     * Die Laenge eines Intervalls in Sekunden - einmal hier, weil diese Klasse
     * die Zeitplaene anlegt und SYNC_INTERVALS besitzt. Gefragt wird von zwei
     * Seiten: SyncHealthNotice, um zu entscheiden, ab wann ein Lauf ueberfaellig
     * ist, und SyncEngine, um zu entscheiden, ueber wie viel Zeit sich leere
     * Antworten erstrecken muessen, bevor sie als richtig gelten.
     *
     * Der Rueckfall auf HOUR_IN_SECONDS greift, wenn ein Intervall aus
     * wp_get_schedules() verschwindet (ein Plugin filtert es weg) - dann lieber
     * die kuerzeste Vorgabe als 0, das waere in beiden Rechnungen oben eine
     * Division durch nichts.
     */
    public static function intervalSeconds(string $interval): int
    {
        $schedules = wp_get_schedules();
        $seconds = (int) ($schedules[$interval]['interval'] ?? 0);

        return $seconds > 0 ? $seconds : HOUR_IN_SECONDS;
    }

    private static function scheduleIfNeeded(string $hook, string $recurrence): void
    {
        $event = wp_get_scheduled_event($hook);

        if ($event !== false && $event->schedule === $recurrence) {
            return;
        }

        if ($event !== false) {
            wp_clear_scheduled_hook($hook);
        }

        // A minute out rather than time(): rescheduling happens right after a
        // settings save, and firing a full sync inside that same request would
        // stall the redirect back to the settings page.
        wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, $hook);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('ctp_run_sync');
        wp_clear_scheduled_hook('ctp_run_retention_cleanup');

        // Der Streak leerer API-Antworten zaehlt *beobachtete* Laeufe. Waehrend
        // das Plugin aus war, ist keiner gelaufen - bliebe der Zaehler stehen,
        // koennte die erste leere Antwort nach dem Reaktivieren sofort loeschen
        // (drei Laeufe von damals, dazwischen Monate). Siehe
        // SyncEngine::looksLikeApiFailure().
        delete_option('ctp_empty_sync_runs');
    }

    /**
     * dbDelta() only ever adds/changes columns and indexes, never drops data, so it's
     * safe to re-run on every version bump instead of building a full migration system.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('ctp_db_version') === self::DB_VERSION) {
            return;
        }

        self::createTables();
        self::dropRetiredSettings();
        update_option('ctp_db_version', self::DB_VERSION);
    }

    /**
     * Raeumt Einstellungen weg, die es nicht mehr gibt.
     *
     * Anlass ist `github_token`: das Feld ist mit 1.5.0 entfallen (das
     * Repository ist oeffentlich, siehe GitHubUpdateChecker), aber auf
     * bestehenden Installationen liegt der verschluesselte Wert weiter in
     * ctp_settings. sanitizeSettings() gibt den Schluessel nicht mehr zurueck,
     * er verschwaende also spaetestens beim naechsten Speichern von selbst -
     * bis dahin bliebe ein Geheimnis in der Datenbank stehen, das niemand mehr
     * liest und deshalb auch niemand mehr zurueckziehen kann.
     *
     * Bewusst am Settings-Array vorbei geschrieben, ohne den Sanitizer:
     * dieselbe Ueberlegung wie in SettingsPage::refreshCalendars() - der
     * Sanitizer wuerde hier bereits geprueften Bestand ein zweites Mal durch
     * seine Allowlists schicken.
     */
    private static function dropRetiredSettings(): void
    {
        $settings = get_option('ctp_settings', []);

        if (!is_array($settings) || !array_key_exists('github_token', $settings)) {
            return;
        }

        unset($settings['github_token']);

        remove_filter('sanitize_option_ctp_settings', [SettingsPage::class, 'sanitizeSettings']);
        update_option('ctp_settings', $settings);
        add_filter('sanitize_option_ctp_settings', [SettingsPage::class, 'sanitizeSettings']);
    }

    private static function createTables(): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'ctp_events';
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta() never drops indexes that are absent from the new SQL, so the 1.0.0
        // UNIQUE KEY on ct_event_id alone has to be removed explicitly — otherwise it
        // would still block storing more than one occurrence per recurring series.
        self::dropLegacyUniqueKeyIfPresent($tableName);

        // One ChurchTools appointment can be a recurring series (e.g. "every Monday",
        // "Mon-Fri"); each occurrence gets its own row here, identified together by
        // (ct_event_id, start_date) — a lone UNIQUE KEY on ct_event_id would collapse
        // every occurrence of a series into a single overwritten row.
        //
        // The start_date index (added in DB 1.4.0) backs the frontend's month-window
        // paging: every list/grid query filters on a start_date range and orders by
        // start_date (EventRepository::findInWindow()), which would otherwise fall
        // back to the end_date index plus a filesort. Kept as a PHP comment rather
        // than an SQL one — dbDelta() parses the CREATE TABLE body line by line and
        // chokes on "--" comments between column definitions.
        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ct_event_id BIGINT UNSIGNED NOT NULL,
            ct_calendar_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) NULL,
            description LONGTEXT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            all_day TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            location VARCHAR(255) NULL,
            image_url VARCHAR(1000) NULL,
            attachment_id BIGINT UNSIGNED NULL,
            raw_data LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY ct_event_occurrence (ct_event_id, start_date),
            KEY ct_calendar_id (ct_calendar_id),
            KEY end_date (end_date),
            KEY start_date (start_date)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function dropLegacyUniqueKeyIfPresent(string $tableName): void
    {
        global $wpdb;

        $indexExists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
            $tableName,
            'ct_event_id'
        ));

        if ((int) $indexExists > 0) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $tableName, 'ct_event_id'));
        }
    }
}
