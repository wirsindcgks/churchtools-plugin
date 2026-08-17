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

    /**
     * Grouping key for month dividers (event-list.php/event-grid.php) — plain
     * "Y-m" so consecutive events in the same month compare equal regardless
     * of locale, independent of monthLabel()'s display text below.
     */
    public static function monthKey(string $mysqlDate): string
    {
        return mysql2date('Y-m', $mysqlDate);
    }

    /**
     * Plain "Y-m-d" for the eventfinder's timeframe buttons (assets/js/frontend.js
     * parses this per item to bucket by "this week"/"this weekend"/"this month") —
     * date-only since the timeframe presets operate on calendar days, not times.
     */
    public static function dateKey(string $mysqlDate): string
    {
        return mysql2date('Y-m-d', $mysqlDate);
    }

    /**
     * Localized "Month Year" heading text for a month divider, e.g. "August
     * 2026" — uses date_i18n() (not mysql2date()) so the month name itself
     * respects the site's language, not just the date *format*.
     */
    public static function monthLabel(string $mysqlDate): string
    {
        return date_i18n('F Y', mysql2date('U', $mysqlDate));
    }

    /**
     * wp_trim_words() already strips tags itself (descriptions can contain HTML,
     * see SettingsPage's wp_kses_post() on the admin detail view), so the result
     * here is plain text — callers still esc_html() it like every other field.
     */
    public static function excerpt(string $description, int $wordCount = 20): string
    {
        return wp_trim_words($description, $wordCount);
    }
}
