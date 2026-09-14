<?php

declare(strict_types=1);

namespace ChurchToolsPlugin;

use ChurchToolsPlugin\Admin\GroupsTab;
use ChurchToolsPlugin\Admin\PrivacyPolicy;
use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Admin\SyncHealthNotice;
use ChurchToolsPlugin\Blocks\EventListBlock;
use ChurchToolsPlugin\Blocks\GroupListBlock;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\Assets;
use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\EventDetailPage;
use ChurchToolsPlugin\Frontend\EventFeed;
use ChurchToolsPlugin\Frontend\EventIcs;
use ChurchToolsPlugin\Frontend\EventSitemap;
use ChurchToolsPlugin\Frontend\EventsEndpoint;
use ChurchToolsPlugin\Frontend\Shortcode;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Integrations\WpBakeryIntegration;
use ChurchToolsPlugin\Sync\ImageSizeBackfill;
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
        Installer::registerHooks();

        if (is_admin()) {
            (new SettingsPage())->register();
            (new GroupsTab())->register();
            (new SyncHealthNotice())->register();
            (new PrivacyPolicy())->register();
        }

        (new Shortcode())->register();
        (new Assets())->register();
        (new EventsEndpoint())->register();
        (new EventListBlock())->register();
        (new GroupListBlock())->register();
        (new WpBakeryIntegration())->register();

        EventDetailPage::registerHooks();
        EventSitemap::registerHooks();
        EventIcs::registerHooks();
        EventFeed::registerHooks();
        CardImage::registerHooks();
        SyncEngine::registerHooks();
        GroupSync::registerHooks();
        RetentionCleanup::registerHooks();
        ImageSizeBackfill::registerHooks();
        GitHubUpdateChecker::register();
    }
}
