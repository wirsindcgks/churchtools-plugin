<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Db\EventRepository;

/**
 * Wraps EventRepository::findUpcoming() in a short-lived transient cache so that
 * pages using [ctp_events] don't hit the DB on every request. WP's transient API
 * already uses a persistent object cache (Redis/Memcached) when the site has one
 * configured and falls back to the options table otherwise, so this stays a thin
 * wrapper rather than a custom caching layer.
 */
final class EventQueryCache
{
    private const TTL = 10 * MINUTE_IN_SECONDS;
    private const VERSION_OPTION = 'ctp_events_cache_version';

    public static function findUpcoming(array $calendarIds, int $limit): array
    {
        $key = self::cacheKey($calendarIds, $limit);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $events = (new EventRepository())->findUpcoming($calendarIds, $limit);
        set_transient($key, $events, self::TTL);

        return $events;
    }

    /**
     * Called by SyncEngine::run() after a successful sync. Bumps a version counter
     * rather than deleting transients directly: findUpcoming() is called with a
     * different calendar_ids/limit combination per shortcode/block/WPBakery
     * instance, so there's no fixed, enumerable set of cache keys to delete — the
     * old-version entries are simply never read again and expire on their own TTL.
     */
    public static function flush(): void
    {
        update_option(self::VERSION_OPTION, (int) get_option(self::VERSION_OPTION, 0) + 1, false);
    }

    private static function cacheKey(array $calendarIds, int $limit): string
    {
        $calendarIds = array_map('intval', $calendarIds);
        sort($calendarIds);

        $version = (int) get_option(self::VERSION_OPTION, 0);

        return 'ctp_events_' . $version . '_' . md5(implode(',', $calendarIds) . '|' . $limit);
    }
}
