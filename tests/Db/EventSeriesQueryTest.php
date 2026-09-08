<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Die zwei Abfragen hinter „Alle N importieren": welche Termine in die Datei
 * kommen (findSeries) und welche Zahl auf dem Knopf steht (seriesCounts).
 *
 * Beide müssen dieselbe Grenze ziehen, sonst verspricht die Beschriftung eine
 * andere Anzahl, als die Datei liefert — und das merkt niemand, bis der
 * Kalender des Besuchers zu wenige Einträge hat. Genau das ist hier
 * behauptet, gegen eine SQLite-Datenbank im Arbeitsspeicher (siehe SqliteWpdb)
 * statt gegen den erzeugten SQL-Text.
 */
final class EventSeriesQueryTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-09-15 12:00:00');
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    public function testTheSeriesComesBackInDateOrder(): void
    {
        $this->seedSeries(500, ['2026-10-01', '2026-09-17', '2026-09-24']);

        $daten = array_map(
            static fn (array $zeile): string => substr((string) $zeile['start_date'], 0, 10),
            $this->repository->findSeries(500)
        );

        $this->assertSame(['2026-09-17', '2026-09-24', '2026-10-01'], $daten);
    }

    /**
     * Vergangene Vorkommnisse bleiben draußen. Wer einen Hauskreis in seinen
     * Kalender legt, will die nächsten Termine und nicht die vierzig, die
     * schon waren.
     */
    public function testPastDatesStayOutOfTheFile(): void
    {
        $this->seedSeries(500, ['2026-08-20', '2026-09-03', '2026-09-17', '2026-09-24']);

        $this->assertCount(2, $this->repository->findSeries(500));
    }

    /**
     * `end_date >= jetzt`, nicht `start_date` — derselbe Schnitt, den
     * findInWindow() für die Liste zieht. Ein Termin, der heute früh begonnen
     * hat und noch läuft, steht dort noch und gehört deshalb auch in die
     * Datei.
     */
    public function testAnEventStillRunningTodayIsCarriedAlong(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-15 09:00:00', '2026-09-15 22:00:00', null, 500);

        $this->assertCount(1, $this->repository->findSeries(500));
    }

    public function testOnlyTheAskedForSeriesComesBack(): void
    {
        $this->seedSeries(500, ['2026-09-17', '2026-09-24']);
        $this->seedSeries(600, ['2026-09-18', '2026-09-25']);

        foreach ($this->repository->findSeries(500) as $zeile) {
            $this->assertSame(500, (int) $zeile['ct_event_id']);
        }
    }

    /**
     * Der Grund, warum seriesCounts() überhaupt existiert: eine Abfrage für
     * eine ganze Seite. Je Kachel einmal zu zählen wäre dasselbe N+1-Muster,
     * das in diesem Plugin schon einmal 55 Abfragen aus 5 gemacht hat.
     */
    public function testAllCountsForAPageComeBackFromOneQuery(): void
    {
        $this->seedSeries(500, ['2026-09-17', '2026-09-24', '2026-10-01']);
        $this->seedSeries(600, ['2026-09-18', '2026-09-25']);
        $this->seedSeries(700, ['2026-09-19']);

        $abfragen = $this->wpdb->num_queries;
        $counts = $this->repository->seriesCounts([500, 600, 700, 500]);

        $this->assertSame([500 => 3, 600 => 2, 700 => 1], $counts);
        $this->assertSame(1, $this->wpdb->num_queries - $abfragen, 'Mehr als eine Abfrage für eine Seite.');
    }

    /**
     * Die Zusage, an der die Beschriftung hängt: Was der Knopf verspricht, ist
     * genau das, was in der Datei steht. Läuft die eine Abfrage über
     * start_date und die andere über end_date, fällt das hier auf.
     */
    public function testTheNumberOnTheButtonIsWhatTheFileContains(): void
    {
        $this->seedSeries(500, ['2026-08-01', '2026-09-03', '2026-09-17', '2026-09-24', '2026-10-01']);
        $this->wpdb->seedEvent(1, '2026-09-15 09:00:00', '2026-09-15 22:00:00', null, 500);

        $this->assertSame(
            count($this->repository->findSeries(500)),
            $this->repository->seriesCounts([500])[500] ?? 0
        );
    }

    /**
     * Eine Serie, deren Termine alle vorbei sind, fehlt in der Antwort. Der
     * Aufrufer fällt auf 1 zurück und blendet den Knopf damit aus — es gibt
     * nichts mehr mitzunehmen.
     */
    public function testASeriesThatIsOverIsMissingFromTheCounts(): void
    {
        $this->seedSeries(500, ['2026-08-01', '2026-08-08']);

        $this->assertSame([], $this->repository->seriesCounts([500]));
    }

    public function testAskingForNothingQueriesNothing(): void
    {
        $abfragen = $this->wpdb->num_queries;

        $this->assertSame([], $this->repository->seriesCounts([]));
        $this->assertSame($abfragen, $this->wpdb->num_queries);
    }

    /**
     * @param string[] $daten
     */
    private function seedSeries(int $ctEventId, array $daten): void
    {
        foreach ($daten as $datum) {
            $this->wpdb->seedEvent(1, $datum . ' 19:30:00', $datum . ' 21:00:00', null, $ctEventId);
        }
    }
}
