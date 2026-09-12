<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Sync\RoomLookup;
use ChurchToolsPlugin\Sync\SyncEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for mapOccurrence()'s field mapping against the real
 * ChurchTools /api/calendars/appointments response shape — see the class docblock
 * in SyncEngine.php for how that shape was verified against live data and differs
 * from what the OpenAPI schema originally suggested (appointment.base/calculated,
 * not appointment/calculatedDates). A previous version of this code silently
 * dropped every row because of that exact mismatch (see plan.md's bug log); these
 * tests exist so a regression there fails loudly instead.
 */
final class SyncEngineTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** Sekunden des Vorgabe-Intervalls "stuendlich". */
    private const INTERVAL = 3600;

    protected function setUp(): void
    {
        ctp_test_reset_options();
    }

    /**
     * getLastError() just reads back whatever run() persisted under
     * "ctp_last_sync_error" — run() itself isn't unit-tested here since it
     * constructs a real Client and hits the network (see the class docblock on why
     * this test suite avoids full WP/integration bootstrapping), but the read side
     * of that error round trip is pure and worth pinning down.
     */
    public function testGetLastErrorReturnsNullWhenNoErrorStored(): void
    {
        $this->assertNull(SyncEngine::getLastError());
    }

    public function testGetLastErrorReturnsPersistedError(): void
    {
        ctp_test_set_option('ctp_last_sync_error', [
            'time' => '2026-08-15 12:00:00',
            'message' => 'ChurchTools API error 401: No valid token',
        ]);

        $this->assertSame([
            'time' => '2026-08-15 12:00:00',
            'message' => 'ChurchTools API error 401: No valid token',
        ], SyncEngine::getLastError());
    }

    /**
     * Alles, was nicht die vereinbarte Form hat, gilt als "kein Fehler" statt
     * als halber - in der Option kann ein Wert aus einer aelteren Version, ein
     * teilweise eingespieltes Backup oder etwas von fremder Hand liegen. Ohne
     * diese Pruefung braeuchte jeder der drei Aufrufer sein eigenes ?? '',
     * und ein vergessenes waere eine PHP-Warnung mitten auf einer Admin-Seite.
     *
     * @dataProvider malformedStoredErrors
     *
     * @param mixed $stored
     */
    public function testMalformedStoredErrorIsReportedAsNoError($stored): void
    {
        ctp_test_set_option('ctp_last_sync_error', $stored);

        $this->assertNull(SyncEngine::getLastError());
    }

    public function malformedStoredErrors(): array
    {
        return [
            'kein Array' => ['irgendein String'],
            'leeres Array' => [[]],
            'ohne message' => [['time' => '2026-08-15 12:00:00']],
            'ohne time' => [['message' => 'Fehler']],
            'message ist ein Array' => [['time' => '2026-08-15 12:00:00', 'message' => ['Fehler']]],
            'time ist ein Array' => [['time' => [], 'message' => 'Fehler']],
        ];
    }

    /**
     * Die Gegenprobe zur Formpruefung: Ein Zeitstempel, der als Zahl in der
     * Option gelandet ist, ist noch ein brauchbarer Fehler - nur eben einer,
     * den die Aufrufer als Zeichenkette weiterreichen duerfen muessen.
     */
    public function testScalarValuesAreNormalisedToStrings(): void
    {
        ctp_test_set_option('ctp_last_sync_error', ['time' => 1_800_000_000, 'message' => 404]);

        $this->assertSame(['time' => '1800000000', 'message' => '404'], SyncEngine::getLastError());
    }

    /**
     * Der Fall, der ohne Schutz einen kompletten Jahreskalender leert:
     * HTTP 200, unerwarteter Body, Client::request() gibt [] zurueck ohne zu
     * werfen - und deleteOrphans() laesst bei leerer Keep-Liste seine
     * NOT-IN-Schutzbedingung weg.
     */
    public function testEmptyApiResponseWithStoredEventsIsTreatedAsFailure(): void
    {
        $this->assertTrue($this->looksLikeApiFailure([], true));
    }

    /**
     * Gegenprobe: Eine frische Installation ohne gespeicherte Termine bekommt
     * legitim nichts zurueck - das darf den Lauf nicht abbrechen, sonst kaeme
     * eine leere Instanz nie in Gang.
     */
    public function testEmptyApiResponseWithoutStoredEventsIsFine(): void
    {
        $this->assertFalse($this->looksLikeApiFailure([], false));
    }

    /**
     * Und ein Lauf, der Termine liefert, ist nie verdaechtig - auch dann nicht,
     * wenn er deutlich weniger liefert als gespeichert sind (ein wirklich
     * schrumpfender Kalender muss sich leeren duerfen).
     */
    public function testNonEmptyApiResponseIsNeverTreatedAsFailure(): void
    {
        $this->assertFalse($this->looksLikeApiFailure([['appointment' => []]], true));
    }

    /**
     * Der zweite leere Lauf in Folge blockiert noch - eine voruebergehende
     * Stoerung soll die gespeicherten Termine ueberleben.
     */
    public function testSecondConsecutiveEmptyResponseStillBlocks(): void
    {
        $this->assertTrue($this->looksLikeApiFailure([], true, 2, 2 * self::INTERVAL));
    }

    /**
     * Der dritte laesst die leere Antwort gelten. Ohne diesen Ausweg bliebe ein
     * wirklich geleerter Kalender fuer immer stehen: Die Fehlermeldung raeumt
     * nur ein erfolgreicher Lauf ab, und erfolgreich wird der Lauf nie, solange
     * die - korrekte - leere Antwort als Stoerung gilt.
     */
    public function testThirdConsecutiveEmptyResponseIsAllowedThrough(): void
    {
        $this->assertFalse($this->looksLikeApiFailure([], true, 3, 3 * self::INTERVAL));
    }

    /**
     * Und die Bedingung, ohne die der Ausweg ein Loch waere: Drei Klicks auf
     * "Jetzt synchronisieren" sind drei Laeufe in einer halben Minute -
     * ajaxRunSync() ruft SyncEngine::run() direkt auf. Geloescht wuerde dann
     * ausgerechnet, waehrend jemand wegen der Stoerung am Suchen ist. Die
     * Begruendung fuer das Nachgeben ist "offensichtlich keine voruebergehende
     * Stoerung", und das ist eine Aussage ueber Zeit, nicht ueber Klicks.
     */
    public function testThreeManualRunsInQuickSuccessionStillBlock(): void
    {
        $this->assertTrue($this->looksLikeApiFailure([], true, 3, 30));
    }

    /**
     * Genau auf der Grenze wird durchgelassen - gefordert ist die Zeit, die
     * drei planmaessige Laeufe brauchen, nicht mehr.
     */
    public function testExactlyTheRequiredSpreadIsAllowedThrough(): void
    {
        $this->assertFalse($this->looksLikeApiFailure([], true, 3, 2 * self::INTERVAL));
    }

    /**
     * Die Invariante, auf der die Zeitbedingung ueberhaupt steht: "since" ist
     * der *erste* leere Lauf und darf beim Hochzaehlen nicht mitwandern. Wuerde
     * es das, waere der Abstand immer null und die Bedingung wirkungslos.
     */
    public function testStreakKeepsTheTimestampOfTheFirstEmptyRun(): void
    {
        ctp_test_set_option('ctp_empty_sync_runs', ['runs' => 1, 'since' => self::NOW - 3 * self::INTERVAL]);

        $streak = $this->recordEmptyRun();

        $this->assertSame(2, $streak['runs']);
        $this->assertSame(self::NOW - 3 * self::INTERVAL, $streak['since']);
    }

    /**
     * Ein Lauf mit Terminen raeumt den Zaehler weg - nur *aufeinanderfolgende*
     * leere Antworten duerfen sich aufsummieren.
     */
    public function testASuccessfulRunClearsTheStreak(): void
    {
        ctp_test_set_option('ctp_empty_sync_runs', ['runs' => 2, 'since' => self::NOW]);

        $this->assertSame(0, $this->forgetEmptyRuns()['runs']);
        $this->assertNull(get_option('ctp_empty_sync_runs', null));
    }

    /**
     * Ein kaputter oder aelterer Optionswert faengt bei null an, statt beim
     * Hochzaehlen an einem fehlenden Schluessel zu scheitern.
     */
    public function testCorruptStoredStreakStartsOver(): void
    {
        ctp_test_set_option('ctp_empty_sync_runs', 'kaputt');

        $this->assertSame(1, $this->recordEmptyRun()['runs']);
    }

    private function recordEmptyRun(): array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'recordEmptyRun');

        return $method->invoke(null);
    }

    private function forgetEmptyRuns(): array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'forgetEmptyRuns');

        return $method->invoke(null);
    }

    private function looksLikeApiFailure(array $envelopes, bool $hasStored, int $runs = 1, int $spread = 0): bool
    {
        $method = new ReflectionMethod(SyncEngine::class, 'looksLikeApiFailure');

        return $method->invoke(
            null,
            $envelopes,
            $hasStored,
            ['runs' => $runs, 'since' => self::NOW - $spread],
            self::NOW,
            self::INTERVAL
        );
    }

    private function withRoom(array $row, RoomLookup $rooms, array $roomIdsAtChurch = []): array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'withRoom');

        return $method->invoke(null, $row, $rooms, $roomIdsAtChurch);
    }

    /**
     * Eine bestaetigte Buchung von „Saal 1" fuer den Termin aus envelope()
     * (ID 123) am 01.11.2026 - dieselbe Form wie in RoomLookupTest. 09:30 Zulu
     * ist 10:30 in Site-Zeit, derselbe Tag.
     */
    private function roomsWithSaal1(): RoomLookup
    {
        return RoomLookup::fromBookings([[
            'base' => ['appointmentId' => 123, 'statusId' => 2, 'resourceId' => 23, 'resource' => ['name' => 'Saal 1']],
            'calculated' => ['startDate' => '2026-11-01T09:30:00Z', 'endDate' => '2026-11-01T11:00:00Z'],
        ]], [23]);
    }

    private function row(string $location, string $startDate = '2026-11-01 10:30:00'): array
    {
        return [
            'ct_event_id' => 123,
            'start_date' => $startDate,
            'location' => $location,
            'location_at_church' => false,
        ];
    }

    /**
     * Wie roomsWithSaal1(), aber mit zwei Raeumen in der Zeile - der Fall der
     * Stellung „alle nennen".
     */
    private function roomsWithTwoRooms(): RoomLookup
    {
        return RoomLookup::fromBookings([
            [
                'base' => ['appointmentId' => 123, 'statusId' => 2, 'resourceId' => 23, 'resource' => ['name' => 'Saal 1']],
                'calculated' => ['startDate' => '2026-11-01T09:30:00Z', 'endDate' => '2026-11-01T11:00:00Z'],
            ],
            [
                'base' => ['appointmentId' => 123, 'statusId' => 2, 'resourceId' => 26, 'resource' => ['name' => 'Foyer']],
                'calculated' => ['startDate' => '2026-11-01T09:30:00Z', 'endDate' => '2026-11-01T11:00:00Z'],
            ],
        ], [23, 26], RoomLookup::MODE_ALL);
    }

    /**
     * Die Huelle, wie ChurchTools sie wirklich schickt: `base` und `calculated`
     * doppelt (oben als veraltete Kopie von `appointment.*`) und im Termin die
     * vier Aliase - jeweils mit der `@deprecated`-Angabe, die sie als solche
     * ausweist. Abgelesen an der Antwort vom 2026-09-11.
     */
    private function envelopeWithAliases(): array
    {
        $envelope = $this->envelope();
        $base = $envelope['appointment']['base'] + [
            'caption' => 'Gottesdienst',
            'note' => 'Predigt: Max Mustermann',
            'information' => 'Herzliche Einladung',
            'additionals' => [],
            'additions' => [],
            '@deprecated' => [
                'additions' => 'additionals',
                'caption' => 'title',
                'note' => 'subtitle',
                'information' => 'description',
            ],
        ];
        $envelope['appointment']['base'] = $base;

        return [
            'appointment' => $envelope['appointment'],
            '@deprecated' => ['base' => 'appointment.base', 'calculated' => 'appointment.calculated'],
            'base' => $base,
            'calculated' => $envelope['appointment']['calculated'],
        ];
    }

    /**
     * Die doppelte Huelle war fast die Haelfte von `raw_data` (49 %, gemessen
     * an 114 Zeilen) - ohne ein Byte Information.
     */
    public function testTheStoredAnswerDropsTheDeprecatedCopyOfTheEnvelope(): void
    {
        $raw = $this->mapOccurrence($this->envelopeWithAliases())['raw_data'];

        $this->assertArrayNotHasKey('base', $raw);
        $this->assertArrayNotHasKey('calculated', $raw);
        $this->assertSame(123, $raw['appointment']['base']['id']);
        $this->assertSame('2026-08-16T06:30:00Z', $raw['appointment']['calculated']['startDate']);
    }

    /**
     * Und im Termin die Aliase. `note` ist der, der in die Irre gefuehrt hat:
     * zehn Tage als offene Frage im Plan, ob sie oeffentlich gezeigt werden
     * duerfe - dabei ist sie der alte Name des Untertitels.
     */
    public function testTheStoredAnswerDropsTheAliasesInsideTheAppointment(): void
    {
        $base = $this->mapOccurrence($this->envelopeWithAliases())['raw_data']['appointment']['base'];

        foreach (['note', 'caption', 'information', 'additions'] as $alias) {
            $this->assertArrayNotHasKey($alias, $base, "Alias \"{$alias}\" steht noch in raw_data.");
        }

        $this->assertSame('Predigt: Max Mustermann', $base['subtitle']);
        $this->assertArrayHasKey('additionals', $base);
    }

    /**
     * Die Spalten kommen weiter aus der unveraenderten Huelle - bereinigt wird
     * nur, was gespeichert wird. Ohne diesen Test hiesse „raw_data ist
     * schlanker" womoeglich auch „der Untertitel ist weg".
     */
    public function testTheColumnsAreUnaffectedByTheCleanup(): void
    {
        $row = $this->mapOccurrence($this->envelopeWithAliases());

        $this->assertSame('Gottesdienst', $row['title']);
        $this->assertSame('Predigt: Max Mustermann', $row['subtitle']);
        $this->assertSame('Herzliche Einladung', $row['description']);
    }

    /**
     * Ein alter Schluessel faellt nur weg, wenn sein Ziel auch da ist. Nennt
     * ChurchTools eine Abbildung und liefert das neue Feld nicht mit, waere
     * das alte der einzige Traeger des Werts.
     */
    public function testAnAliasWithoutItsTargetStays(): void
    {
        $envelope = $this->envelope();
        unset($envelope['appointment']['base']['subtitle']);
        $envelope['appointment']['base']['note'] = 'Nur hier';
        $envelope['appointment']['base']['@deprecated'] = ['note' => 'subtitle'];

        $base = $this->mapOccurrence($envelope)['raw_data']['appointment']['base'];

        $this->assertSame('Nur hier', $base['note']);
    }

    /**
     * `@deprecated` selbst bleibt: ein paar Dutzend Bytes, die dem naechsten,
     * der in `raw_data` nachsieht, sagen, wohin `note` gegangen ist.
     */
    public function testTheAliasMapItselfStays(): void
    {
        $raw = $this->mapOccurrence($this->envelopeWithAliases())['raw_data'];

        $this->assertSame(['base' => 'appointment.base', 'calculated' => 'appointment.calculated'], $raw['@deprecated']);
        $this->assertSame('subtitle', $raw['appointment']['base']['@deprecated']['note']);
    }

    private function mapOccurrence(array $envelope): ?array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'mapOccurrence');

        return $method->invoke(null, $envelope);
    }

    private function envelope(array $overrides = []): array
    {
        $base = [
            'id' => 123,
            'title' => 'Gottesdienst',
            'subtitle' => 'Predigt: Max Mustermann',
            'description' => 'Herzliche Einladung',
            'allDay' => false,
            'calendar' => ['id' => 32],
            'image' => ['fileUrl' => 'https://musterkirche.church.tools/files/image.jpg'],
            'address' => [
                'name' => 'Gemeindehaus',
                'street' => 'Hauptstraße 1',
                'zip' => '75015',
                'city' => 'Bretten',
            ],
        ];

        $calculated = [
            'startDate' => '2026-08-16T06:30:00Z',
            'endDate' => '2026-08-16T08:00:00Z',
        ];

        return array_replace_recursive([
            'appointment' => [
                'base' => $base,
                'calculated' => $calculated,
            ],
        ], $overrides);
    }

    /**
     * ChurchTools' Option „nur fuer angemeldete Benutzer" (`isInternal`) ist
     * die einzige Stelle, an der die Gemeinde am Termin selbst sagt, dass er
     * nicht nach draussen soll. Wird sie ignoriert, veroeffentlicht ein
     * unbeaufsichtigter Cron-Lauf einen internen Termin - der Fehler faellt
     * niemandem auf, weil niemand hinsieht.
     */
    public function testSkipsAppointmentsMarkedInternal(): void
    {
        $envelope = $this->envelope(['appointment' => ['base' => ['isInternal' => true]]]);

        $this->assertNull($this->mapOccurrence($envelope));
    }

    /**
     * Die Gegenprobe zum Test darueber: Ein *nicht* gesetztes Haekchen darf
     * nichts aussortieren. ChurchTools liefert das Feld an jedem Termin mit,
     * in den Daten der Testinstanz durchgehend als `false` - eine zu strenge
     * Pruefung (isset statt empty) haette damit den gesamten Bestand entfernt.
     */
    public function testKeepsAppointmentsWithTheInternalFlagExplicitlyFalse(): void
    {
        $envelope = $this->envelope(['appointment' => ['base' => ['isInternal' => false]]]);

        $this->assertNotNull($this->mapOccurrence($envelope));
    }

    public function testMapsAllFieldsFromTheRealApiShape(): void
    {
        $row = $this->mapOccurrence($this->envelope());

        $this->assertNotNull($row);
        $this->assertSame(123, $row['ct_event_id']);
        $this->assertSame(32, $row['ct_calendar_id']);
        $this->assertSame('Gottesdienst', $row['title']);
        $this->assertSame('Predigt: Max Mustermann', $row['subtitle']);
        $this->assertSame('Herzliche Einladung', $row['description']);
        $this->assertFalse($row['all_day']);
        $this->assertSame('https://musterkirche.church.tools/files/image.jpg', $row['image_url']);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, 75015 Bretten', $row['location']);
    }

    /**
     * Die Adresse kommt als Objekt mit getrennten Feldern, nicht als fertige
     * Zeile - formatAddress() setzt sie zusammen. Der Zusatz (UI: "Zusatz")
     * benennt Gebaeude oder Halle und gehoert dazu: Strasse und PLZ findet eine
     * Karten-App, das Gebaeude nicht.
     */
    public function testPutsTheAdditionBetweenStreetAndCity(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => ['addition' => 'Haus B']]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, Haus B, 75015 Bretten', $row['location']);
    }

    /**
     * Ein Teilort ist haeufig der Name, unter dem Ortsfremde den Ort einordnen,
     * waehrend die politische Gemeinde in der PLZ-Zeile ihnen nichts sagt. Er
     * haengt deshalb an der Stadt statt ein eigenes Komma-Glied zu sein - das
     * ist zugleich die postalisch uebliche Schreibweise.
     */
    public function testAppendsTheDistrictToTheCity(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => ['district' => 'Ruit']]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, 75015 Bretten-Ruit', $row['location']);
    }

    /**
     * Steht der Teilort schon in der Stadt, darf er nicht ein zweites Mal
     * angehaengt werden - sonst entstuende "Bretten-Ruit-Ruit", sobald jemand
     * beide Felder gleich pflegt.
     */
    public function testDoesNotRepeatADistrictTheCityAlreadyNames(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => ['city' => 'Bretten-Ruit', 'district' => 'Ruit']]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, 75015 Bretten-Ruit', $row['location']);
    }

    /**
     * Ohne Stadt traegt der Teilort die Zeile allein, statt sie mit einem
     * fuehrenden Bindestrich zu beginnen.
     */
    public function testUsesTheDistrictAloneWhenTheCityIsMissing(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => ['city' => '', 'district' => 'Ruit']]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, 75015 Ruit', $row['location']);
    }

    /**
     * Zwei Felder der Antwort bleiben bewusst draussen: `country` liefert einen
     * Laendercode, und ein Code in einer Adresszeile ist schlechter als gar
     * nichts; `meetingAt` ist kein eigenes Feld, sondern laut dem
     * `@deprecated`-Verzeichnis derselben Antwort der Altname von `name`.
     */
    public function testLeavesOutTheCountryCodeAndTheDeprecatedMeetingAtAlias(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => [
                'country' => 'DE',
                'meetingAt' => 'Gemeindehaus',
            ]]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Gemeindehaus, Hauptstraße 1, 75015 Bretten', $row['location']);
    }

    /**
     * `base.address` ist ein Objekt - oder `null`, und das ist der haeufigere
     * Fall. Ein Termin ohne Ort bekommt eine leere Zeile, keinen Rest an
     * Trennzeichen.
     */
    public function testLeavesTheLocationEmptyWithoutAnAddress(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => null]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('', $row['location']);
    }

    /**
     * Eine auswärtige Adresse trägt Straße, Ort *und* Koordinaten - und die
     * sind dort am meisten wert, wo jemand einen fremden Ort sucht. Die
     * sichtbare Zeile bleibt davon unberührt, die Teile stehen daneben.
     */
    public function testAnAddressWithAStreetIsAlsoStoredInItsParts(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => [
                'name' => 'Freibad',
                'street' => 'Badstraße 1',
                'zip' => '75015',
                'city' => 'Bretten',
                'country' => 'DE',
                'latitude' => '49.0368',
                'longitude' => '8.7057',
            ]]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Freibad, Badstraße 1, 75015 Bretten', $row['location']);

        $parts = json_decode($row['location_data'], true);

        $this->assertSame('Badstraße 1', $parts['street']);
        $this->assertSame('49.0368', $parts['latitude']);
    }

    /**
     * Der häufigere Fall an der echten Instanz: Im Adressfeld steht nur ein
     * Name, und aus einem Namen eine Anschrift zu bauen hieße raten. Gemessen
     * am 2026-09-12: 10 von 113 Zeilen tragen eine Adresse, keine davon eine
     * Straße.
     */
    public function testAnAddressWithOnlyANameStoresNoParts(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            // Ausdrücklich leer statt weggelassen: envelope() mischt mit der
            // Vorgabe, ein Weglassen erbte also deren Straße.
            'appointment' => ['base' => ['address' => [
                'name' => 'Festsaal',
                'street' => '',
                'zip' => '',
                'city' => '',
            ]]],
        ]));

        $this->assertNotNull($row);
        $this->assertSame('Festsaal', $row['location']);
        $this->assertSame('', $row['location_data']);
    }

    /**
     * Koordinaten allein genügen: Sie verorten den Termin genauer als jede
     * Adresszeile, auch ohne Straße.
     */
    public function testCoordinatesAloneAreWorthStoring(): void
    {
        $row = $this->mapOccurrence($this->envelope([
            'appointment' => ['base' => ['address' => [
                'name' => 'Waldlichtung',
                'latitude' => '49.0368',
                'longitude' => '8.7057',
            ]]],
        ]));

        $this->assertNotNull($row);
        $this->assertNotSame('', $row['location_data']);
    }

    /**
     * Seit 2026-09-11 gilt die umgekehrte Reihenfolge zu 1.12.0: ein
     * gepflegter Ort schlaegt den gebuchten Raum. Ausschlaggebend war ein
     * Taufgottesdienst ausserhalb des eigenen Hauses mit einer versehentlich
     * gebuchten Ressource im eigenen Haus - die alte Regel haette dort den
     * Raum gezeigt statt der echten Adresse.
     */
    public function testAnAddressWinsOverABookedRoom(): void
    {
        $row = $this->withRoom($this->row('Freibad, Badstraße 1, 75015 Bretten'), $this->roomsWithSaal1());

        $this->assertSame('Freibad, Badstraße 1, 75015 Bretten', $row['location']);
    }

    /**
     * Die Gegenprobe: Steht kein Ort fest, traegt weiterhin der Raum - das
     * ist der Fall, der die Serien ohne eigene Adresse versorgt.
     */
    public function testARoomFillsTheLocationWhenNoAddressIsSet(): void
    {
        $row = $this->withRoom($this->row(''), $this->roomsWithSaal1());

        $this->assertSame('Saal 1', $row['location']);
    }

    /**
     * Die Buchung gilt nur fuer ihr eigenes Vorkommnis: Dieselbe Serie eine
     * Woche spaeter hat keinen Raum und keinen Ort, und die Zeile bleibt leer
     * statt eines geratenen Platzhalters.
     */
    public function testTheLocationStaysEmptyWithoutAddressOrRoom(): void
    {
        $row = $this->withRoom($this->row('', '2026-11-08 10:30:00'), $this->roomsWithSaal1());

        $this->assertSame('', $row['location']);
    }

    /**
     * Der Merker entscheidet, ob die Anschrift der Gemeinde in strukturierte
     * Daten und `.ics` darf. Er gilt nur fuer Zeilen, die ein gebuchter Raum
     * im Haus der Gemeinde stellt.
     */
    public function testARoomInTheChurchBuildingIsMarked(): void
    {
        $row = $this->withRoom($this->row(''), $this->roomsWithSaal1(), [23]);

        $this->assertSame('Saal 1', $row['location']);
        $this->assertTrue($row['location_at_church']);
    }

    /**
     * Die Gegenprobe: Ein Raum, den ChurchTools nicht im Gebaeude der
     * Gemeinde fuehrt, bekommt deren Anschrift nicht - sonst stuende an einem
     * Termin im Nebenhaus die Adresse des Haupthauses.
     */
    public function testARoomElsewhereIsNotMarked(): void
    {
        $row = $this->withRoom($this->row(''), $this->roomsWithSaal1(), [99]);

        $this->assertSame('Saal 1', $row['location']);
        $this->assertFalse($row['location_at_church']);
    }

    /**
     * Nennt die Zeile mehrere Raeume, muessen *alle* im Haus liegen. Eine
     * Anschrift, die nur fuer einen Teil der genannten Raeume gilt, waere
     * falsch und nicht nur unvollstaendig.
     */
    public function testSeveralRoomsCountOnlyIfEveryOneIsInTheBuilding(): void
    {
        $both = $this->withRoom($this->row(''), $this->roomsWithTwoRooms(), [23, 26]);
        $half = $this->withRoom($this->row(''), $this->roomsWithTwoRooms(), [23]);

        $this->assertSame('Saal 1, Foyer', $both['location']);
        $this->assertTrue($both['location_at_church']);
        $this->assertFalse($half['location_at_church']);
    }

    /**
     * Stellt die Adresse vom Termin die Zeile, sagt sie selbst, wo sie liegt -
     * die Anschrift der Gemeinde hat dort nichts zu suchen, auch wenn
     * nebenher ein Raum im Haus gebucht ist. Genau das war der Fall des
     * Taufgottesdienstes im Freibad.
     */
    public function testAnAddressLineNeverCarriesTheChurchAddress(): void
    {
        $row = $this->withRoom($this->row('Freibad, Badstraße 1, 75015 Bretten'), $this->roomsWithSaal1(), [23]);

        $this->assertSame('Freibad, Badstraße 1, 75015 Bretten', $row['location']);
        $this->assertFalse($row['location_at_church']);
    }

    /**
     * Ohne zugeordnete Raeume (kein Ort an der Ressource gepflegt, oder die
     * Gemeindeanschrift traegt keinen Namen) heisst die leere Liste „nicht
     * zuzuordnen" und nicht „passt schon".
     */
    public function testWithoutAKnownBuildingNothingIsMarked(): void
    {
        $row = $this->withRoom($this->row(''), $this->roomsWithSaal1(), []);

        $this->assertSame('Saal 1', $row['location']);
        $this->assertFalse($row['location_at_church']);
    }

    /**
     * ChurchTools returns Zulu/UTC timestamps; toMysqlDate() must convert them into
     * the site's configured timezone (Europe/Berlin here, see tests/bootstrap.php),
     * not just reformat the UTC value as-is.
     */
    public function testConvertsUtcTimestampsToSiteTimezone(): void
    {
        $row = $this->mapOccurrence($this->envelope());

        // 2026-08-16T06:30:00Z is during CEST (UTC+2) -> 08:30 local time.
        $this->assertSame('2026-08-16 08:30:00', $row['start_date']);
        $this->assertSame('2026-08-16 10:00:00', $row['end_date']);
    }

    public function testAllDayFlagIsCarriedOver(): void
    {
        $row = $this->mapOccurrence($this->envelope(['appointment' => ['base' => ['allDay' => true]]]));

        $this->assertTrue($row['all_day']);
    }

    public function testReturnsNullWhenEventIdIsMissing(): void
    {
        $envelope = $this->envelope();
        unset($envelope['appointment']['base']['id']);

        $this->assertNull($this->mapOccurrence($envelope));
    }

    public function testReturnsNullWhenCalendarIdIsMissing(): void
    {
        $envelope = $this->envelope();
        unset($envelope['appointment']['base']['calendar']);

        $this->assertNull($this->mapOccurrence($envelope));
    }

    public function testReturnsNullWhenCalculatedDatesAreMissing(): void
    {
        $envelope = $this->envelope();
        unset($envelope['appointment']['calculated']['startDate']);

        $this->assertNull($this->mapOccurrence($envelope));

        $envelope = $this->envelope();
        unset($envelope['appointment']['calculated']['endDate']);

        $this->assertNull($this->mapOccurrence($envelope));
    }

    public function testReturnsNullForCompletelyEmptyEnvelope(): void
    {
        $this->assertNull($this->mapOccurrence([]));
    }

    public function testMissingImageAndAddressBecomeEmptyStrings(): void
    {
        $envelope = $this->envelope();
        unset($envelope['appointment']['base']['image']);
        unset($envelope['appointment']['base']['address']);

        $row = $this->mapOccurrence($envelope);

        $this->assertSame('', $row['image_url']);
        $this->assertSame('', $row['location']);
    }

    /**
     * The full appointment envelope is stored in raw_data — used e.g. for
     * future debugging/reprocessing without needing to re-fetch from ChurchTools.
     * Verbatim except for what ChurchTools itself marks as `@deprecated` (see
     * the tests above): an envelope without such markers, like this one, comes
     * back byte for byte.
     */
    public function testRawDataIsTheEntireEnvelopeWithoutAliases(): void
    {
        $envelope = $this->envelope();

        $this->assertSame($envelope, $this->mapOccurrence($envelope)['raw_data']);
    }
}
