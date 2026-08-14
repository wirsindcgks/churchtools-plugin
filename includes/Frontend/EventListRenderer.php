<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;

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

        $events = (new EventRepository())->findUpcoming($args['calendar_ids'], $args['limit']);
        $events = $this->withCalendarMeta($events);

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
     */
    private function withCalendarMeta(array $events): array
    {
        $calendars = SettingsPage::get()['calendars'];

        foreach ($events as &$event) {
            $calendar = $calendars[(int) $event['ct_calendar_id']] ?? null;
            $event['calendar_color'] = $calendar['color'] ?? '';
            $event['calendar_name'] = $calendar['name'] ?? '';
        }
        unset($event);

        return $events;
    }
}
