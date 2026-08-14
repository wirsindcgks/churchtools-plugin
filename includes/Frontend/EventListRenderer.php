<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Db\EventRepository;

final class EventListRenderer
{
    public function render(array $args): string
    {
        $args = wp_parse_args($args, [
            'calendar_ids' => [],
            'layout' => 'list',
            'limit' => 10,
        ]);

        $events = (new EventRepository())->findUpcoming($args['calendar_ids'], $args['limit']);

        $template = locate_template('churchtools-plugin/event-list.php');
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-list.php';
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }
}
