<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Db\EventRepository;

/**
 * Wraps the EventRepository read queries in a short-lived transient cache so that
 * pages using [ctp_events] don't hit the DB on every request. WP's transient API
 * already uses a persistent object cache (Redis/Memcached) when the site has one
 * configured and falls back to the options table otherwise, so this stays a thin
 * wrapper rather than a custom caching layer.
 */
final class EventQueryCache
{
    private const TTL = 10 * MINUTE_IN_SECONDS;
    private const VERSION_OPTION = 'ctp_events_cache_version';

    /**
     * One page of the month-window paging (see EventWindow). The window bounds
     * are part of the cache key, so every page caches independently — and since
     * the bounds only move when the calendar month rolls over, the keys stay
     * stable between requests instead of shifting continuously.
     */
    public static function findInWindow(
        array $calendarIds,
        ?string $startFrom,
        ?string $startBefore,
        int $limit,
        int $offset = 0
    ): array {
        $key = self::cacheKey($calendarIds, $limit, $startFrom, $startBefore, 'events', $offset);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $events = (new EventRepository())->findInWindow($calendarIds, $startFrom, $startBefore, $limit, $offset);
        set_transient($key, $events, self::TTL);

        return $events;
    }

    public static function findUpcoming(array $calendarIds, int $limit): array
    {
        return self::findInWindow($calendarIds, null, null, $limit);
    }

    /**
     * Cached counterpart of EventRepository::hasEventsFrom() — the "is there
     * another page?" check runs on every rendered list, so it gets the same
     * treatment as the event query itself. Stored as an int rather than a bool
     * because get_transient() returns false for "not cached", which would be
     * indistinguishable from a cached `false`.
     */
    public static function hasEventsFrom(array $calendarIds, string $startFrom): bool
    {
        $key = self::cacheKey($calendarIds, 0, $startFrom, null, 'events_more');
        $cached = get_transient($key);

        if (is_numeric($cached)) {
            return (int) $cached === 1;
        }

        $hasMore = (new EventRepository())->hasEventsFrom($calendarIds, $startFrom);
        set_transient($key, $hasMore ? 1 : 0, self::TTL);

        return $hasMore;
    }

    /**
     * Called by SyncEngine::run() after a successful sync. Bumps a version counter
     * rather than deleting transients directly: the read methods above are called
     * with a different calendar_ids/limit/window combination per shortcode/block/
     * WPBakery instance and per page, so there's no fixed, enumerable set of cache
     * keys to delete — the old-version entries are simply never read again and
     * expire on their own TTL.
     */
    public static function flush(): void
    {
        update_option(self::VERSION_OPTION, (int) get_option(self::VERSION_OPTION, 0) + 1, false);
    }

    private static function cacheKey(
        array $calendarIds,
        int $limit,
        ?string $startFrom = null,
        ?string $startBefore = null,
        string $prefix = 'events',
        int $offset = 0
    ): string {
        $calendarIds = array_map('intval', $calendarIds);
        sort($calendarIds);

        $version = (int) get_option(self::VERSION_OPTION, 0);
        $parts = implode(',', $calendarIds) . '|' . $limit . '|' . ($startFrom ?? '')
            . '|' . ($startBefore ?? '') . '|' . $offset;

        return 'ctp_' . $prefix . '_' . $version . '_' . md5($parts);
    }
}
