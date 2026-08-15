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
            'image' => ['fileUrl' => 'https://cg-ks.church.tools/files/image.jpg'],
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
        $this->assertSame('https://cg-ks.church.tools/files/image.jpg', $row['image_url']);
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
