<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\Timeframe;
use PHPUnit\Framework\TestCase;

/**
 * Die Grenzen hinter den Zeitraum-Schaltflächen des Eventfinders. Sie
 * entscheiden, welche Termine ein Besucher unter „Diese Woche“ überhaupt zu
 * sehen bekommt, und ihre Fehler sind Grenzfehler: ein Tag zu früh, ein Tag zu
 * spät, oder der Sonntag, der in der einen Zählweise das Ende und in der
 * anderen der Anfang der Woche ist.
 *
 * Jeder Test gibt „jetzt“ selbst vor - eine Zeitraumberechnung, die nur an dem
 * Wochentag stimmt, an dem die Testsuite gerade läuft, wäre keine.
 */
final class TimeframeTest extends TestCase
{
    /** Ein Mittwoch, mitten in der Woche und mitten im Monat. */
    private const WEDNESDAY = '2026-08-19 14:30:00';

    public function testAnytimeHasNoBounds(): void
    {
        $this->assertNull(Timeframe::bounds('', self::WEDNESDAY));
    }

    /**
     * Der Endpunkt reicht unbekannte Werte durch, statt sie abzuweisen (siehe
     * EventsEndpoint::handle()) - hier landen sie, und „keine Grenzen“ ist die
     * einzige Antwort, die dabei nichts unterschlägt.
     */
    public function testUnknownKeyHasNoBounds(): void
    {
        $this->assertNull(Timeframe::bounds('naechstes-jahr', self::WEDNESDAY));
    }

    /**
     * Untergrenze ist heute Mitternacht, nicht „jetzt“: ein Termin, der heute
     * um 10 Uhr begonnen hat und bis 22 Uhr läuft, gehört noch in diese Woche.
     * Aus der Liste fällt er trotzdem, sobald er vorbei ist - dafür sorgt das
     * end_date >= jetzt der Abfrage, nicht diese Grenze.
     */
    public function testThisWeekStartsTodayAtMidnight(): void
    {
        $bounds = Timeframe::bounds('week', self::WEDNESDAY);

        $this->assertSame('2026-08-19 00:00:00', $bounds['from']);
    }

    /**
     * Die Obergrenze ist halboffen: Mitternacht des Folgetags, damit ein
     * Termin, der am Sonntag um 20 Uhr beginnt, noch dazugehört.
     */
    public function testThisWeekEndsAfterSunday(): void
    {
        $bounds = Timeframe::bounds('week', self::WEDNESDAY);

        $this->assertSame('2026-08-24 00:00:00', $bounds['before']);
    }

    /**
     * Der Sonntag ist der Fall, an dem sich die beiden Zählweisen
     * unterscheiden: In JavaScript ist er Tag 0 und damit rechnerisch der
     * *Anfang* der Woche, im ISO-Kalender Tag 7 und ihr Ende. Fiele er hier auf
     * die JavaScript-Seite, wäre „Diese Woche“ am Sonntag eine Woche zu lang.
     */
    public function testThisWeekOnSundayEndsThatSameDay(): void
    {
        $bounds = Timeframe::bounds('week', '2026-08-23 09:00:00');

        $this->assertSame('2026-08-23 00:00:00', $bounds['from']);
        $this->assertSame('2026-08-24 00:00:00', $bounds['before']);
    }

    public function testThisWeekendIsSaturdayAndSunday(): void
    {
        $bounds = Timeframe::bounds('weekend', self::WEDNESDAY);

        $this->assertSame('2026-08-22 00:00:00', $bounds['from']);
        $this->assertSame('2026-08-24 00:00:00', $bounds['before']);
    }

    /**
     * Am Wochenende selbst rückt die Untergrenze auf heute vor - sonst stünden
     * unter „Dieses Wochenende“ am Sonntag noch die Termine vom Samstag.
     */
    public function testThisWeekendOnSundayNoLongerIncludesSaturday(): void
    {
        $bounds = Timeframe::bounds('weekend', '2026-08-23 09:00:00');

        $this->assertSame('2026-08-23 00:00:00', $bounds['from']);
        $this->assertSame('2026-08-24 00:00:00', $bounds['before']);
    }

    public function testThisMonthRunsToTheFirstOfTheNext(): void
    {
        $bounds = Timeframe::bounds('month', self::WEDNESDAY);

        $this->assertSame('2026-08-19 00:00:00', $bounds['from']);
        $this->assertSame('2026-09-01 00:00:00', $bounds['before']);
    }

    /** Der Jahreswechsel ist der Monatswechsel, bei dem sich auch die Jahreszahl ändert. */
    public function testThisMonthCrossesTheYearBoundary(): void
    {
        $bounds = Timeframe::bounds('month', '2026-12-30 20:00:00');

        $this->assertSame('2027-01-01 00:00:00', $bounds['before']);
    }

    /**
     * Am letzten Tag des Monats bleibt genau dieser eine Tag übrig - eine
     * Spanne von einem Tag, keine leere.
     */
    public function testThisMonthOnItsLastDayKeepsThatDay(): void
    {
        $bounds = Timeframe::bounds('month', '2026-08-31 08:00:00');

        $this->assertSame('2026-08-31 00:00:00', $bounds['from']);
        $this->assertSame('2026-09-01 00:00:00', $bounds['before']);
    }
}
