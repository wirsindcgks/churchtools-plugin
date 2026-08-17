<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Resolves a requested cursor into the events to render plus the cursor for the
 * step after it — the single place that knows how "load more" advances.
 *
 * The cursor is a (page, offset) pair rather than a plain page index, because
 * the two ways of limiting a step have to compose:
 *   - The *window* (page) is the primary unit: two calendar months at a time,
 *     see EventWindow.
 *   - An optional *limit* caps how many events one step may return. Without an
 *     offset, a window holding more events than the cap would lose the
 *     remainder outright — the next click would jump to the following window
 *     and silently skip whatever didn't fit.
 * Offset is therefore only ever non-zero while a capped window is still being
 * worked through, and resets to 0 as soon as the window advances.
 *
 * Beyond that it skips forward over *empty* windows. A parish with nothing
 * scheduled for the next two months but a full calendar in autumn would
 * otherwise render an empty list with a "load more" button underneath, and each
 * click would advance by two silent months. Skipping means both the first
 * render and every appended page always carry actual events — at the price of
 * page 0 sometimes covering a later span than "the next two months" literally
 * implies.
 */
final class EventPager
{
    /**
     * Cap on how many consecutive empty windows one request will skip. At the
     * default two months per page that's two years of look-ahead in a single
     * request — well past any realistic sync_days_ahead, so in practice the
     * "nothing left at all" branch ends the loop long before this does. It only
     * exists so a pathological data set can't turn one request into an
     * unbounded query loop; the caller still gets a cursor in that case, so a
     * further click resumes where this one stopped.
     */
    private const MAX_SKIPPED_WINDOWS = 12;

    /**
     * @return array{events: array, page: int, next_page: int|null, next_offset: int}
     */
    public static function load(
        array $calendarIds,
        int $page,
        int $months,
        int $limit,
        int $offset = 0
    ): array {
        $window = EventWindow::forPage($page, $months);
        $offset = max(0, $offset);

        for ($attempt = 0; $attempt <= self::MAX_SKIPPED_WINDOWS; $attempt++) {
            $events = EventQueryCache::findInWindow(
                $calendarIds,
                $window->startFrom(),
                $window->startBefore(),
                $limit,
                $offset
            );

            if ($events !== []) {
                // A full batch means the cap, not the window, ended this step —
                // so the next one has to resume inside the same window. It may
                // turn out to have been exactly the last batch, in which case
                // that next request comes back empty here and the loop below
                // moves it on to the following window by itself.
                if ($limit > 0 && count($events) === $limit) {
                    return [
                        'events' => $events,
                        'page' => $window->page(),
                        'next_page' => $window->page(),
                        'next_offset' => $offset + $limit,
                    ];
                }

                return [
                    'events' => $events,
                    'page' => $window->page(),
                    'next_page' => EventQueryCache::hasEventsFrom($calendarIds, $window->startBefore())
                        ? $window->page() + 1
                        : null,
                    'next_offset' => 0,
                ];
            }

            if (!EventQueryCache::hasEventsFrom($calendarIds, $window->startBefore())) {
                return [
                    'events' => [],
                    'page' => $window->page(),
                    'next_page' => null,
                    'next_offset' => 0,
                ];
            }

            $window = $window->next();
            $offset = 0;
        }

        // Skip budget spent while events still exist further out. $window was
        // advanced but never queried, so handing it back as the next cursor
        // lets a further click resume exactly here rather than dead-ending.
        return [
            'events' => [],
            'page' => $window->page(),
            'next_page' => $window->page(),
            'next_offset' => 0,
        ];
    }
}
