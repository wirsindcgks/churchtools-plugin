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
    /**
     * The whole "when" as one string. No longer used by the bundled templates,
     * which split it across dateOnly()/timeRange() to give date and time a line
     * and an icon each — kept because event-{layout}.php is theme-overridable
     * (see EventListRenderer::render()) and a copied override still calls it.
     */
    public static function dateRange(array $event): string
    {
        if (!empty($event['all_day'])) {
            return mysql2date(get_option('date_format'), $event['start_date']);
        }

        $dateTimeFormat = get_option('date_format') . ' ' . get_option('time_format');

        if (self::isSameDay($event)) {
            return mysql2date($dateTimeFormat, $event['start_date'])
                . '–' . mysql2date(get_option('time_format'), $event['end_date']);
        }

        return mysql2date($dateTimeFormat, $event['start_date'])
            . ' – ' . mysql2date($dateTimeFormat, $event['end_date']);
    }

    /**
     * The date half of the "when", for the calendar-icon line the layout
     * templates pair with timeRange() below — the two used to be one
     * dateRange() string on a single line, which buried the time in the
     * middle of it.
     *
     * A single date for a same-day event, the start–end span for one running
     * over several days (where the span belongs on the *date* line, not the
     * time one: "20.08. – 22.08." plus "19:30 – 22:00" reads as one stretch,
     * while a bare "20.08." would drop the end date entirely).
     */
    public static function dateOnly(array $event): string
    {
        $format = get_option('date_format');
        $start = mysql2date($format, $event['start_date']);

        if (self::isSameDay($event)) {
            return $start;
        }

        return $start . ' – ' . mysql2date($format, $event['end_date']);
    }

    /**
     * The time half, for the clock-icon line. Empty for all-day events —
     * their start timestamp carries no meaningful time, and templates then
     * omit the line entirely rather than printing a misleading "00:00"
     * (the "Ganztägig" badge next to the title already states the case).
     */
    public static function timeRange(array $event): string
    {
        if (!empty($event['all_day'])) {
            return '';
        }

        $format = (string) get_option('time_format');
        $separator = self::isSameDay($event) ? '–' : ' – ';

        return self::withClockSuffix(
            mysql2date($format, $event['start_date'])
                . $separator
                . mysql2date($format, $event['end_date']),
            $format
        );
    }

    /**
     * Haengt die Einheit an die Uhrzeit: "10:30–12:00" wird "10:30–12:00 Uhr".
     *
     * Uebersetzbar statt fest verdrahtet, weil "Uhr" eine deutsche
     * Eigenheit ist - in einer englischen Uebersetzung bleibt schlicht "%s"
     * stehen.
     *
     * Ausgelassen wird der Zusatz beim 12-Stunden-Format: Dessen am/pm sagt
     * dasselbe schon selbst, "10:30 am Uhr" waere doppelt gemoppelt und
     * falsch. Erkennbar an einem unmaskierten a/A im Formatstring - in
     * date()-Formaten steht ein Backslash davor, wenn das Zeichen als
     * Buchstabe gemeint ist.
     */
    private static function withClockSuffix(string $time, string $format): string
    {
        if ((bool) preg_match('/(?<!\\\\)[aA]/', $format)) {
            return $time;
        }

        /* translators: %s is a time or time range, e.g. "10:30–12:00". */
        return sprintf(__('%s Uhr', 'churchtools-plugin'), $time);
    }

    private static function isSameDay(array $event): bool
    {
        return mysql2date('Y-m-d', $event['start_date']) === mysql2date('Y-m-d', $event['end_date']);
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
        // Ohne den abschliessenden Punkt, den WordPress' deutsche Uebersetzung
        // mitliefert ("Aug."): Im Datums-Chip steht das Kuerzel unter der Zahl,
        // gesperrt und in Versalien - der Punkt haengt darin schief und
        // verschiebt das Kuerzel gegen die Zahl darueber aus der Mitte.
        return rtrim(mysql2date('M', $mysqlDate), '.');
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
     * see descriptionHtml() below), so the result here is plain text — callers
     * still esc_html() it like every other field. Line breaks fall away with it,
     * which is right for a one-line card excerpt: wp_trim_words() splits on
     * `[\n\r\t ]+`, so a description typed across several lines still reads as
     * one continuous sentence.
     */
    public static function excerpt(string $description, int $wordCount = 20): string
    {
        return wp_trim_words($description, $wordCount);
    }

    /**
     * The description as renderable HTML, for the detail view (popup and own
     * page) and the admin's event detail.
     *
     * ChurchTools stores descriptions as *plain text*: paragraphs are blank
     * lines, lists are "•\t" lines, and the schedule of a seminar is one line
     * per slot. Passed through wp_kses_post() alone — which is all this used to
     * do — every one of those breaks collapses into a single running block of
     * text, which is why a carefully laid-out program note arrived on the site
     * as a wall.
     *
     * The three passes, in the order WordPress itself applies them to comment
     * text:
     *   - wp_kses_post() first, on the raw value, so the allowlist decides what
     *     HTML survives before anything else adds markup of its own. (A
     *     ChurchTools instance *can* deliver HTML here; both shapes have to
     *     work.)
     *   - make_clickable() turns bare URLs and mail addresses into links —
     *     ChurchTools descriptions carry registration links as plain text, and
     *     they were previously unreachable.
     *   - wpautop() last, translating blank lines into <p> and single newlines
     *     into <br>. It leaves existing block-level markup alone, so a
     *     description that already *is* HTML doesn't get double-wrapped.
     */
    public static function descriptionHtml(string $description): string
    {
        return wpautop(make_clickable(wp_kses_post($description)));
    }
}
