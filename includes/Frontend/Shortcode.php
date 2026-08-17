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
            // 0 = uncapped: how much is shown is decided by the time window
            // ("months" below), not by a count. Still honored when set, as a
            // safety cap per page — and it remains the only knob for the
            // count-based "upcoming" layout, which isn't paged.
            'limit' => 0,
            'columns' => 3,
            'click' => 'default',
            'filter' => '0',
            'search' => '0',
            'month_dividers' => '0',
            'eventfinder' => '0',
            // 0 = fall back to the Design tab's global "Zeitraum pro Seite".
            'months' => 0,
            'paging' => '1',
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
            'eventfinder' => (bool) $atts['eventfinder'],
            'months' => (int) $atts['months'],
            'paging' => (bool) $atts['paging'],
        ]);
    }
}
