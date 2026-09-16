<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Settings;

final class RetentionCleanup
{
    /**
     * Wie viele Tage das Protokoll (Log) hoechstens behaelt - unabhaengig von
     * der einstellbaren Aufbewahrungsfrist fuer Termine oben, siehe
     * Db\LogRepository::prune().
     */
    private const LOG_MAX_AGE_DAYS = 30;

    /** Wie viele Eintraege das Protokoll hoechstens behaelt. */
    private const LOG_MAX_ENTRIES = 1000;

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

        // Das Protokoll haengt an diesem taeglichen Hook, nicht am
        // stuendlichen Sync (Abweichung von der ersten Skizze in plan.md):
        // Dieser Lauf ist bereits fuers Aufraeumen da, und im Sync zu putzen
        // koestete jeden Lauf eine Abfrage ohne Gegenwert.
        $logCutoff = current_datetime()->modify('-' . self::LOG_MAX_AGE_DAYS . ' days');
        (new LogRepository())->prune($logCutoff, self::LOG_MAX_ENTRIES);
    }
}
