<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * countsByCalendar() liefert die Zahlen, die im Tab „Kalender“ auf jeder
 * Kalenderkachel stehen (siehe SettingsPage::renderCalendarCard()). Sie sind
 * dort der einzige Hinweis darauf, ob ein aktivierter Kalender ueberhaupt noch
 * etwas liefert - eine falsche Aufteilung zwischen „kommend“ und „gesamt“
 * bliebe also unbemerkt und wuerde einen toten Kalender gesund aussehen
 * lassen.
 */
final class EventRepositoryCalendarCountsTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    public function testGroupsCountsByCalendarAndSeparatesUpcomingFromPast(): void
    {
        // Kalender 1: zwei vergangene, ein kuenftiger Termin.
        $this->wpdb->seedEvent(1, '2020-01-01 10:00:00', '2020-01-01 12:00:00');
        $this->wpdb->seedEvent(1, '2021-01-01 10:00:00', '2021-01-01 12:00:00');
        $this->wpdb->seedEvent(1, '2030-05-05 08:00:00', '2030-05-05 09:00:00');
        // Kalender 2: nur ein kuenftiger Termin.
        $this->wpdb->seedEvent(2, '2030-06-06 08:00:00', '2030-06-06 09:00:00');

        $counts = $this->repository->countsByCalendar();

        $this->assertSame(['total' => 3, 'upcoming' => 1], $counts[1]);
        $this->assertSame(['total' => 1, 'upcoming' => 1], $counts[2]);
    }

    /**
     * Ein Kalender ohne eine einzige Zeile taucht gar nicht erst auf - genau
     * deshalb setzt der Aufrufer den Standardwert selbst (siehe
     * renderCalendarsTab()), statt sich hier auf einen Nulleintrag zu
     * verlassen.
     */
    public function testCalendarsWithoutRowsAreAbsentRatherThanZero(): void
    {
        $this->wpdb->seedEvent(7, '2030-05-05 08:00:00', '2030-05-05 09:00:00');

        $counts = $this->repository->countsByCalendar();

        $this->assertArrayHasKey(7, $counts);
        $this->assertArrayNotHasKey(8, $counts);
    }

    public function testEmptyTableYieldsAnEmptyMap(): void
    {
        $this->assertSame([], $this->repository->countsByCalendar());
    }
}
