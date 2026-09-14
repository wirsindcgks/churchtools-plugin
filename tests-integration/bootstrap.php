<?php

declare(strict_types=1);

/**
 * Integrationstests gegen ein echtes WordPress (Sicherheits-Review 2026-09-14,
 * Phase 5.2). Die Unit-Tests unter tests/ laufen gegen einen Nachbau; was im
 * Verhalten von WordPress selbst liegt - der doppelte Sanitizer beim ersten
 * Speichern, Weiterleitungen samt Header, add_option() ohne Atomaritaet -, sieht
 * der prinzipiell nicht.
 *
 * Braucht eine Wegwerf-Installation (bin/integration-setup.sh) in CTP_WP_DIR -
 * die Tests speichern Einstellungen und rufen uninstall.php auf.
 */

$wpDir = getenv('CTP_WP_DIR');

if (!is_string($wpDir) || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "CTP_WP_DIR zeigt auf keine WordPress-Installation. Erst bin/integration-setup.sh <verzeichnis> ausfuehren.\n");
    exit(1);
}

if (file_exists($wpDir . '/wp-content/plugins/churchtools-plugin') && realpath($wpDir . '/wp-content/plugins/churchtools-plugin') !== realpath(dirname(__DIR__))) {
    fwrite(STDERR, "In CTP_WP_DIR ist ein anderes churchtools-plugin installiert.\n");
    exit(1);
}

define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/';

require $wpDir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user(1);
