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
    if (!empty($settings['keep_data_on_uninstall'])) {
        return;
    }

    delete_option('ctp_settings');
    delete_option('ctp_last_sync');
    delete_option('ctp_last_sync_error');
    delete_option('ctp_empty_sync_runs');
    delete_option('ctp_db_version');
    delete_option('ctp_events_cache_version');
    delete_option('ctp_rewrite_version');

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
