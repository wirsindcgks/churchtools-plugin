<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Shared plain-text formatting for the start_date/end_date/all_day fields
 * EventListRenderer passes to every layout template — pulled out because all three
 * templates (list/grid/upcoming) need the same "all-day vs. same-day vs. multi-day"
 * branching. Callers are still responsible for esc_html()'ing the result.
 *
 * Deliberately doesn't fold the all_day flag into this string — every template
 * shows it as its own "Ganztägig" badge instead, so dateRange() just returns the
 * date/time portion.
 */
final class EventFormatter
{
    public static function dateRange(array $event): string
    {
        if (!empty($event['all_day'])) {
            return mysql2date(get_option('date_format'), $event['start_date']);
        }

        $dateTimeFormat = get_option('date_format') . ' ' . get_option('time_format');
        $sameDay = mysql2date('Y-m-d', $event['start_date']) === mysql2date('Y-m-d', $event['end_date']);

        if ($sameDay) {
            return mysql2date($dateTimeFormat, $event['start_date'])
                . '–' . mysql2date(get_option('time_format'), $event['end_date']);
        }

        return mysql2date($dateTimeFormat, $event['start_date'])
            . ' – ' . mysql2date($dateTimeFormat, $event['end_date']);
    }

    public static function shortDate(string $mysqlDate): string
    {
        return mysql2date(get_option('date_format'), $mysqlDate);
    }

    public static function dayNumber(string $mysqlDate): string
    {
        return mysql2date('j', $mysqlDate);
    }

    public static function monthAbbreviation(string $mysqlDate): string
    {
        return mysql2date('M', $mysqlDate);
    }
}
