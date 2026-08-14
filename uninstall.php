<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('ctp_settings');
delete_option('ctp_last_sync');
delete_option('ctp_db_version');

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ctp_events");
