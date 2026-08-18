<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Deckt hasEventsBetween() ab - die Frage "liegen im abgefragten Zeitraum
 * gespeicherte Termine?", an der der Leer-Antwort-Schutz in
 * SyncEngine::doRun() haengt. Faellt sie faelschlich mit "nein" aus, laeuft
 * eine gestoerte API-Antwort ungebremst in deleteOrphans(); faellt sie
 * faelschlich mit "ja" aus, meldet ein gesunder Sync einen Fehler.
 *
 * Die Methode entstand als Kopie von hasEventsFrom() und unterscheidet sich
 * von ihr an beiden Enden des Fensters. Genau diese beiden Unterschiede sind
 * hier eigene Tests: Sie sind der Grund, warum es zwei Methoden gibt, und das
 * Erste, was eine spaetere Zusammenlegung der beiden kaputtmachen wuerde.
 *
 * Laeuft gegen eine SQLite-Datenbank im Arbeitsspeicher (siehe SqliteWpdb),
 * nicht gegen den erzeugten SQL-Text - ein Textvergleich wuerde bei
 * Grenzfehlern genau das bestaetigen, was jemand hingeschrieben hat.
 */
final class EventWindowQueryTest extends TestCase
{
    /** Das Fenster, das doRun() aus Heute und sync_days_ahead bildet. */
    private const FROM = '2026-08-18 00:00:00';
    private const TO = '2026-12-31 23:59:59';

    private SqliteWpdb $wpdb;

    private EventRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-08-18 12:00:00');
        ctp_test_reset_deleted_attachments();

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
    }

    public function testFindsAnEventInsideTheWindow(): void
    {
        $this->wpdb->seedEvent(1, '2026-09-01 19:00:00', '2026-09-01 21:00:00');

        $this->assertTrue($this->repository->hasEventsBetween([1], self::FROM, self::TO));
    }

    public function testEmptyTableAnswersNo(): void
    {
        $this->assertFalse($this->repository->hasEventsBetween([1], self::FROM, self::TO));
    }

    public function testEventBeforeTheWindowDoesNotCount(): void
    {
        $this->wpdb->seedEvent(1, '2026-08-17 19:00:00', '2026-08-17 21:00:00');

        $this->assertFalse($this->repository->hasEventsBetween([1], self::FROM, self::TO));
    }

    public function testEventAfterTheWindowDoesNotCount(): void
    {
        $this->wpdb->seedEvent(1, '2027-01-01 19:00:00', '2027-01-01 21:00:00');

        $this->assertFalse($this->repository->hasEventsBetween([1], self::FROM, self::TO));
    }

    /**
     * Beide Grenzen gehoeren zum Fenster - abgefragt wird bei ChurchTools
     * derselbe Zeitraum einschliesslich seiner Raender.
     */
    public function testBothBoundsAreInclusive(): void
    {
        $this->wpdb->seedEvent(1, self::FROM, '2026-08-18 01:00:00');
        $this->assertTrue($this->repository->hasEventsBetween([1], self::FROM, self::TO));

        $this->wpdb = ctp_test_install_wpdb();
        $this->repository = new EventRepository();
        $this->wpdb->seedEvent(1, self::TO, self::TO);
        $this->assertTrue($this->repository->hasEventsBetween([1], self::FROM, self::TO));
    }

    /**
     * Gefragt wird nur nach den aktiven Kalendern - die Termine eines
     * abgewaehlten Kalenders duerfen eine leere Antwort nicht zur Stoerung
     * machen, denn nach ihnen hat niemand gefragt.
     */
    public function testEventsOfOtherCalendarsDoNotCount(): void
    {
        $this->wpdb->seedEvent(9, '2026-09-01 19:00:00', '2026-09-01 21:00:00');

        $this->assertFalse($this->repository->hasEventsBetween([1, 2], self::FROM, self::TO));
        $this->assertTrue($this->repository->hasEventsBetween([1, 9], self::FROM, self::TO));
    }

    /**
     * Erster Unterschied zu hasEventsFrom(), unteres Ende: Ein Termin, der
     * heute frueher am Tag zu Ende ging, zaehlt hier mit - deleteOrphans()
     * loescht ab $from, seine Untergrenze ist start_date. hasEventsFrom()
     * verlangt dagegen "end_date >= jetzt" (es beantwortet die Frage der
     * Schaltflaeche "Weitere Termine laden") und wuerde ihn uebersehen: Die
     * einzige gespeicherte Zeile im Fenster gaelte als nicht vorhanden, eine
     * leere Antwort damit als richtig - und geloescht wuerde ausgerechnet die
     * Zeile, wegen der der Schutz haette anspringen muessen.
     */
    public function testCountsAnEventThatAlreadyEndedToday(): void
    {
        $this->wpdb->seedEvent(1, '2026-08-18 08:00:00', '2026-08-18 09:00:00');

        $this->assertTrue($this->repository->hasEventsBetween([1], self::FROM, self::TO));
        $this->assertFalse($this->repository->hasEventsFrom([1], self::FROM));
    }

    /**
     * Zweiter Unterschied, oberes Ende: Wer den Sync-Zeitraum verkuerzt,
     * behaelt Zeilen jenseits des neuen Horizonts, bis sie vergangen sind.
     * hasEventsFrom() kennt keine Obergrenze und wuerde deshalb jede kuenftig
     * leere - und voellig berechtigte - Antwort fuer diesen kurzen Zeitraum zur
     * Stoerung machen, bis diese Zeilen ablaufen.
     */
    public function testIgnoresEventsBeyondAShortenedHorizon(): void
    {
        $this->wpdb->seedEvent(1, '2027-06-01 19:00:00', '2027-06-01 21:00:00');

        $this->assertFalse($this->repository->hasEventsBetween([1], self::FROM, self::TO));
        $this->assertTrue($this->repository->hasEventsFrom([1], self::FROM));
    }
}
