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

    public function render(array $args): string
    {
        $args = wp_parse_args($args, [
            'calendar_ids' => [],
            'layout' => 'list',
            'limit' => 10,
            'columns' => self::DEFAULT_COLUMNS,
        ]);

        $args['layout'] = in_array($args['layout'], self::LAYOUTS, true) ? $args['layout'] : 'list';
        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));

        $designSettings = SettingsPage::get();
        $args['design_style'] = CardDesign::styleAttribute(
            $designSettings['element_order'],
            $designSettings['corner_style']
        );

        $events = EventQueryCache::findUpcoming($args['calendar_ids'], $args['limit']);
        $events = $this->withCalendarMeta($events);

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
     * Adds the calendar's configured name/color to each event row so templates can
     * show them without every override having to know about SettingsPage/options.
     * Also resolves image_url to the imported WP attachment when one exists, so
     * templates never have to hotlink the ChurchTools-hosted original — falls back
     * to the raw ChurchTools URL only for rows synced before the image import
     * (attachment_id not yet set) or where the download failed.
     */
    private function withCalendarMeta(array $events): array
    {
        $calendars = SettingsPage::get()['calendars'];

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
        }
        unset($event);

        return $events;
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
