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
     * Cached counterpart of EventRepository::calendarIdsWithUpcoming() — the
     * toolbar asks it on every rendered list, exactly like the "is there
     * another page?" check above, so it gets the same treatment.
     *
     * Wrapped in an array before storing (and unwrapped on read) because the
     * honest answer can be the empty list, which get_transient() would hand
     * back as `false`, i.e. as a cache miss, on every single request.
     *
     * @param int[] $calendarIds
     *
     * @return int[]
     */
    public static function calendarIdsWithUpcoming(array $calendarIds): array
    {
        $key = self::cacheKey($calendarIds, 0, null, null, 'events_calendars');
        $cached = get_transient($key);

        if (is_array($cached) && array_key_exists('ids', $cached)) {
            return $cached['ids'];
        }

        $ids = (new EventRepository())->calendarIdsWithUpcoming($calendarIds);
        set_transient($key, ['ids' => $ids], self::TTL);

        return $ids;
    }

    /**
     * Cached counterpart of the "answer a toolbar question completely" query:
     * the frontend search across the whole sync horizon, the eventfinder's
     * timeframe ranges, or both at once. Search terms are unbounded user input,
     * so they enter the key as a hash — bounded in practice by how many
     * distinct things visitors actually type, and each entry expires on the
     * same short TTL as everything else here. Without a cache this would be an
     * uncapped public endpoint running a leading-wildcard LIKE on every
     * keystroke-triggered request.
     */
    public static function findMatching(
        array $calendarIds,
        string $search,
        ?string $startFrom,
        ?string $startBefore,
        int $limit
    ): array {
        $key = self::cacheKey($calendarIds, $limit, $startFrom, $startBefore, 'events_search', 0, md5($search));
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $events = (new EventRepository())->findInWindow($calendarIds, $startFrom, $startBefore, $limit, 0, $search);
        set_transient($key, $events, self::TTL);

        return $events;
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

    /**
     * $extra carries whatever a single caller adds on top of the shared
     * calendar/limit/window identity — today only findMatching()'s hashed
     * search term, which must not collide with the same window's unfiltered
     * entry.
     */
    private static function cacheKey(
        array $calendarIds,
        int $limit,
        ?string $startFrom = null,
        ?string $startBefore = null,
        string $prefix = 'events',
        int $offset = 0,
        string $extra = ''
    ): string {
        $calendarIds = array_map('intval', $calendarIds);
        sort($calendarIds);

        $version = (int) get_option(self::VERSION_OPTION, 0);
        $parts = implode(',', $calendarIds) . '|' . $limit . '|' . ($startFrom ?? '')
            . '|' . ($startBefore ?? '') . '|' . $offset . '|' . $extra;

        return 'ctp_' . $prefix . '_' . $version . '_' . md5($parts);
    }
}
