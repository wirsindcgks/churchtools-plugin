<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use DateTimeImmutable;

/**
 * The eventfinder's timeframe shortcuts ("Diese Woche", "Dieses Wochenende",
 * "Diesen Monat", see partials/eventfinder.php) as half-open
 * [from, before) start_date bounds — the same shape EventWindow produces, so
 * both feed EventRepository::findInWindow() unchanged.
 *
 * These bounds used to exist only in assets/js/frontend.js, which filtered the
 * items already in the DOM. That silently made every timeframe answer a subset
 * of "the month window the page happened to load": pick "Diesen Monat" on a
 * list capped at twelve events and you got whichever of this month's events
 * were among those twelve, with the rest reachable only by clicking "Weitere
 * Termine laden" first. The JS pass stays as the instant, no-request first
 * answer; this class is what makes the answer *complete*.
 *
 * Anchored on today rather than on the calendar week's/month's own start: the
 * queries behind it are upcoming-only, so a range reaching back to Monday or
 * the 1st would just match nothing extra — but it would make "Diese Woche"
 * look like it covers days that are over.
 */
final class Timeframe
{
    public const KEYS = ['week', 'weekend', 'month'];

    /**
     * $now is injectable for the same reason EventWindow::forPage()'s is: the
     * boundary math is the whole point of this class and has to be testable
     * without a running WordPress. Production callers omit it and get
     * current_time(), i.e. site-local time — the clock SyncEngine stores
     * start_date/end_date in.
     *
     * @return array{from: string, before: string}|null null for "Jederzeit"
     *         (and for anything unknown), meaning "no bounds at all" rather
     *         than "an empty range".
     */
    public static function bounds(string $key, ?string $now = null): ?array
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $today = (new DateTimeImmutable($now ?? current_time('mysql')))->setTime(0, 0);
        // ISO weekday: 1 = Monday … 7 = Sunday, so the current week's Monday is
        // always a plain subtraction — no special case for Sunday, unlike the
        // JS mirror of this in frontend.js, which has to work around
        // getDay()'s Sunday-is-0.
        $monday = $today->modify(sprintf('-%d days', (int) $today->format('N') - 1));
        $sunday = $monday->modify('+6 days');

        switch ($key) {
            case 'weekend':
                $saturday = $monday->modify('+5 days');

                return self::range($saturday > $today ? $saturday : $today, $sunday);
            case 'month':
                return self::range($today, $today->modify('last day of this month'));
            default:
                return self::range($today, $sunday);
        }
    }

    /**
     * Turns an inclusive first/last *day* into the half-open bounds the queries
     * want — the upper one is the day after the last one at midnight, so an
     * event starting at 20:00 on the final day is still inside the range.
     *
     * @return array{from: string, before: string}
     */
    private static function range(DateTimeImmutable $from, DateTimeImmutable $lastDay): array
    {
        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'before' => $lastDay->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];
    }
}
