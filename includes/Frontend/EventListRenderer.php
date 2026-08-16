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

    public function render(array $args): string
    {
        $args = wp_parse_args($args, [
            'calendar_ids' => [],
            'layout' => 'list',
            'limit' => 10,
            'columns' => self::DEFAULT_COLUMNS,
            'click' => 'default',
        ]);

        $args['layout'] = in_array($args['layout'], self::LAYOUTS, true) ? $args['layout'] : 'list';
        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));

        $designSettings = SettingsPage::get();
        $args['design_style'] = CardDesign::styleAttribute(
            $designSettings['element_order'],
            $designSettings['corner_style']
        );
        // Same value for every card in the loop below (element order is a global
        // design setting, not per-event), so it's computed once here rather than
        // per iteration in the templates.
        $args['design_separators'] = CardDesign::renderSeparators($designSettings['element_order']);

        // "default" (the shortcode/block attribute's own default) defers to the
        // Design tab's global setting; an explicit none/popup/page always wins,
        // even over a global "popup" default — same override relationship "columns"
        // already has to nothing (there's no global columns setting to defer to).
        $args['click_behavior'] = in_array($args['click'], self::CLICK_BEHAVIORS, true)
            ? $args['click']
            : $designSettings['click_behavior'];

        $events = EventQueryCache::findUpcoming($args['calendar_ids'], $args['limit']);
        $events = $this->withCalendarMeta($events, $args['click_behavior'], $designSettings['detail_element_order']);

        // "upcoming" has a single hero item plus a compact list, not a set of peer
        // items — filtering it client-side would either leave an empty hero slot or
        // need JS to re-elect a new hero, so the filter bar is scoped to list/grid.
        $filterCalendars = $args['layout'] !== 'upcoming' ? $this->filterCalendars($events) : [];

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
     * (attachment_id not yet set) or where the download failed.
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
     * Builds the options for the frontend filter dropdown from the calendars
     * actually present among $events (not every enabled calendar in settings) —
     * showing a filter option with zero matching events would be confusing.
     * Returns [] when there's nothing to filter (0 or 1 distinct calendar), so
     * templates can render the filter bar with a plain empty-check.
     */
    private function filterCalendars(array $events): array
    {
        $calendars = [];

        foreach ($events as $event) {
            $id = (int) $event['ct_calendar_id'];

            if (!isset($calendars[$id])) {
                $name = $event['calendar_name'] !== '' ? $event['calendar_name'] : sprintf('#%d', $id);
                $calendars[$id] = ['id' => $id, 'name' => $name];
            }
        }

        if (count($calendars) < 2) {
            return [];
        }

        usort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $calendars;
    }
}
