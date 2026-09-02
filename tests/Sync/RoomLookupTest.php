<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Sync\RoomLookup;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Regel ab, die den Raum aus den Buchungen zieht: genau ein
 * angehakter, bestaetigt gebuchter Raum wird gezeigt, alles andere schweigt.
 * Die Huelle der Buchungsantwort ist dieselbe wie bei Terminen (`base` fuer
 * die Serie, `calculated` fuer das Vorkommnis), verifiziert gegen die
 * produktive Instanz.
 */
final class RoomLookupTest extends TestCase
{
    public function testReturnsTheRoomWhenExactlyOneEnabledResourceIsBooked(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
        ], [23, 24]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Der Kern der Regel: Wo mehrere angehakte Raeume gebucht sind, gibt es
     * keine Ortsangabe, sondern eine Inventarliste - dann schweigt das Plugin,
     * statt einen davon zu behaupten.
     */
    public function testStaysSilentWhenTwoEnabledResourcesAreBooked(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 24, 'resource' => ['name' => 'Foyer']]),
        ], [23, 24]);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Genau dafuer ist die Auswahl da: Ein Termin, der einen grossen und drei
     * Nebenraeume bucht, wird durch das Abwaehlen der Nebenraeume wieder
     * eindeutig.
     */
    public function testTheSelectionMakesABundleUnambiguousAgain(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 26, 'resource' => ['name' => 'Seminarraum 1']]),
            $this->booking(['resourceId' => 30, 'resource' => ['name' => 'Seminarraum 2']]),
        ], [23]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Derselbe Raum in zwei Zeitfenstern desselben Tages (Aufbau und
     * Veranstaltung) ist ein Raum, keine Mehrdeutigkeit.
     */
    public function testTheSameRoomBookedTwiceStaysOneRoom(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']], '2026-11-01T06:00:00Z'),
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']], '2026-11-01T09:30:00Z'),
        ], [23]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    public function testIgnoresBookingsThatAreOnlyRequested(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'statusId' => 1, 'resource' => ['name' => 'Grosser Saal']]),
        ], [23]);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    public function testIgnoresResourcesThatAreNotEnabled(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 99, 'resource' => ['name' => 'Technikschrank']]),
        ], [23]);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    public function testWithoutAnySelectionNothingIsLookedUpAtAll(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
        ], []);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Buchungen kommen in Zulu-Zeit, die Termintabelle fuehrt Site-Zeit
     * (Europe/Berlin, siehe tests/bootstrap.php). Eine Buchung um 23:30 Uhr UTC
     * gehoert damit zum Folgetag - ohne Umrechnung liefe die Zuordnung hier auf
     * den falschen Tag.
     */
    public function testMatchesOnTheLocalDateNotTheUtcOne(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']], '2026-11-01T23:30:00Z'),
        ], [23]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-02 00:30:00'));
        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 23:30:00'));
    }

    /**
     * Ein Raum ist regelmaessig frueher gebucht als der Termin beginnt.
     * Zusammengefuehrt wird deshalb ueber das Datum, nicht die Uhrzeit.
     */
    public function testMatchesEvenWhenTheBookingStartsEarlierThanTheEvent(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']], '2026-11-01T05:00:00Z'),
        ], [23]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    public function testSkipsBookingsWithoutAnAppointmentOrName(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'appointmentId' => 0, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 23, 'appointmentId' => 4711, 'resource' => ['name' => '  ']]),
        ], [23]);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
        $this->assertSame('', $lookup->forOccurrence(4711, '2026-11-01 10:30:00'));
    }

    /**
     * Eine Antwort, die nicht die erwartete Form hat, darf den Sync nicht
     * anhalten - sie liefert eben keinen Raum.
     */
    public function testSurvivesMalformedEnvelopes(): void
    {
        $lookup = RoomLookup::fromBookings([
            [],
            ['base' => 'kein Array'],
            ['base' => ['resourceId' => 23], 'calculated' => ['startDate' => 'kein Datum']],
        ], [23]);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Der grosszuegige Standard: Ein angehakter Raum genuegt, auch wenn daneben
     * nicht angehakte belegt sind. An den echten Daten ist das der Unterschied
     * zwischen 81 und 50 Terminen mit Ortsangabe.
     */
    public function testByDefaultUnselectedRoomsBookedAlongsideDoNotMatter(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 26, 'resource' => ['name' => 'Seminarraum 1']]),
        ], [23]);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Der strenge Modus zaehlt jede Buchung mit, auch die nicht angehakter
     * Raeume: Eine Veranstaltung ueber mehrere Raeume bekommt dann gar keine
     * Ortsangabe, statt unter dem Namen eines von ihnen zu erscheinen.
     */
    public function testInExclusiveModeAnyOtherBookedRoomSilencesTheLine(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 26, 'resource' => ['name' => 'Seminarraum 1']]),
        ], [23], true);

        $this->assertSame('', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Der strenge Modus darf den eindeutigen Fall nicht mitnehmen - sonst waere
     * er nicht streng, sondern kaputt.
     */
    public function testExclusiveModeStillShowsTheOnlyBookedRoom(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
        ], [23], true);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    /**
     * Eine nur angefragte Buchung ist keine Belegung und darf auch im strengen
     * Modus nichts verhindern.
     */
    public function testExclusiveModeIgnoresMerelyRequestedBookingsOfOtherRooms(): void
    {
        $lookup = RoomLookup::fromBookings([
            $this->booking(['resourceId' => 23, 'resource' => ['name' => 'Grosser Saal']]),
            $this->booking(['resourceId' => 26, 'statusId' => 1, 'resource' => ['name' => 'Seminarraum 1']]),
        ], [23], true);

        $this->assertSame('Grosser Saal', $lookup->forOccurrence(6739, '2026-11-01 10:30:00'));
    }

    private function booking(array $base = [], string $startDate = '2026-11-01T09:30:00Z'): array
    {
        return [
            'base' => array_replace([
                'appointmentId' => 6739,
                'statusId' => 2,
                'resourceId' => 23,
                'resource' => ['name' => 'Grosser Saal'],
            ], $base),
            'calculated' => ['startDate' => $startDate, 'endDate' => $startDate],
        ];
    }
}
