<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Sync\RetentionCleanup;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * RetentionCleanup::run() raeumt seit 1.35.0 zwei Tabellen auf, nicht mehr
 * nur die Termine: Das Protokoll (Log) haengt bewusst an diesem taeglichen
 * Hook statt am stuendlichen Sync, siehe die Begruendung in der Klasse
 * selbst. Beide Grenzen werden hier gegen echtes SQL geprueft, nicht gegen
 * behauptetes Verhalten.
 */
final class RetentionCleanupTest extends TestCase
{
    private SqliteWpdb $wpdb;

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_deleted_attachments();
        ctp_test_set_current_time('2026-09-16 12:00:00');

        $this->wpdb = ctp_test_install_wpdb();
    }

    public function testEventsOlderThanTheRetentionSettingAreDeleted(): void
    {
        ctp_test_set_option('ctp_settings', ['retention_days' => 30]);

        $this->wpdb->seedEvent(1, '2026-08-10 09:00:00', '2026-08-10 10:00:00'); // 37 Tage alt, muss weg
        $this->wpdb->seedEvent(1, '2026-09-10 09:00:00', '2026-09-10 10:00:00'); // 6 Tage alt, bleibt

        RetentionCleanup::run();

        $this->assertSame(1, $this->wpdb->countRows());
    }

    public function testLogEntriesOlderThan30DaysAreDeletedRegardlessOfTheRetentionSetting(): void
    {
        // Absichtlich eine andere Aufbewahrungsfrist fuer Termine als fuer
        // das Protokoll - beide Grenzen sind unabhaengig voneinander.
        ctp_test_set_option('ctp_settings', ['retention_days' => 365]);

        $this->wpdb->seedLogEntry('2026-08-10 09:00:00', message: '37 Tage alt'); // muss weg
        $this->wpdb->seedLogEntry('2026-09-10 09:00:00', message: '6 Tage alt'); // bleibt

        RetentionCleanup::run();

        $this->assertSame(1, $this->wpdb->countLogRows());
    }

    public function testLogEntriesAreCappedAt1000EvenWithinThe30Days(): void
    {
        ctp_test_set_option('ctp_settings', ['retention_days' => 30]);

        for ($i = 1; $i <= 1005; $i++) {
            $this->wpdb->seedLogEntry('2026-09-16 00:00:00', message: "Eintrag {$i}");
        }

        RetentionCleanup::run();

        $this->assertSame(1000, $this->wpdb->countLogRows());
    }
}
