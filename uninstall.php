<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Wrapped in an IIFE, not run as top-level script code, so PHPCS's
// PrefixAllGlobals sniff doesn't flag the local variables below as globals
// needing a "ctp_"/"CTP_" prefix (same fix as the churchtools-plugin.php
// bootstrap, see plan.md).
(function (): void {
    global $wpdb;

    $settings = get_option('ctp_settings', []);

    // Die Sperren der Abgleiche (Sync\RunLock) sind kein Datenbestand, sie
    // gehen in jedem Fall.
    delete_option('ctp_lock_events');
    delete_option('ctp_lock_groups');

    if (!empty($settings['keep_data_on_uninstall'])) {
        // „Daten behalten" heisst Termine, Gruppen und Einstellungen - nicht
        // das Geheimnis. Ein API-Key in der Datenbank eines Plugins, das es
        // nicht mehr gibt, liest niemand mehr und zieht auch niemand mehr
        // zurueck (Sicherheits-Review 2026-09-14). Bei einer Neuinstallation
        // wird er einmal neu eingetragen.
        if (is_array($settings) && array_key_exists('api_key', $settings)) {
            unset($settings['api_key']);
            update_option('ctp_settings', $settings);
        }

        return;
    }

    delete_option('ctp_settings');
    delete_option('ctp_last_sync');
    delete_option('ctp_calendars_fetched');
    delete_option('ctp_last_sync_error');
    delete_option('ctp_calendars_sync_error');
    delete_option('ctp_image_import_warning');
    delete_option('ctp_empty_sync_runs');
    delete_option('ctp_db_version');
    delete_option('ctp_events_cache_version');
    delete_option('ctp_rewrite_version');
    delete_option('ctp_church_address');
    delete_option('ctp_resources_fetched');

    // Die Gruppen (siehe Groups\GroupSync::optionNames() - die Klasse ist hier
    // nicht geladen, also dieselbe Liste noch einmal). Ihre Bilder stehen in
    // keiner Tabelle, sondern in der Zuordnung Gruppe => Anhang.
    $groupImages = get_option('ctp_group_images', []);

    foreach (is_array($groupImages) ? $groupImages : [] as $attachmentId) {
        wp_delete_attachment((int) $attachmentId, true);
    }

    delete_option('ctp_group_settings');
    delete_option('ctp_groups');
    delete_option('ctp_group_images');
    delete_option('ctp_group_sync_error');
    delete_option('ctp_group_image_warning');
    delete_option('ctp_group_last_sync');
    delete_option('ctp_group_homepages_fetched');

    $tableName = $wpdb->prefix . 'ctp_events';

    // Imported event images live in the media library as attachments referenced
    // by attachment_id, not inside this table — dropping the table alone would
    // leave them behind as orphaned uploads with nothing pointing at them anymore.
    $attachmentIds = $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT attachment_id FROM %i WHERE attachment_id IS NOT NULL',
        $tableName
    ));

    foreach ($attachmentIds as $attachmentId) {
        wp_delete_attachment((int) $attachmentId, true);
    }

    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tableName));
})();
