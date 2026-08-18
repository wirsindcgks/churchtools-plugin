<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;

final class EventListRenderer
{
    private const LAYOUTS = ['list', 'grid', 'upcoming'];
    private const DEFAULT_COLUMNS = 3;
    private const MIN_COLUMNS = 2;
    private const MAX_COLUMNS = 6;
    private const CLICK_BEHAVIORS = ['none', 'popup', 'page'];

    /**
     * "upcoming" is the one layout that stayed count-based (one hero plus a short
     * tail — a time window makes no sense for it), so it still needs a number when
     * no explicit "limit" is given. Kept at the value "limit" defaulted to before
     * the switch to month windows, so existing [ctp_events layout="upcoming"]
     * instances render exactly as they did.
     */
    private const UPCOMING_FALLBACK_LIMIT = 10;

    /**
     * Cap on how many hits one search returns. Generous enough that a realistic
     * query never truncates, low enough that "e" doesn't try to render the
     * entire synced calendar into one response.
     */
    private const SEARCH_LIMIT = 100;

    public function render(array $args): string
    {
        $args = $this->prepareArgs($args);
        $designSettings = SettingsPage::get();

        if ($args['layout'] === 'upcoming') {
            $limit = $args['limit'] > 0 ? $args['limit'] : self::UPCOMING_FALLBACK_LIMIT;
            $events = EventQueryCache::findUpcoming($args['calendar_ids'], $limit);
            $args['next_page'] = null;
            $args['next_offset'] = 0;
        } else {
            $page = EventPager::load($args['calendar_ids'], 0, $args['months'], $args['limit']);
            $events = $page['events'];
            $args['next_page'] = $page['next_page'];
            $args['next_offset'] = $page['next_offset'];
        }

        $events = $this->withCalendarMeta($events, $args['click_behavior'], $designSettings['detail_element_order']);

        // "upcoming" has a single hero item plus a compact list, not a set of peer
        // items — filtering/searching it client-side would either leave an empty
        // hero slot or need JS to re-elect a new hero, so the whole toolbar (filter,
        // search, month dividers, eventfinder) is scoped to list/grid, same as
        // before. Paging is scoped out for the same reason: there is no flat list
        // to append to.
        $isFilterable = $args['layout'] !== 'upcoming';
        // Eventfinder is a self-contained guided toolbar (calendar buttons, timeframe
        // buttons, search) that replaces the plain filter/search toolbar rather than
        // stacking alongside it — showing both would be redundant UI over the same
        // underlying filtering, so it wins over "filter"/"search" when both are set.
        $args['eventfinder'] = $isFilterable && (bool) $args['eventfinder'];
        $args['search'] = $args['eventfinder'] || ($isFilterable && (bool) $args['search']);
        $args['month_dividers'] = $isFilterable && (bool) $args['month_dividers'];
        $args['paging'] = $isFilterable && $args['paging'] && $args['next_page'] !== null;
        $args['paging_config'] = $args['paging'] ? $this->pagingConfig($args) : [];
        // Carried on the search input itself rather than on the load-more
        // button: search has to work with paging="0" too, where no button
        // exists to hang a config off.
        $args['search_config'] = $args['search'] ? $this->searchConfig($args) : [];

        // After $args['paging']/$args['eventfinder'], which filterCalendars()
        // branches on.
        $filterCalendars = $isFilterable && ($args['filter'] || $args['eventfinder'])
            ? $this->filterCalendars($events, $args)
            : [];
        $args['show_toolbar'] = $args['eventfinder'] || $args['search'] || $filterCalendars !== [];

        $templateName = "churchtools-plugin/event-{$args['layout']}.php";
        $template = locate_template($templateName);
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-' . $args['layout'] . '.php';
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * Renders one further page of list/grid items for the load-more button —
     * just the <li> elements (plus their month dividers), no container, no
     * toolbar, since those are already in the page and the JS appends into the
     * existing <ul>. Called from EventsEndpoint, which has already validated
     * every value in $args against the same rules render() applies.
     *
     * @return array{html: string, next_page: int|null, next_offset: int}
     */
    public function renderItems(array $args, int $page, int $offset = 0, ?string $lastMonthKey = null): array
    {
        $args = $this->prepareArgs($args);

        if ($args['layout'] === 'upcoming') {
            return ['html' => '', 'next_page' => null, 'next_offset' => 0];
        }

        $designSettings = SettingsPage::get();
        $result = EventPager::load($args['calendar_ids'], $page, $args['months'], $args['limit'], $offset);
        $events = $this->withCalendarMeta(
            $result['events'],
            $args['click_behavior'],
            $designSettings['detail_element_order']
        );

        // Divider bookkeeping continues from the last month already rendered in
        // the browser, which the JS reads off the DOM and sends along. Window
        // boundaries are month boundaries (see EventWindow), so for a plain
        // page step this is always a different month and a fresh divider gets
        // emitted either way — but a capped window continues *inside* one month
        // (see EventPager's offset), and without this the batch would open with
        // a second "Oktober 2026" heading right under the first.
        $currentMonthKey = $lastMonthKey;

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-' . $args['layout'] . '-items.php';

        return [
            'html' => (string) ob_get_clean(),
            'next_page' => $result['next_page'],
            'next_offset' => $result['next_offset'],
        ];
    }

    /**
     * Renders the items for a full-horizon search, in the same markup as a
     * normal page so the frontend JS can swap them into the existing <ul>.
     * Unlike renderItems() there is no window, no cursor and no "load more":
     * a search returns its (capped) matches in one go, which is what makes it
     * able to reach past the month window the page is currently showing.
     *
     * @return array{html: string, count: int}
     */
    public function renderSearchResults(array $args, string $search): array
    {
        $args = $this->prepareArgs($args);

        if ($args['layout'] === 'upcoming') {
            return ['html' => '', 'count' => 0];
        }

        $designSettings = SettingsPage::get();
        $events = EventQueryCache::searchUpcoming($args['calendar_ids'], $search, self::SEARCH_LIMIT);
        $events = $this->withCalendarMeta($events, $args['click_behavior'], $designSettings['detail_element_order']);

        // Month dividers stay off for search results: hits are scattered across
        // the whole horizon, so grouping them by month would produce a heading
        // per result rather than a grouping.
        $args['month_dividers'] = false;
        $currentMonthKey = null;

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-' . $args['layout'] . '-items.php';

        return ['html' => (string) ob_get_clean(), 'count' => count($events)];
    }

    /**
     * Narrows a requested calendar list down to the ones actually enabled in the
     * settings; an empty request means "all enabled". Mirrors what
     * EventsEndpoint::sanitizeCalendarIds() does for the AJAX side, so a first
     * page and an appended one can never disagree about which calendars they show.
     *
     * @return int[]
     */
    private static function enabledOnly(array $calendarIds): array
    {
        $enabled = SettingsPage::getEnabledCalendarIds();

        if ($calendarIds === []) {
            return $enabled !== [] ? $enabled : [0];
        }

        $allowed = array_values(array_intersect(array_map('intval', $calendarIds), $enabled));

        return $allowed !== [] ? $allowed : [0];
    }

    /**
     * The argument normalization render() and renderItems() share: layout/column
     * clamping plus everything derived from the global Design-tab settings, so an
     * appended page is styled by exactly the same rules as the first one.
     */
    private function prepareArgs(array $args): array
    {
        $args = wp_parse_args($args, [
            'calendar_ids' => [],
            'layout' => 'list',
            // 0 = no cap: the month window decides how much is shown. An explicit
            // limit still works and then acts as a safety cap per page.
            'limit' => 0,
            'columns' => self::DEFAULT_COLUMNS,
            'click' => 'default',
            'filter' => false,
            'search' => false,
            'month_dividers' => false,
            'eventfinder' => false,
            // 0 = use the Design tab's global "Zeitraum pro Seite" setting.
            'months' => 0,
            'paging' => true,
        ]);

        // "Alle Kalender" hiess bisher woertlich "kein Kalenderfilter" - die
        // Abfrage lief ohne WHERE ct_calendar_id, gab also auch Termine
        // deaktivierter Kalender aus. Wer einen Kalender abwaehlt, erwartet das
        // Gegenteil, und die Detailseite hielt sich mit ihrer eigenen
        // Enabled-Pruefung bereits daran (EventDetailPage::maybeRenderDetail()) -
        // Liste und Detailansicht widersprachen sich also.
        //
        // Auch explizit genannte IDs werden geschnitten: ein per Shortcode
        // adressierter, inzwischen deaktivierter Kalender darf nicht die
        // Hintertuer sein. [0] statt [] als "nichts gefunden"-Sentinel, weil ein
        // leeres Array wieder "alle" bedeuten wuerde.
        $args['calendar_ids'] = self::enabledOnly((array) $args['calendar_ids']);

        $args['layout'] = in_array($args['layout'], self::LAYOUTS, true) ? $args['layout'] : 'list';
        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));
        $args['limit'] = max(0, (int) $args['limit']);
        $args['paging'] = (bool) $args['paging'];

        $designSettings = SettingsPage::get();

        $args['months'] = EventWindow::sanitizeMonths(
            (int) $args['months'] > 0 ? (int) $args['months'] : (int) $designSettings['paging_months']
        );

        $args['design_style'] = CardDesign::styleAttribute(
            $designSettings['element_order'],
            $designSettings['corner_style'],
            $designSettings['media_aspect_ratio'],
            $designSettings['accent_color_enabled'] ? $designSettings['accent_color'] : ''
        );
        // Same value for every card in the loop below (element order is a global
        // design setting, not per-event), so it's computed once here rather than
        // per iteration in the templates.
        $args['design_separators'] = CardDesign::renderSeparators($designSettings['element_order']);
        // Hidden fields have no dedicated markup to attach a CSS var to (unlike
        // order/corner/ratio/accent above) — templates check this array directly
        // with in_array() and skip rendering the element's markup outright.
        $args['hidden_elements'] = $designSettings['hidden_elements'];

        // "default" (the shortcode/block attribute's own default) defers to the
        // Design tab's global setting; an explicit none/popup/page always wins,
        // even over a global "popup" default — same override relationship "columns"
        // already has to nothing (there's no global columns setting to defer to).
        $args['click_behavior'] = in_array($args['click'], self::CLICK_BEHAVIORS, true)
            ? $args['click']
            : $designSettings['click_behavior'];

        return $args;
    }

    /**
     * The instance configuration the load-more button carries as a data
     * attribute, so the AJAX request can render further pages identically.
     * Deliberately passes the *resolved* click_behavior rather than the raw
     * "click" attribute: an instance left on "default" should keep rendering
     * with the behavior the first page was built with, even if an admin changes
     * the global Design-tab setting while a visitor has the page open.
     *
     * No nonce: the endpoint is public, read-only and returns nothing a visitor
     * can't already see on the page. A nonce would also be actively harmful
     * here — this markup is served from full-page caches, where an embedded
     * nonce goes stale long before the cached HTML does and would break the
     * button for everyone hitting the cache.
     */
    private function pagingConfig(array $args): array
    {
        return [
            'endpoint' => EventsEndpoint::url(),
            'action' => EventsEndpoint::ACTION,
            'page' => $args['next_page'],
            'offset' => $args['next_offset'],
            'calendars' => array_map('intval', $args['calendar_ids']),
            'layout' => $args['layout'],
            'columns' => $args['columns'],
            'click' => $args['click_behavior'],
            'month_dividers' => $args['month_dividers'] ? 1 : 0,
            'months' => $args['months'],
            'limit' => $args['limit'],
        ];
    }

    /**
     * What the search box needs to ask the server for matches beyond the
     * currently loaded window. Deliberately a subset of pagingConfig(): no
     * cursor, no month dividers, because a search result set has neither.
     */
    private function searchConfig(array $args): array
    {
        return [
            'endpoint' => EventsEndpoint::url(),
            'action' => EventsEndpoint::ACTION,
            'calendars' => array_map('intval', $args['calendar_ids']),
            'layout' => $args['layout'],
            'columns' => $args['columns'],
            'click' => $args['click_behavior'],
            'min' => 2,
        ];
    }

    /**
     * Renders a single event's detail view for the "own page" click behavior
     * (EventDetailPage::maybeRenderDetail()) — enriches the raw DB row the same
     * way render() enriches every list/grid/upcoming event, then includes the
     * "own page" wrapper template (back link + partials/event-detail-content.php).
     */
    public function renderDetail(array $rawEvent): string
    {
        $designSettings = SettingsPage::get();
        $event = $this->withCalendarMeta([$rawEvent], 'page', $designSettings['detail_element_order'])[0];
        $order = DetailDesign::isValidOrder($designSettings['detail_element_order'])
            ? $designSettings['detail_element_order']
            : DetailDesign::DEFAULT_ORDER;
        $backUrl = wp_get_referer();
        $backUrl = $backUrl !== false && $backUrl !== '' ? $backUrl : home_url('/');

        $templateName = 'churchtools-plugin/event-detail.php';
        $template = locate_template($templateName);
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-detail.php';
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * Adds the calendar's configured name/color to each event row so templates can
     * show them without every override having to know about SettingsPage/options.
     * Also resolves image_url to the imported WP attachment when one exists, so
     * templates never have to hotlink the ChurchTools-hosted original — falls back
     * to the raw ChurchTools URL only for rows synced before the image import
     * (attachment_id not yet set) or where the download failed. If the event still
     * has no image at that point, falls back to the calendar's admin-configured
     * "Standardbild" (default_image_id, Calendars tab) — image_is_fallback marks
     * that case so templates/CSS can visually distinguish a generic calendar photo
     * from a real per-event one (e.g. a tinted overlay). The CSS gradient in
     * .ctp-events__media remains the last-resort fallback when even the calendar
     * has no default image.
     *
     * Also adds detail_url (always, cheap to compute) and — only for the "popup"
     * click behavior — detail_html: the pre-rendered detail content for this one
     * event, embedded server-side so the frontend JS never needs an AJAX round
     * trip to open the modal (see partials/event-detail-content.php).
     */
    private function withCalendarMeta(array $events, string $clickBehavior = 'none', array $detailOrder = []): array
    {
        $calendars = SettingsPage::get()['calendars'];
        $order = DetailDesign::isValidOrder($detailOrder) ? $detailOrder : DetailDesign::DEFAULT_ORDER;

        foreach ($events as &$event) {
            $calendar = $calendars[(int) $event['ct_calendar_id']] ?? null;
            $event['calendar_color'] = $calendar['color'] ?? '';
            $event['calendar_name'] = $calendar['name'] ?? '';

            $attachmentId = (int) ($event['attachment_id'] ?? 0);
            if ($attachmentId > 0) {
                $attachmentUrl = wp_get_attachment_image_url($attachmentId, 'large');
                if ($attachmentUrl !== false) {
                    $event['image_url'] = $attachmentUrl;
                }
            }

            $event['image_is_fallback'] = false;
            if ($event['image_url'] === '') {
                $defaultImageId = (int) ($calendar['default_image_id'] ?? 0);
                if ($defaultImageId > 0) {
                    $defaultImageUrl = wp_get_attachment_image_url($defaultImageId, 'large');
                    if ($defaultImageUrl !== false) {
                        $event['image_url'] = $defaultImageUrl;
                        $event['image_is_fallback'] = true;
                    }
                }
            }

            $event['detail_url'] = EventDetailPage::url((int) $event['id']);

            if ($clickBehavior === 'popup') {
                $event['detail_html'] = $this->renderDetailPartial($event, $order);
            }
        }
        unset($event);

        return $events;
    }

    private function renderDetailPartial(array $event, array $order): string
    {
        ob_start();
        include CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-content.php';

        return (string) ob_get_clean();
    }

    /**
     * Builds the options for the frontend filter dropdown. Returns [] when
     * there's nothing to filter (0 or 1 distinct calendar), so templates can
     * render the filter bar with a plain empty-check.
     *
     * Two sources, depending on whether more pages can still be appended:
     *   - Paging active: the calendars this instance is *configured* for. The
     *     rendered events are only the first window, so deriving the options
     *     from them would produce a dropdown that silently gains entries as the
     *     visitor loads more — or worse, one that's missing the calendar they
     *     were looking for.
     *   - No further pages: the calendars actually present among $events, the
     *     original behavior — with the full result set in the DOM, an option
     *     matching zero events would just be noise.
     */
    private function filterCalendars(array $events, array $args): array
    {
        $calendars = $args['paging']
            ? $this->configuredCalendars($args['calendar_ids'])
            : $this->calendarsAmong($events);

        if (count($calendars) < 2) {
            return [];
        }

        usort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $calendars;
    }

    private function calendarsAmong(array $events): array
    {
        $calendars = [];

        foreach ($events as $event) {
            $id = (int) $event['ct_calendar_id'];

            if (!isset($calendars[$id])) {
                $name = $event['calendar_name'] !== '' ? $event['calendar_name'] : sprintf('#%d', $id);
                $calendars[$id] = ['id' => $id, 'name' => $name];
            }
        }

        return array_values($calendars);
    }

    /**
     * The calendars this shortcode/block instance draws from: its explicit
     * calendar_ids, or — when it was left empty, meaning "all" — every calendar
     * currently enabled in the settings. Mirrors what EventRepository actually
     * queries in each case.
     */
    private function configuredCalendars(array $calendarIds): array
    {
        $known = SettingsPage::get()['calendars'];
        $ids = $calendarIds !== [] ? array_map('intval', $calendarIds) : SettingsPage::getEnabledCalendarIds();
        $calendars = [];

        foreach ($ids as $id) {
            $name = $known[$id]['name'] ?? '';
            $calendars[$id] = ['id' => $id, 'name' => $name !== '' ? $name : sprintf('#%d', $id)];
        }

        return array_values($calendars);
    }
}
