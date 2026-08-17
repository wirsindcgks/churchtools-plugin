<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use DateTimeImmutable;

/**
 * One page of the frontend's month-based paging: the half-open
 * [startFrom, startBefore) range of start_date values a single "load more" step
 * covers.
 *
 * Boundaries are whole calendar months, not a rolling "+2 months from today":
 * page 0 runs from now until the first of the month two months out (i.e. the
 * rest of the current month plus the whole next one), page 1 picks up exactly
 * there. Two consequences this design leans on:
 *   - A page boundary never falls inside a month, so the month dividers
 *     (event-list.php/event-grid.php) restart cleanly on every appended page —
 *     no divider ever has to be merged with one already in the DOM.
 *   - The bounds only change when the calendar month rolls over, so the cache
 *     keys built from them (EventQueryCache) stay stable instead of shifting
 *     with every request.
 *
 * Page 0 deliberately has no lower bound: EventRepository already filters on
 * `end_date >= now`, and adding `start_date >= first of this month` on top would
 * drop a multi-day event that started in the previous month and is still running.
 */
final class EventWindow
{
    public const DEFAULT_MONTHS = 2;
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 24;

    /**
     * Upper bound for the page index a request may ask for. At the default two
     * months per page that's 20 years out — far beyond any plausible
     * sync_days_ahead, so it only ever caps a nonsense value arriving from the
     * public AJAX endpoint, never a real "load more" chain.
     */
    public const MAX_PAGE = 120;

    private int $page;
    private int $months;
    private DateTimeImmutable $base;

    private function __construct(int $page, int $months, DateTimeImmutable $base)
    {
        $this->page = $page;
        $this->months = $months;
        $this->base = $base;
    }

    /**
     * $now is injectable purely so the boundary math is unit-testable without a
     * running WordPress — production callers omit it and get current_time(),
     * i.e. site-local time, which is the same clock SyncEngine stores
     * start_date/end_date in (see SyncEngine::toMysqlDate()).
     */
    public static function forPage(int $page, int $months, ?string $now = null): self
    {
        $base = (new DateTimeImmutable($now ?? current_time('mysql')))
            ->setTime(0, 0)
            // "first day of this month" before any month arithmetic: adding
            // months to, say, the 31st would otherwise overflow into the
            // following month (PHP's +1 month on Jan 31 lands on Mar 3).
            ->modify('first day of this month');

        return new self(
            max(0, min(self::MAX_PAGE, $page)),
            self::sanitizeMonths($months),
            $base
        );
    }

    public static function sanitizeMonths(int $months): int
    {
        return max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));
    }

    public function page(): int
    {
        return $this->page;
    }

    public function months(): int
    {
        return $this->months;
    }

    public function startFrom(): ?string
    {
        if ($this->page === 0) {
            return null;
        }

        return $this->offsetFromBase($this->page * $this->months);
    }

    public function startBefore(): string
    {
        return $this->offsetFromBase(($this->page + 1) * $this->months);
    }

    public function next(): self
    {
        return new self($this->page + 1, $this->months, $this->base);
    }

    private function offsetFromBase(int $months): string
    {
        return $this->base->modify(sprintf('+%d months', $months))->format('Y-m-d H:i:s');
    }
}
