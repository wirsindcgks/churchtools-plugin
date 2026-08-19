<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * calendarIdsWithUpcoming() entscheidet, welche Themen der Eventfinder
 * überhaupt anbietet (siehe EventListRenderer::filterCalendars()). Fällt die
 * Antwort zu großzügig aus, landet ein Besucher auf einer leeren Liste und hat
 * keine Möglichkeit, das von einem kaputten Filter zu unterscheiden; fällt sie
 * zu knapp aus, fehlt genau das Thema, nach dem er sucht.
 *
 * Läuft gegen eine SQLite-Datenbank im Arbeitsspeicher (siehe SqliteWpdb),
 * nicht gegen den erzeugten SQL-Text.
 */
final class CalendarsWithEventsTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-08-19 12:00:00');
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    public function testListsEachCalendarOnceRegardlessOfHowManyEventsItHas(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00');
        $this->wpdb->seedEvent(1, '2026-09-08 19:00:00', '2026-09-08 21:00:00');
        $this->wpdb->seedEvent(2, '2026-10-01 19:00:00', '2026-10-01 21:00:00');

        $ids = $this->repository->calendarIdsWithUpcoming();
        sort($ids);

        $this->assertSame([1, 2], $ids);
    }

    /**
     * Der Fall, um den es geht: ein Kalender, dessen Termine alle vorbei sind.
     * Er steht weiterhin in den Einstellungen und war deshalb bisher ein
     * Knopf, hinter dem nichts lag.
     */
    public function testCalendarWithOnlyPastEventsIsLeftOut(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00');
        $this->wpdb->seedEvent(2, '2026-05-01 19:00:00', '2026-05-01 21:00:00');

        $this->assertSame([1], $this->repository->calendarIdsWithUpcoming());
    }

    /**
     * Dieselbe Grenze wie in jeder anderen Frontend-Abfrage: end_date, nicht
     * start_date. Ein Termin, der heute früh begonnen hat und noch läuft,
     * zählt mit.
     */
    public function testAnEventStillRunningTodayCounts(): void
    {
        $this->wpdb->seedEvent(3, '2026-08-19 09:00:00', '2026-08-19 22:00:00');

        $this->assertSame([3], $this->repository->calendarIdsWithUpcoming());
    }

    /**
     * Die Instanz gibt vor, aus welchen Kalendern sie zeichnet - ein Kalender
     * mit Terminen, der nicht dazugehört, darf nicht als Thema auftauchen.
     */
    public function testStaysInsideTheRequestedCalendars(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00');
        $this->wpdb->seedEvent(2, '2026-09-02 19:00:00', '2026-09-02 21:00:00');

        $this->assertSame([1], $this->repository->calendarIdsWithUpcoming([1]));
    }

    public function testEmptyTableYieldsNoCalendars(): void
    {
        $this->assertSame([], $this->repository->calendarIdsWithUpcoming());
    }
}
