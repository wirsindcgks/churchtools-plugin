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
    private function cacheKey(array $calendarIds, int $limit): string
    {
        $method = new ReflectionMethod(EventQueryCache::class, 'cacheKey');
        $method->setAccessible(true);

        return $method->invoke(null, $calendarIds, $limit);
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
}
