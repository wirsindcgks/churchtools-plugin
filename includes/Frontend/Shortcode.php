<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;

final class Shortcode
{
    public function register(): void
    {
        add_shortcode('ctp_events', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'calendar' => '',
            'layout' => 'list',
            'limit' => 10,
            'columns' => 3,
            'click' => 'default',
            'filter' => '0',
            'search' => '0',
            'month_dividers' => '0',
        ], $atts, 'ctp_events');

        $refs = array_filter(array_map('trim', explode(',', (string) $atts['calendar'])));
        $calendarIds = SettingsPage::resolveCalendarIds($refs);

        return (new EventListRenderer())->render([
            'calendar_ids' => $calendarIds,
            'layout' => $atts['layout'],
            'limit' => (int) $atts['limit'],
            'columns' => (int) $atts['columns'],
            'click' => $atts['click'],
            'filter' => (bool) $atts['filter'],
            'search' => (bool) $atts['search'],
            'month_dividers' => (bool) $atts['month_dividers'],
        ]);
    }
}
