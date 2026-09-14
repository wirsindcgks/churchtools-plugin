<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Release;

use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * uninstall.php laeuft genau einmal im Leben einer Installation und ist danach
 * nicht mehr zu korrigieren - was es liegen laesst, liegt fuer immer.
 */
final class UninstallTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_deleted_attachments();
        ctp_test_install_wpdb();

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'churchtools-plugin/churchtools-plugin.php');
        }
    }

    /** „Daten behalten" behaelt Termine und Einstellungen, nicht den API-Key. */
    public function testKeepingDataStillRemovesTheApiKey(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt('token'), 'keep_data_on_uninstall' => true]);
        ctp_test_set_option('ctp_church_address', ['name' => 'Gemeindehaus']);

        require dirname(__DIR__, 2) . '/uninstall.php';

        $settings = get_option('ctp_settings');
        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertSame('musterkirche', $settings['instance']);
        $this->assertSame(['name' => 'Gemeindehaus'], get_option('ctp_church_address'));
    }

    public function testRemovingDataLeavesNoOptionOfThisPluginBehind(): void
    {
        foreach (['ctp_settings', 'ctp_church_address', 'ctp_resources_fetched', 'ctp_lock_events', 'ctp_lock_groups', 'ctp_groups', 'ctp_group_settings'] as $option) {
            ctp_test_set_option($option, ['x']);
        }

        require dirname(__DIR__, 2) . '/uninstall.php';

        $left = array_filter(array_keys($GLOBALS['ctp_test_options']), static fn (string $name): bool => str_starts_with($name, 'ctp_'));
        $this->assertSame([], array_values($left));
    }
}
