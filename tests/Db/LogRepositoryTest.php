<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Laeuft gegen eine SQLite-Datenbank im Arbeitsspeicher (siehe SqliteWpdb),
 * nicht gegen den erzeugten SQL-Text - dieselbe Begruendung wie bei den
 * Db-Tests der Termine: Ein Textvergleich bestaetigt nur, was jemand
 * hingeschrieben hat, nicht ob eine Grenze (Filter, Seite, Altersschnitt)
 * stimmt.
 */
final class LogRepositoryTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private LogRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-09-16 12:00:00');

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new LogRepository();
    }

    public function testInsertAndFindRoundTrip(): void
    {
        $this->repository->insert('warning', 'images', 'Terminbild konnte nicht importiert werden', ['url' => 'https://example.com/bild.jpg']);

        $rows = $this->repository->find();

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['level']);
        $this->assertSame('images', $rows[0]['area']);
        $this->assertSame('Terminbild konnte nicht importiert werden', $rows[0]['message']);
        $this->assertSame(['url' => 'https://example.com/bild.jpg'], $rows[0]['context']);
        $this->assertSame('2026-09-16 12:00:00', $rows[0]['logged_at']);
    }

    /** Ein leerer Kontext landet als '' und kommt als [] zurueck, nicht als Fehler beim Dekodieren. */
    public function testEmptyContextRoundTripsToEmptyArray(): void
    {
        $this->repository->insert('info', 'events', 'Synchronisation abgeschlossen', []);

        $this->assertSame([], $this->repository->find()[0]['context']);
    }

    public function testFindOrdersNewestFirst(): void
    {
        $this->repository->insert('info', 'events', 'zuerst', []);
        $this->repository->insert('info', 'events', 'danach', []);

        $rows = $this->repository->find();

        $this->assertSame('danach', $rows[0]['message']);
        $this->assertSame('zuerst', $rows[1]['message']);
    }

    public function testFilterByLevel(): void
    {
        $this->repository->insert('error', 'events', 'Fehler', []);
        $this->repository->insert('info', 'events', 'Info', []);

        $rows = $this->repository->find(['level' => 'error']);

        $this->assertCount(1, $rows);
        $this->assertSame('Fehler', $rows[0]['message']);
    }

    public function testFilterByArea(): void
    {
        $this->repository->insert('warning', 'images', 'Bild', []);
        $this->repository->insert('warning', 'groups', 'Gruppe', []);

        $rows = $this->repository->find(['area' => 'groups']);

        $this->assertCount(1, $rows);
        $this->assertSame('Gruppe', $rows[0]['message']);
    }

    public function testCombinedFilterAppliesBothConditions(): void
    {
        $this->repository->insert('error', 'groups', 'passt', []);
        $this->repository->insert('warning', 'groups', 'falsche Stufe', []);
        $this->repository->insert('error', 'events', 'falscher Bereich', []);

        $rows = $this->repository->find(['level' => 'error', 'area' => 'groups']);

        $this->assertCount(1, $rows);
        $this->assertSame('passt', $rows[0]['message']);
    }

    public function testCountRespectsTheSameFilters(): void
    {
        $this->repository->insert('error', 'events', 'a', []);
        $this->repository->insert('info', 'events', 'b', []);

        $this->assertSame(2, $this->repository->count());
        $this->assertSame(1, $this->repository->count(['level' => 'error']));
    }

    /**
     * Fuer SyncHealthNotice::eventWarnings()/groupWarnings() - "seit dem
     * letzten erfolgreichen Lauf" ist echt "danach", der Erfolg selbst zaehlt
     * nicht als Warnung mit.
     */
    public function testSinceFilterExcludesEntriesAtOrBeforeTheGivenTime(): void
    {
        $this->wpdb->seedLogEntry('2026-09-16 09:00:00', level: 'warning', message: 'davor');
        $this->wpdb->seedLogEntry('2026-09-16 10:00:00', level: 'warning', message: 'genau dann');
        $this->wpdb->seedLogEntry('2026-09-16 11:00:00', level: 'warning', message: 'danach');

        $this->assertSame(1, $this->repository->count(['since' => '2026-09-16 10:00:00']));
        $this->assertSame('danach', $this->repository->find(['since' => '2026-09-16 10:00:00'])[0]['message']);
    }

    /** LogRepository::PAGE_SIZE ist 50 - Seite 2 zeigt den Rest. */
    public function testPaginationSplitsAtPageSize(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->repository->insert('info', 'events', "Eintrag {$i}", []);
        }

        $this->assertCount(50, $this->repository->find());
        $this->assertCount(5, $this->repository->find([], 2));
        // Seite 1 zeigt die zuletzt eingetragenen (id DESC) - "Eintrag 55" zuerst.
        $this->assertSame('Eintrag 55', $this->repository->find()[0]['message']);
        $this->assertSame('Eintrag 1', $this->repository->find([], 2)[4]['message']);
    }

    public function testPruneRemovesEntriesOlderThanTheCutoff(): void
    {
        $this->wpdb->seedLogEntry('2026-08-01 00:00:00', message: 'alt');
        $this->wpdb->seedLogEntry('2026-09-15 00:00:00', message: 'neu');

        $deleted = $this->repository->prune(new DateTimeImmutable('2026-08-17 00:00:00'), 1000);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->wpdb->countLogRows());
        $this->assertSame('neu', $this->repository->find()[0]['message']);
    }

    /**
     * Die Altersgrenze allein reicht nicht - ein sehr aktiver Sync koennte
     * sonst innerhalb der 30 Tage beliebig viele Zeilen anhaeufen. Geloescht
     * werden dann die aeltesten zuerst, bis genau MAX_ENTRIES uebrig sind.
     */
    public function testPruneCapsAtMaxEntriesEvenWithinTheAgeCutoff(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->wpdb->seedLogEntry("2026-09-1{$i} 00:00:00", message: "Eintrag {$i}");
        }

        $deleted = $this->repository->prune(new DateTimeImmutable('2026-01-01 00:00:00'), 3);

        $this->assertSame(2, $deleted);
        $this->assertSame(3, $this->wpdb->countLogRows());

        $remaining = array_column($this->repository->find(), 'message');
        // Die zwei aeltesten (Eintrag 1, Eintrag 2) sind weg, der Rest bleibt.
        $this->assertSame(['Eintrag 5', 'Eintrag 4', 'Eintrag 3'], $remaining);
    }

    public function testPruneOnEmptyTableDeletesNothing(): void
    {
        $this->assertSame(0, $this->repository->prune(new DateTimeImmutable('2026-01-01 00:00:00'), 1000));
    }
}
