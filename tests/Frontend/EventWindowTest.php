<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventWindow;
use PHPUnit\Framework\TestCase;

/**
 * The boundary math behind the frontend's "next two months, then load more"
 * paging. Every case passes an explicit $now so the assertions are about the
 * calendar arithmetic itself, not about whatever today happens to be.
 */
final class EventWindowTest extends TestCase
{
    /**
     * Page 0 covers the rest of the current month plus the whole next one, and
     * has no lower bound at all — see the class docblock for why (a multi-day
     * event that started last month and is still running has to stay visible).
     */
    public function testFirstPageIsOpenEndedDownwardsAndStopsTwoMonthsOut(): void
    {
        $window = EventWindow::forPage(0, 2, '2026-08-17 14:30:00');

        $this->assertNull($window->startFrom());
        $this->assertSame('2026-10-01 00:00:00', $window->startBefore());
    }

    public function testSecondPagePicksUpExactlyWhereTheFirstStopped(): void
    {
        $first = EventWindow::forPage(0, 2, '2026-08-17 14:30:00');
        $second = EventWindow::forPage(1, 2, '2026-08-17 14:30:00');

        $this->assertSame($first->startBefore(), $second->startFrom());
        $this->assertSame('2026-12-01 00:00:00', $second->startBefore());
    }

    public function testNextReturnsTheFollowingPage(): void
    {
        $window = EventWindow::forPage(0, 2, '2026-08-17 14:30:00')->next();

        $this->assertSame(1, $window->page());
        $this->assertSame('2026-10-01 00:00:00', $window->startFrom());
        $this->assertSame('2026-12-01 00:00:00', $window->startBefore());
    }

    /**
     * Regression guard for the reason forPage() normalizes to the first of the
     * month before doing any month arithmetic: PHP's "+1 month" on a 31st
     * overflows into the month after next (Jan 31 + 1 month = Mar 3), which
     * would make the window boundaries depend on what day of the month the
     * visitor happens to arrive.
     */
    public function testMonthEndDatesDoNotOverflowIntoTheFollowingMonth(): void
    {
        $window = EventWindow::forPage(0, 1, '2026-01-31 23:59:00');

        $this->assertSame('2026-02-01 00:00:00', $window->startBefore());
    }

    public function testBoundariesAreIndependentOfTheDayWithinTheMonth(): void
    {
        $early = EventWindow::forPage(1, 2, '2026-08-01 00:00:00');
        $late = EventWindow::forPage(1, 2, '2026-08-31 22:00:00');

        $this->assertSame($early->startFrom(), $late->startFrom());
        $this->assertSame($early->startBefore(), $late->startBefore());
    }

    public function testMonthsAreClampedIntoTheSupportedRange(): void
    {
        $this->assertSame(EventWindow::MIN_MONTHS, EventWindow::sanitizeMonths(0));
        $this->assertSame(EventWindow::MIN_MONTHS, EventWindow::sanitizeMonths(-5));
        $this->assertSame(EventWindow::MAX_MONTHS, EventWindow::sanitizeMonths(99));
        $this->assertSame(3, EventWindow::sanitizeMonths(3));
    }

    /**
     * The page index arrives from a public query string (EventsEndpoint), so
     * both ends have to be clamped rather than trusted.
     */
    public function testPageIndexIsClamped(): void
    {
        $this->assertSame(0, EventWindow::forPage(-3, 2, '2026-08-17 14:30:00')->page());
        $this->assertSame(
            EventWindow::MAX_PAGE,
            EventWindow::forPage(EventWindow::MAX_PAGE + 50, 2, '2026-08-17 14:30:00')->page()
        );
    }

    public function testWindowLengthFollowsTheConfiguredMonthCount(): void
    {
        $window = EventWindow::forPage(1, 3, '2026-08-17 14:30:00');

        $this->assertSame('2026-11-01 00:00:00', $window->startFrom());
        $this->assertSame('2027-02-01 00:00:00', $window->startBefore());
    }
}
