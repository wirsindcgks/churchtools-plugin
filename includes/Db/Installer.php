<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Db;

final class Installer
{
    public const DB_VERSION = '1.3.0';

    public static function activate(): void
    {
        self::createTables();
        update_option('ctp_db_version', self::DB_VERSION);

        if (!wp_next_scheduled('ctp_run_sync')) {
            wp_schedule_event(time(), 'hourly', 'ctp_run_sync');
        }

        if (!wp_next_scheduled('ctp_run_retention_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ctp_run_retention_cleanup');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('ctp_run_sync');
        wp_clear_scheduled_hook('ctp_run_retention_cleanup');
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
        update_option('ctp_db_version', self::DB_VERSION);
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
            KEY end_date (end_date)
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
