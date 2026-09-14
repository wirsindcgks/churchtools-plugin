<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Settings;

final class RetentionCleanup
{
    public static function registerHooks(): void
    {
        add_action('ctp_run_retention_cleanup', [self::class, 'run']);
    }

    public static function run(): void
    {
        $retentionDays = Settings::get()['retention_days'];

        // current_datetime() matches the WordPress-configured timezone that
        // SyncEngine::toMysqlDate() stores end_date in — using the PHP-default
        // timezone here instead could cut the cutoff off by hours.
        $cutoff = current_datetime()->modify("-{$retentionDays} days");

        (new EventRepository())->deleteOlderThan($cutoff);
    }
}
