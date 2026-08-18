<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;

/**
 * Public, read-only AJAX endpoint behind the "Weitere Termine laden" button:
 * takes a page index plus the rendering configuration the button carries as a
 * data attribute (see EventListRenderer::pagingConfig()) and returns the next
 * batch of <li> markup.
 *
 * Registered for logged-in *and* logged-out visitors (wp_ajax_nopriv_) and
 * deliberately without a nonce — see pagingConfig()'s docblock for why one would
 * break under full-page caching. Nothing here changes state, and every response
 * is built from data the visitor can already see on the page; the request
 * parameters are nevertheless clamped/whitelisted below rather than trusted,
 * since they arrive straight from the query string.
 */
final class EventsEndpoint
{
    public const ACTION = 'ctp_events_page';

    private const LAYOUTS = ['list', 'grid'];
    private const CLICK_BEHAVIORS = ['none', 'popup', 'page'];
    private const MAX_LIMIT = 500;

    /**
     * Only reached when a "limit" cap is in play and a single window holds more
     * events than the cap (see EventPager) — 5000 is far past any window a
     * parish calendar could plausibly fill, and keeps a hand-crafted OFFSET out
     * of the "make the database walk a million rows" range.
     */
    private const MAX_OFFSET = 5000;

    /**
     * Below two characters a search matches most of the calendar, which is both
     * useless to the visitor and the most expensive query this endpoint can
     * run. The JS applies the same floor before firing a request.
     */
    private const MIN_SEARCH_LENGTH = 2;

    /** Truncated rather than rejected — a pasted paragraph is a typo, not an attack. */
    private const MAX_SEARCH_LENGTH = 100;

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    public static function url(): string
    {
        return admin_url('admin-ajax.php');
    }

    public function handle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only public endpoint, see the class docblock; every value read here is validated against a whitelist or clamped below.
        $layout = isset($_GET['layout']) ? sanitize_key(wp_unslash($_GET['layout'])) : 'list';
        $click = isset($_GET['click']) ? sanitize_key(wp_unslash($_GET['click'])) : 'none';
        $rawCalendars = isset($_GET['calendars']) ? sanitize_text_field(wp_unslash($_GET['calendars'])) : '';
        $page = isset($_GET['page']) ? absint($_GET['page']) : 0;
        $offset = isset($_GET['offset']) ? absint($_GET['offset']) : 0;
        $months = isset($_GET['months']) ? absint($_GET['months']) : EventWindow::DEFAULT_MONTHS;
        $columns = isset($_GET['columns']) ? absint($_GET['columns']) : 3;
        $limit = isset($_GET['limit']) ? absint($_GET['limit']) : 0;
        $monthDividers = !empty($_GET['month_dividers']);
        $lastMonth = isset($_GET['last_month']) ? sanitize_text_field(wp_unslash($_GET['last_month'])) : '';
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $calendar = isset($_GET['calendar']) ? absint($_GET['calendar']) : 0;
        $timeframe = isset($_GET['timeframe']) ? sanitize_key(wp_unslash($_GET['timeframe'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!in_array($layout, self::LAYOUTS, true)) {
            wp_send_json_error(['message' => 'invalid layout'], 400);
        }

        $search = trim($search);
        // Anything unknown is read as "Jederzeit" rather than rejected: the
        // parameter only ever narrows the answer, so a value this version
        // doesn't know can safely fall back to the unnarrowed one.
        $timeframe = in_array($timeframe, Timeframe::KEYS, true) ? $timeframe : '';
        // The eventfinder's calendar buttons pick *one* of the calendars the
        // instance is configured for; sanitizeCalendarIds() has already reduced
        // that configuration to what is actually enabled, so this only has to
        // choose from within it.
        $calendarIds = $this->narrowToCalendar($this->sanitizeCalendarIds($rawCalendars), $calendar);

        if ($search !== '' && mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
            wp_send_json_error(['message' => 'search too short'], 400);
        }

        // A search or a timeframe asks a different question than a paging
        // request does ("everything matching, anywhere in the horizon" vs "the
        // next window"), and both are bounded enough to answer in one go — so
        // they return early, with next_page: null in place of a cursor they
        // have no use for.
        //
        // A bare calendar choice is not among them: "this calendar, at any
        // time" is as open-ended as the unfiltered list and keeps the cursor,
        // with the filter simply applied to every page of it.
        if ($search !== '' || $timeframe !== '') {
            $result = (new EventListRenderer())->renderMatches([
                'calendar_ids' => $calendarIds,
                'layout' => $layout,
                'columns' => $columns,
                'click' => in_array($click, self::CLICK_BEHAVIORS, true) ? $click : 'none',
                'month_dividers' => $monthDividers,
                'paging' => false,
            ], mb_substr($search, 0, self::MAX_SEARCH_LENGTH), $timeframe);

            wp_send_json_success($result);
        }

        $result = (new EventListRenderer())->renderItems([
            'calendar_ids' => $calendarIds,
            'layout' => $layout,
            'columns' => $columns,
            // Already resolved server-side when the first page was rendered, so
            // "default" must not be re-resolved here — anything unexpected falls
            // back to the non-clickable variant rather than to the global setting.
            'click' => in_array($click, self::CLICK_BEHAVIORS, true) ? $click : 'none',
            'month_dividers' => $monthDividers,
            'months' => min(EventWindow::MAX_MONTHS, $months),
            'limit' => min(self::MAX_LIMIT, $limit),
            'paging' => true,
        ], min(EventWindow::MAX_PAGE, $page), min(self::MAX_OFFSET, $offset), $this->sanitizeMonthKey($lastMonth));

        wp_send_json_success($result);
    }

    /**
     * Reduces the instance's calendar list to the single calendar an
     * eventfinder button selected. 0 means "Alle" and leaves the list alone.
     *
     * A calendar that isn't part of the list (or isn't enabled) yields the
     * no-match sentinel rather than the unfiltered list, for the same reason
     * sanitizeCalendarIds() does it: falling back to "all" would answer a
     * narrower question with a *wider* result set.
     *
     * @param int[] $calendarIds
     *
     * @return int[]
     */
    private function narrowToCalendar(array $calendarIds, int $calendar): array
    {
        if ($calendar <= 0) {
            return $calendarIds;
        }

        // [] means "every enabled calendar" (see sanitizeCalendarIds()), so the
        // membership test has to be made against that same set.
        $allowed = $calendarIds === [] ? SettingsPage::getEnabledCalendarIds() : $calendarIds;

        return in_array($calendar, array_map('intval', $allowed), true) ? [$calendar] : [0];
    }

    /**
     * The "Y-m" key of the last month heading already in the browser's list, so
     * the appended batch doesn't repeat it (see
     * EventListRenderer::renderItems()). Anything not in that exact shape is
     * dropped rather than corrected — a bad value would at worst suppress a
     * legitimate divider, so it must not be guessed at.
     */
    private function sanitizeMonthKey(string $raw): ?string
    {
        return preg_match('/^\d{4}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * Turns the button's comma-separated calendar list back into IDs, keeping
     * only calendars the admin has actually enabled. An empty list means "all",
     * exactly as an empty `calendar` shortcode attribute does.
     *
     * The intersection is a guard, not a filter that changes normal behavior:
     * SyncEngine only ever writes rows for enabled calendars
     * (SettingsPage::getEnabledCalendarIds()), so a disabled or unknown ID could
     * not return anything anyway — it just keeps a hand-crafted request from
     * probing for combinations no shortcode on the site actually renders.
     */
    private function sanitizeCalendarIds(string $raw): array
    {
        $requested = array_filter(array_map('absint', explode(',', $raw)));

        if ($requested === []) {
            return [];
        }

        $allowed = array_values(array_intersect($requested, SettingsPage::getEnabledCalendarIds()));

        // Not the same as an empty request: the caller asked for specific
        // calendars and none of them survived the check (all disabled since the
        // page was cached, or made up). Falling through to [] here would mean
        // "all calendars" and quietly return *more* than was asked for, so send
        // back an ID that matches no row instead.
        return $allowed !== [] ? $allowed : [0];
    }
}
