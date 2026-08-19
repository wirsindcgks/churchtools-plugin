<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventQueryCache;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EventQueryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_transients();
    }

    /**
     * cacheKey() is private — reflection over widening its visibility just for
     * tests, same pattern as SettingsPageTest::sanitizeInstance().
     */
    private function cacheKey(
        array $calendarIds,
        int $limit,
        ?string $startFrom = null,
        ?string $startBefore = null,
        string $prefix = 'events'
    ): string {
        $method = new ReflectionMethod(EventQueryCache::class, 'cacheKey');
        $method->setAccessible(true);

        return $method->invoke(null, $calendarIds, $limit, $startFrom, $startBefore, $prefix);
    }

    public function testCacheKeyIsOrderIndependent(): void
    {
        $this->assertSame($this->cacheKey([1, 2], 10), $this->cacheKey([2, 1], 10));
    }

    public function testCacheKeyDiffersByLimit(): void
    {
        $this->assertNotSame($this->cacheKey([1, 2], 10), $this->cacheKey([1, 2], 20));
    }

    public function testCacheKeyDiffersByCalendarIds(): void
    {
        $this->assertNotSame($this->cacheKey([1, 2], 10), $this->cacheKey([1, 3], 10));
    }

    public function testFlushChangesCacheKey(): void
    {
        $before = $this->cacheKey([1, 2], 10);
        EventQueryCache::flush();

        $this->assertNotSame($before, $this->cacheKey([1, 2], 10));
    }

    /**
     * Pre-seeding the transient at the key findUpcoming() would compute itself and
     * asserting the exact same array comes back verifies the cache-hit branch is
     * actually taken. If it weren't (e.g. a key mismatch), findUpcoming() would fall
     * through to `new EventRepository()`, which needs a real $wpdb this bootstrap
     * deliberately doesn't stub (see bootstrap.php's docblock) — that would fatal
     * instead of silently passing, so this test can't accidentally pass for the
     * wrong reason.
     */
    public function testFindUpcomingReturnsCachedValueWithoutTouchingTheRepository(): void
    {
        $sentinel = [['id' => 1, 'title' => 'Cached Event']];
        set_transient($this->cacheKey([1, 2], 10), $sentinel);

        $this->assertSame($sentinel, EventQueryCache::findUpcoming([2, 1], 10));
    }

    /**
     * Each page of the month-based paging has to cache separately, or the
     * second "load more" click would be served page one's events.
     */
    public function testCacheKeyDiffersByWindowBounds(): void
    {
        $firstPage = $this->cacheKey([1], 0, null, '2026-10-01 00:00:00');
        $secondPage = $this->cacheKey([1], 0, '2026-10-01 00:00:00', '2026-12-01 00:00:00');

        $this->assertNotSame($firstPage, $secondPage);
        $this->assertNotSame($firstPage, $this->cacheKey([1], 0, null, '2026-11-01 00:00:00'));
    }

    /**
     * The "is there another page?" flag is stored under its own prefix — it
     * shares calendar IDs and a bound with the event query, so without the
     * prefix the two would be one cache entry overwriting each other.
     */
    public function testHasMoreFlagUsesItsOwnKeyspace(): void
    {
        $events = $this->cacheKey([1], 0, '2026-10-01 00:00:00', null);
        $hasMore = $this->cacheKey([1], 0, '2026-10-01 00:00:00', null, 'events_more');

        $this->assertNotSame($events, $hasMore);
    }

    /**
     * A cached "no further pages" is a legitimate value, but get_transient()
     * signals "nothing cached" with false too — hence the int encoding, which
     * this asserts actually round-trips instead of being read as a miss.
     */
    public function testCachedNegativeHasMoreFlagIsReturnedAsFalse(): void
    {
        set_transient($this->cacheKey([1], 0, '2026-10-01 00:00:00', null, 'events_more'), 0);

        $this->assertFalse(EventQueryCache::hasEventsFrom([1], '2026-10-01 00:00:00'));
    }

    public function testCachedPositiveHasMoreFlagIsReturnedAsTrue(): void
    {
        set_transient($this->cacheKey([1], 0, '2026-10-01 00:00:00', null, 'events_more'), 1);

        $this->assertTrue(EventQueryCache::hasEventsFrom([1], '2026-10-01 00:00:00'));
    }

    /**
     * findMatching() hangs the search term into the key as $extra. Without it,
     * "Taufe" and "Konzert" over the same calendars and the same timeframe
     * would be one entry — and the second visitor would be served the first
     * one's hits.
     */
    public function testCacheKeyDiffersBySearchTerm(): void
    {
        $taufe = $this->matchKey([1], null, null, md5('Taufe'));
        $konzert = $this->matchKey([1], null, null, md5('Konzert'));

        $this->assertNotSame($taufe, $konzert);
    }

    /**
     * The counterpart: same term, different timeframe. "Konzert diese Woche"
     * and "Konzert diesen Monat" are different questions.
     */
    public function testCacheKeyDiffersByTimeframeBoundsForTheSameTerm(): void
    {
        $term = md5('Konzert');
        $thisWeek = $this->matchKey([1], '2026-08-19 00:00:00', '2026-08-24 00:00:00', $term);
        $thisMonth = $this->matchKey([1], '2026-08-19 00:00:00', '2026-09-01 00:00:00', $term);

        $this->assertNotSame($thisWeek, $thisMonth);
        $this->assertNotSame($thisMonth, $this->matchKey([1], null, null, $term));
    }

    /**
     * A hashed search term must not collide with the plain windowed entry for
     * the same calendars and bounds — they live under different prefixes, but
     * only the prefix separates them.
     */
    public function testSearchKeyDoesNotCollideWithThePagedEntry(): void
    {
        $paged = $this->cacheKey([1], 100, '2026-08-19 00:00:00', '2026-09-01 00:00:00');

        $this->assertNotSame($paged, $this->matchKey([1], '2026-08-19 00:00:00', '2026-09-01 00:00:00', md5('')));
    }

    /**
     * The toolbar's calendar list is legitimately empty on an instance whose
     * calendars have nothing coming up — and get_transient() reports a cache
     * miss with `false`, which an empty array would be indistinguishable from.
     * Hence the wrapper, which this asserts actually round-trips: without it,
     * every request would re-run the query and this would fall through to a
     * repository the bootstrap gives no $wpdb (a fatal, not a silent pass).
     */
    public function testCachedEmptyCalendarListIsReturnedAsEmptyRatherThanRefetched(): void
    {
        set_transient($this->cacheKey([1, 2], 0, null, null, 'events_calendars'), ['ids' => []]);

        $this->assertSame([], EventQueryCache::calendarIdsWithUpcoming([2, 1]));
    }

    public function testCachedCalendarListIsReturnedAsIs(): void
    {
        set_transient($this->cacheKey([1, 2], 0, null, null, 'events_calendars'), ['ids' => [2]]);

        $this->assertSame([2], EventQueryCache::calendarIdsWithUpcoming([1, 2]));
    }

    /** The key findMatching() computes, with its own prefix and limit. */
    private function matchKey(array $calendarIds, ?string $startFrom, ?string $startBefore, string $extra): string
    {
        $method = new ReflectionMethod(EventQueryCache::class, 'cacheKey');
        $method->setAccessible(true);

        return $method->invoke(null, $calendarIds, 100, $startFrom, $startBefore, 'events_search', 0, $extra);
    }
}
