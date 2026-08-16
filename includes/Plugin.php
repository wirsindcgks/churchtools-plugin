<?php

declare(strict_types=1);

namespace ChurchToolsPlugin;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Blocks\EventListBlock;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\Assets;
use ChurchToolsPlugin\Frontend\EventDetailPage;
use ChurchToolsPlugin\Frontend\Shortcode;
use ChurchToolsPlugin\Integrations\WpBakeryIntegration;
use ChurchToolsPlugin\Sync\RetentionCleanup;
use ChurchToolsPlugin\Sync\SyncEngine;
use ChurchToolsPlugin\Update\GitHubUpdateChecker;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function init(): void
    {
        load_plugin_textdomain(
            'churchtools-plugin',
            false,
            dirname(plugin_basename(CTP_PLUGIN_FILE)) . '/languages'
        );

        Installer::maybeUpgrade();

        if (is_admin()) {
            (new SettingsPage())->register();
        }

        (new Shortcode())->register();
        (new Assets())->register();
        (new EventListBlock())->register();
        (new WpBakeryIntegration())->register();

        EventDetailPage::registerHooks();
        SyncEngine::registerHooks();
        RetentionCleanup::registerHooks();
        GitHubUpdateChecker::register();
    }
}
