<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Die Suche über den ganzen Sync-Horizont - und, seit der Eventfinder seine
 * Fragen serverseitig stellen kann, dieselbe Abfrage *mit* Zeitfenster.
 *
 * Beides läuft inzwischen durch findInWindow(): die frühere eigene
 * Suchabfrage war eine zweite Kopie derselben WHERE-Bedingungen, und die
 * Kombination „Suchwort in diesem Monat“ hätte eine dritte gebraucht. Die
 * Zusammenlegung ist genau das, was diese Tests absichern - dass die
 * Bedingungen einander nicht überschreiben und die Platzhalter in der
 * richtigen Reihenfolge stehen (ein Fehler daran wäre zur Laufzeit ein
 * Fatal, im SQL-Text aber unsichtbar).
 *
 * Läuft gegen eine SQLite-Datenbank im Arbeitsspeicher (siehe SqliteWpdb).
 */
final class EventSearchQueryTest extends TestCase
{
    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-08-18 12:00:00');
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    public function testSearchMatchesTheTitle(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00', null, null, ['title' => 'Taufgottesdienst']);
        $this->wpdb->seedEvent(1, '2026-09-02 19:00:00', '2026-09-02 21:00:00', null, null, ['title' => 'Hauskreis']);

        $found = $this->repository->searchUpcoming([1], 'tauf');

        $this->assertCount(1, $found);
        $this->assertSame('Taufgottesdienst', $found[0]['title']);
    }

    /**
     * Untertitel und Ort gehören mit in die Suche - „Gemeindehaus“ steht bei
     * keinem Termin im Titel und ist trotzdem das, wonach jemand sucht.
     */
    public function testSearchAlsoMatchesSubtitleAndLocation(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00', null, null, ['subtitle' => 'mit Abendmahl']);
        $this->wpdb->seedEvent(1, '2026-09-02 19:00:00', '2026-09-02 21:00:00', null, null, ['location' => 'Gemeindehaus']);
        $this->wpdb->seedEvent(1, '2026-09-03 19:00:00', '2026-09-03 21:00:00', null, null, ['title' => 'Probe']);

        $this->assertCount(1, $this->repository->searchUpcoming([1], 'Abendmahl'));
        $this->assertCount(1, $this->repository->searchUpcoming([1], 'Gemeindehaus'));
    }

    /**
     * Der Grund, warum die Suche überhaupt zum Server geht: ein Treffer weit
     * jenseits des geladenen Monatsfensters.
     */
    public function testSearchReachesPastTheLoadedWindow(): void
    {
        $this->wpdb->seedEvent(1, '2027-03-14 10:00:00', '2027-03-14 12:00:00', null, null, ['title' => 'Konfirmation']);

        $this->assertCount(1, $this->repository->searchUpcoming([1], 'Konfirmation'));
    }

    public function testSearchIgnoresEventsThatAreOver(): void
    {
        $this->wpdb->seedEvent(1, '2026-08-01 19:00:00', '2026-08-01 21:00:00', null, null, ['title' => 'Konfirmation']);

        $this->assertSame([], $this->repository->searchUpcoming([1], 'Konfirmation'));
    }

    public function testSearchStaysInsideTheRequestedCalendars(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00', null, null, ['title' => 'Konzert']);
        $this->wpdb->seedEvent(2, '2026-09-02 19:00:00', '2026-09-02 21:00:00', null, null, ['title' => 'Konzert']);

        $this->assertCount(1, $this->repository->searchUpcoming([1], 'Konzert'));
    }

    public function testEmptySearchFindsNothingRatherThanEverything(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00', null, null, ['title' => 'Konzert']);

        $this->assertSame([], $this->repository->searchUpcoming([1], '   '));
    }

    /**
     * Der Fall, für den die beiden Abfragen zusammengelegt wurden: das
     * Suchfeld ausgefüllt *und* eine Zeitraum-Schaltfläche aktiv. Beide
     * Bedingungen müssen gelten, nicht nur die zuletzt angehängte.
     */
    public function testSearchAndWindowNarrowTogether(): void
    {
        $this->wpdb->seedEvent(1, '2026-08-20 19:00:00', '2026-08-20 21:00:00', null, null, ['title' => 'Hauskreis']);
        $this->wpdb->seedEvent(1, '2026-10-15 19:00:00', '2026-10-15 21:00:00', null, null, ['title' => 'Hauskreis']);

        $inWindow = $this->repository->findInWindow([1], '2026-08-18 00:00:00', '2026-09-01 00:00:00', 0, 0, 'Hauskreis');

        $this->assertCount(1, $inWindow);
        $this->assertSame('2026-08-20 19:00:00', $inWindow[0]['start_date']);
    }

    /**
     * Ohne Suchwort bleibt findInWindow() die reine Fensterabfrage, die das
     * Paging seit jeher benutzt - der neue Parameter darf sie nicht anfassen.
     */
    public function testWindowWithoutSearchIsUnfiltered(): void
    {
        $this->wpdb->seedEvent(1, '2026-08-20 19:00:00', '2026-08-20 21:00:00', null, null, ['title' => 'Hauskreis']);
        $this->wpdb->seedEvent(1, '2026-08-21 19:00:00', '2026-08-21 21:00:00', null, null, ['title' => 'Gottesdienst']);

        $this->assertCount(2, $this->repository->findInWindow([1], '2026-08-18 00:00:00', '2026-09-01 00:00:00'));
    }
}
