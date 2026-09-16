<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SyncHealthNotice;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * eventWarnings()/groupWarnings() ergaenzen eventProblem()/groupProblem():
 * Ein Lauf kann gelingen (kein Fehler, nicht ueberfaellig) und trotzdem
 * Log::warning()-Eintraege hinterlassen haben (Raumbuchung, Gemeindeanschrift,
 * Bild-Import) - ohne diese Methoden waeren die nur auf dem Reiter
 * "Protokoll" sichtbar, den kaum jemand von sich aus besucht.
 */
final class SyncHealthNoticeWarningsTest extends TestCase
{
    private SqliteWpdb $wpdb;

    protected function setUp(): void
    {
        ctp_test_reset_options();
        putenv('CTP_API_KEY');

        $this->wpdb = ctp_test_install_wpdb();
    }

    private function configureEvents(): void
    {
        ctp_test_set_option('ctp_settings', [
            'instance' => 'musterkirche',
            'api_key' => Crypto::encrypt('token'),
            'calendars' => [1 => ['name' => 'Gottesdienste', 'enabled' => true]],
        ]);
    }

    private function configureGroups(): void
    {
        ctp_test_set_option('ctp_group_settings', [
            'homepages' => [1 => ['name' => 'Hauskreise', 'hash' => 'abc', 'enabled' => true]],
        ]);
    }

    public function testEventWarningsIsNullWhenNotConfigured(): void
    {
        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testEventWarningsIsNullBeforeTheFirstSuccessfulRun(): void
    {
        $this->configureEvents();

        // eventProblem() meldet "never" fuer diesen Fall bereits - eine
        // zweite Meldung hier waere dieselbe Aussage in anderen Worten.
        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testEventWarningsIsNullWithoutAnyWarningSinceLastSync(): void
    {
        $this->configureEvents();
        ctp_test_set_option('ctp_last_sync', '2026-09-16 10:00:00');

        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testEventWarningsReportsTheCountSinceLastSync(): void
    {
        $this->configureEvents();
        ctp_test_set_option('ctp_last_sync', '2026-09-16 10:00:00');
        $this->wpdb->seedLogEntry('2026-09-16 11:00:00', level: 'warning', area: 'events', message: 'Raumbuchungen konnten nicht abgefragt werden');

        $warning = SyncHealthNotice::eventWarnings();

        $this->assertNotNull($warning);
        $this->assertSame('warning', $warning['type']);
        $this->assertStringContainsString('1', $warning['message']);
    }

    public function testEventWarningsIgnoresEntriesBeforeLastSync(): void
    {
        $this->configureEvents();
        ctp_test_set_option('ctp_last_sync', '2026-09-16 10:00:00');
        $this->wpdb->seedLogEntry('2026-09-16 09:00:00', level: 'warning', area: 'events', message: 'alt');

        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testEventWarningsIgnoresErrorLevelEntries(): void
    {
        $this->configureEvents();
        ctp_test_set_option('ctp_last_sync', '2026-09-16 10:00:00');
        // Ein Fehler ist eventProblem()'s Zustaendigkeit (ctp_last_sync_error),
        // nicht der einer zusaetzlichen Warnmeldung.
        $this->wpdb->seedLogEntry('2026-09-16 11:00:00', level: 'error', area: 'events', message: 'Synchronisation fehlgeschlagen');

        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testEventWarningsIgnoresOtherAreas(): void
    {
        $this->configureEvents();
        ctp_test_set_option('ctp_last_sync', '2026-09-16 10:00:00');
        $this->wpdb->seedLogEntry('2026-09-16 11:00:00', level: 'warning', area: 'groups', message: 'Homepage konnte nicht abgeglichen werden');

        $this->assertNull(SyncHealthNotice::eventWarnings());
    }

    public function testGroupWarningsIsNullWithoutAnyEnabledHomepage(): void
    {
        $this->assertNull(SyncHealthNotice::groupWarnings());
    }

    public function testGroupWarningsReportsTheCountSinceLastSync(): void
    {
        $this->configureGroups();
        ctp_test_set_option('ctp_group_last_sync', '2026-09-16 10:00:00');
        $this->wpdb->seedLogEntry('2026-09-16 11:00:00', level: 'warning', area: 'groups', message: 'a');
        $this->wpdb->seedLogEntry('2026-09-16 11:05:00', level: 'warning', area: 'groups', message: 'b');

        $warning = SyncHealthNotice::groupWarnings();

        $this->assertNotNull($warning);
        $this->assertStringContainsString('2', $warning['message']);
    }
}
