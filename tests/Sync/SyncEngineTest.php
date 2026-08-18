<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

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
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function forgetEmptyRuns(): array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'forgetEmptyRuns');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function looksLikeApiFailure(array $envelopes, bool $hasStored, int $runs = 1, int $spread = 0): bool
    {
        $method = new ReflectionMethod(SyncEngine::class, 'looksLikeApiFailure');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            $envelopes,
            $hasStored,
            ['runs' => $runs, 'since' => self::NOW - $spread],
            self::NOW,
            self::INTERVAL
        );
    }

    private function mapOccurrence(array $envelope): ?array
    {
        $method = new ReflectionMethod(SyncEngine::class, 'mapOccurrence');
        $method->setAccessible(true);

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
     * The full appointment envelope is stored verbatim in raw_data — used e.g. for
     * future debugging/reprocessing without needing to re-fetch from ChurchTools.
     */
    public function testRawDataIsTheEntireEnvelope(): void
    {
        $envelope = $this->envelope();

        $this->assertSame($envelope, $this->mapOccurrence($envelope)['raw_data']);
    }
}
