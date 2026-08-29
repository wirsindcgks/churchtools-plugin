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
     * Cap on how many events one *complete answer* returns — a search's hits, or
     * an eventfinder timeframe's range (see renderMatches()). Generous enough
     * that a realistic query never truncates (a parish month is some three
     * dozen events), low enough that "e" doesn't try to render the entire
     * synced calendar into one response.
     */
    private const MATCH_LIMIT = 100;

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
        // buttons, optionales Suchfeld) that replaces the plain filter/search toolbar rather than
        // stacking alongside it — showing both would be redundant UI over the same
        // underlying filtering, so it wins over "filter"/"search" when both are set.
        $args['eventfinder'] = $isFilterable && (bool) $args['eventfinder'];
        // Frueher schaltete der Eventfinder die Suche zwangsweise mit ein
        // ("$args['eventfinder'] || ..."), weil sein Suchfeld als Teil von ihm
        // gedacht war. Auf einer Seite, auf der die Suche bewusst abgewaehlt
        // und der Eventfinder angelassen war, stand sie damit trotzdem da -
        // ein Schalter ohne Wirkung. Beide Werkzeugleisten fragen jetzt
        // denselben Schalter (siehe partials/eventfinder.php).
        $args['search'] = $isFilterable && (bool) $args['search'];
        $args['month_dividers'] = $isFilterable && (bool) $args['month_dividers'];
        $args['paging'] = $isFilterable && $args['paging'] && $args['next_page'] !== null;
        $args['paging_config'] = $args['paging'] ? $this->pagingConfig($args) : [];

        // After $args['eventfinder'], which decides whether there is a toolbar
        // to build these for at all.
        $filterCalendars = $isFilterable && ($args['filter'] || $args['eventfinder'])
            ? $this->filterCalendars($args)
            : [];
        $args['show_toolbar'] = $args['eventfinder'] || $args['search'] || $filterCalendars !== [];
        // Carried on the toolbar itself rather than on the load-more button:
        // every control in it has to be able to ask the server (see
        // resultsConfig()), including on an instance with paging="0" or one
        // whose list fits into a single page, where no button exists to hang a
        // config off. Hence also *after* show_toolbar rather than gated on
        // "search": a filter dropdown without a search box needs it just as
        // much.
        $args['toolbar_config'] = $args['show_toolbar'] ? $this->resultsConfig($args) : [];

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
     * just the item elements (plus their month dividers), no container, no
     * toolbar, since those are already in the page and the JS appends into the
     * existing list container (see the templates: a div[role=list], not a <ul>). Called from EventsEndpoint, which has already validated
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
     * Renders the complete answer to a toolbar question — the search box's term,
     * the eventfinder's timeframe, or both — in the same markup as a normal
     * page, so the frontend JS can swap them into the existing <ul>.
     *
     * Unlike renderItems() there is no window and no cursor: these queries
     * return their (capped) matches in one go, which is exactly what lets them
     * reach past the month window the page is currently showing. That reach is
     * the point of the method — a client-side filter over the loaded DOM can
     * only ever answer "of what you can already see", which is how "Diesen
     * Monat" came to show a fraction of the month.
     *
     * The unbounded "Jederzeit" case is deliberately *not* routed here: with no
     * date bound at all, a complete answer is the whole sync horizon, and
     * MATCH_LIMIT would start silently swallowing events. That case stays with
     * the paged path (renderItems()), which has a cursor for exactly this.
     *
     * @return array{html: string, count: int, next_page: null, next_offset: int}
     */
    public function renderMatches(array $args, string $search, string $timeframe): array
    {
        $args = $this->prepareArgs($args);
        $empty = ['html' => '', 'count' => 0, 'next_page' => null, 'next_offset' => 0];

        if ($args['layout'] === 'upcoming') {
            return $empty;
        }

        $bounds = Timeframe::bounds($timeframe);
        $designSettings = SettingsPage::get();
        $events = EventQueryCache::findMatching(
            $args['calendar_ids'],
            $search,
            $bounds['from'] ?? null,
            $bounds['before'] ?? null,
            self::MATCH_LIMIT
        );
        $events = $this->withCalendarMeta($events, $args['click_behavior'], $designSettings['detail_element_order']);

        // Month dividers stay off for a *search*: hits are scattered across the
        // whole horizon, so grouping them by month would produce a heading per
        // result rather than a grouping. A timeframe's results are a contiguous
        // stretch of days instead, so there they group as usefully as on the
        // paged list — and "Diesen Monat" spanning two headings is how a
        // visitor sees that the month runs into the next one.
        $args['month_dividers'] = $search === '' && $args['month_dividers'];
        $currentMonthKey = null;

        ob_start();
        require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-' . $args['layout'] . '-items.php';

        return [
            'html' => (string) ob_get_clean(),
            'count' => count($events),
            'next_page' => null,
            'next_offset' => 0,
        ];
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

        // Die Stil-Grundlage kommt als Klasse, nicht als Inline-Variablen —
        // warum, steht im Docblock von DesignPreset. Die Rangfolge zwischen
        // beiden ergibt sich daraus von selbst: Der Inline-Style unten schlaegt
        // die Klasse, eine ausdrueckliche Einzeleinstellung im Design-Tab liegt
        // also ueber dem gewaehlten Stil.
        $args['design_class'] = DesignPreset::bodyClass($designSettings['design_preset']);

        $args['design_style'] = CardDesign::styleAttribute(
            $designSettings['element_order'],
            $designSettings['corner_style'],
            $designSettings['media_aspect_ratio'],
            $designSettings['accent_color_enabled'] ? $designSettings['accent_color'] : '',
            $designSettings['button_color_enabled'] ? $designSettings['button_color'] : ''
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
     * What the toolbar needs to ask the server for a complete answer beyond the
     * currently loaded window — used by the search box and, since the
     * eventfinder's buttons became server-backed too, by those as well.
     *
     * pagingConfig() above is the counterpart for the load-more button and the
     * two overlap heavily; they stay separate because this one has to exist
     * where that one doesn't. The button is only rendered when a further page
     * exists (and not at all under paging="0"), while the toolbar has to be
     * able to ask its question on any list — including one that fits into a
     * single page. Everything the paged branch needs (months, limit, dividers)
     * therefore travels here as well: an eventfinder set to "Jederzeit" resumes
     * the paged list under its calendar filter, and the appended pages have to
     * be built exactly like the first one.
     */
    private function resultsConfig(array $args): array
    {
        return [
            'endpoint' => EventsEndpoint::url(),
            'action' => EventsEndpoint::ACTION,
            'calendars' => array_map('intval', $args['calendar_ids']),
            'layout' => $args['layout'],
            'columns' => $args['columns'],
            'click' => $args['click_behavior'],
            'month_dividers' => $args['month_dividers'] ? 1 : 0,
            'months' => $args['months'],
            'limit' => $args['limit'],
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
        // Eigener Name statt $args['design_class'] wie in den Listen-Templates:
        // Diese Ansicht bekommt gar kein $args, sie rendert genau einen Termin.
        $designClass = DesignPreset::bodyClass($designSettings['design_preset']);

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
        self::primeAttachmentCache($events, $calendars);

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
     * Builds the options for the frontend filter dropdown and the eventfinder's
     * "Thema"-buttons: the calendars this instance draws from that actually
     * have something coming up. Returns [] when there's nothing to filter
     * (0 or 1 of them), so templates can render the toolbar with a plain
     * empty-check.
     *
     * The rule used to depend on whether more pages could be appended, and both
     * halves of it were wrong in their own direction:
     *   - With paging, every *configured* calendar was offered — including ones
     *     with no upcoming events at all. Picking such a topic landed the
     *     visitor on an empty list, indistinguishable from a broken filter.
     *   - Without it, only the calendars present among the rendered events were
     *     offered — which under an explicit paging="0" silently dropped topics
     *     that do have events, just not inside the loaded window.
     *
     * One query settles both, and it is the same question in either case: a
     * topic is worth offering exactly when something stands behind it. Since
     * the toolbar's filters reach across the whole sync horizon now (see
     * renderMatches()), "behind it" means the horizon, not the loaded page.
     */
    private function filterCalendars(array $args): array
    {
        $withEvents = EventQueryCache::calendarIdsWithUpcoming($args['calendar_ids']);
        $calendars = array_values(array_filter(
            $this->configuredCalendars($args['calendar_ids']),
            static fn (array $calendar): bool => in_array($calendar['id'], $withEvents, true)
        ));

        if (count($calendars) < 2) {
            return [];
        }

        usort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $calendars;
    }

    /**
     * Holt alle Bilder dieser Seite in einem Zug in den Objekt-Cache.
     *
     * wp_get_attachment_image_url() unten schlaegt sonst jedes Bild einzeln
     * nach, und zwar mit zwei Abfragen (Beitrag und Metadaten). Bei 26 Bildern
     * auf einer Seite waren das 52 der 55 Abfragen eines Durchlaufs - gemessen
     * gegen die Testumgebung. Lokal faellt das nicht auf, auf einem gemeinsam
     * genutzten Server ist jede dieser Abfragen eine eigene Wartezeit, und die
     * Seite von cg-ks.de brauchte im Endpunkt gut zwei Sekunden.
     *
     * _prime_post_caches() ist Kernfunktion und in WordPress selbst genau dafuer
     * da (WP_Query nutzt sie fuer ihre eigenen Ergebnisse). Der fuehrende
     * Unterstrich heisst „nicht Teil der oeffentlichen API“ - die Alternative
     * waere eine WP_Query ueber post__in nur zum Fuellen desselben Caches, also
     * derselbe Effekt mit mehr Umweg. Wenn eine kuenftige WordPress-Version sie
     * doch entfernt, soll die Seite langsam werden und nicht kaputtgehen:
     * Ohne sie schlaegt wp_get_attachment_image_url() die Bilder wieder
     * einzeln nach, genau wie vor 1.0.0.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $calendars
     */
    private static function primeAttachmentCache(array $events, array $calendars): void
    {
        $ids = [];

        foreach ($events as $event) {
            $attachmentId = (int) ($event['attachment_id'] ?? 0);
            if ($attachmentId > 0) {
                $ids[] = $attachmentId;
            }

            $calendar = $calendars[(int) $event['ct_calendar_id']] ?? null;
            $defaultImageId = (int) ($calendar['default_image_id'] ?? 0);
            if ($defaultImageId > 0) {
                $ids[] = $defaultImageId;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids !== [] && function_exists('_prime_post_caches')) {
            _prime_post_caches($ids, false, true);
        }
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
            // Die Farbe wandert bis in die Knoepfe des Eventfinders (siehe
            // partials/eventfinder.php): Ein Knopf in der Farbe seines
            // Kalenders ist im Ergebnis darunter wiederzuerkennen, wo dieselbe
            // Farbe die Kategorie auszeichnet.
            $calendars[$id] = [
                'id' => $id,
                'name' => $name !== '' ? $name : sprintf('#%d', $id),
                'color' => (string) ($known[$id]['color'] ?? ''),
            ];
        }

        return array_values($calendars);
    }
}
