<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * deleteAll() ist der Aufraeumweg, wenn *kein* Kalender mehr aktiv ist (siehe
 * SyncEngine::cleanUpAfterLastCalendar()). Es ist die einzige Loeschung dieser
 * Klasse ohne Bedingung, deshalb hier festgehalten, dass sie auch wirklich
 * ohne Bedingung raeumt - und dass sie die importierten Bilder mitnimmt statt
 * sie als verwaiste Medien in der Bibliothek zurueckzulassen.
 */
final class EventRepositoryDeleteAllTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    /**
     * Vergangene wie kuenftige Zeilen, ueber mehrere Kalender - alles muss weg,
     * denn "kein Kalender aktiv" heisst, dass fuer keine dieser Zeilen noch
     * jemand zustaendig ist.
     */
    public function testRemovesEveryRowRegardlessOfCalendarOrDate(): void
    {
        $this->wpdb->seedEvent(1, '2020-01-01 10:00:00', '2020-01-01 12:00:00');
        $this->wpdb->seedEvent(2, '2026-09-01 19:00:00', '2026-09-01 21:00:00');
        $this->wpdb->seedEvent(3, '2030-05-05 08:00:00', '2030-05-05 09:00:00');

        $this->assertSame(3, $this->repository->deleteAll());
        $this->assertSame(0, $this->wpdb->countRows());
    }

    /**
     * Ein Bild gehoert der Terminserie, nicht der einzelnen Zeile - geloescht
     * wird es deshalb einmal je Serie, nicht einmal je Vorkommnis.
     */
    public function testDeletesEachSeriesImageExactlyOnce(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00', 41, 100);
        $this->wpdb->seedEvent(1, '2026-09-08 19:00:00', '2026-09-08 21:00:00', 41, 100);
        $this->wpdb->seedEvent(1, '2026-09-15 19:00:00', '2026-09-15 21:00:00', 42, 200);

        $this->repository->deleteAll();

        $this->assertSame([41, 42], ctp_test_deleted_attachments());
    }

    /**
     * Eine Serie ohne importiertes Bild darf nichts loeschen - sonst landete
     * die 0 als Anhang-ID in wp_delete_attachment().
     */
    public function testSeriesWithoutAnImportedImageDeletesNothing(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00');

        $this->repository->deleteAll();

        $this->assertSame([], ctp_test_deleted_attachments());
    }

    public function testEmptyTableIsANoOp(): void
    {
        $this->assertSame(0, $this->repository->deleteAll());
        $this->assertSame([], ctp_test_deleted_attachments());
    }
}
