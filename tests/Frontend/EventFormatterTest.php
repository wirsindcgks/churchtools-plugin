<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Zeitangabe ab, die in jeder Ansicht hinter dem Uhr-Symbol steht.
 * mysql2date()/get_option() kommen aus tests/bootstrap.php.
 */
final class EventFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ctp_test_options']['time_format'] = 'H:i';
    }

    private function event(array $overrides = []): array
    {
        return array_merge([
            'all_day' => 0,
            'start_date' => '2026-08-30 10:30:00',
            'end_date' => '2026-08-30 12:00:00',
        ], $overrides);
    }

    public function testTimeRangeCarriesTheUnit(): void
    {
        $this->assertSame('10:30–12:00 Uhr', EventFormatter::timeRange($this->event()));
    }

    /**
     * Endet der Termin an einem anderen Tag, steht der Gedankenstrich mit
     * Abstaenden - die Einheit haengt trotzdem genau einmal am Ende.
     */
    public function testTimeRangeAcrossTwoDaysKeepsOneUnit(): void
    {
        $range = EventFormatter::timeRange($this->event(['end_date' => '2026-08-31 12:00:00']));

        $this->assertSame('10:30 – 12:00 Uhr', $range);
    }

    /**
     * Im 12-Stunden-Format sagt am/pm dasselbe schon selbst; "10:30 am Uhr"
     * waere doppelt und falsch.
     */
    public function testTwelveHourFormatGetsNoUnit(): void
    {
        $GLOBALS['ctp_test_options']['time_format'] = 'g:i a';

        $this->assertSame('10:30 am–12:00 pm', EventFormatter::timeRange($this->event()));
    }

    /**
     * Ein maskiertes "a" im Formatstring ist ein Buchstabe, kein am/pm - der
     * Zusatz muss dort bleiben.
     */
    public function testEscapedLetterInFormatDoesNotCountAsMeridiem(): void
    {
        $GLOBALS['ctp_test_options']['time_format'] = 'H:i\\a';

        $this->assertStringEndsWith('Uhr', EventFormatter::timeRange($this->event()));
    }

    public function testAllDayEventHasNoTimeLine(): void
    {
        $this->assertSame('', EventFormatter::timeRange($this->event(['all_day' => 1])));
    }
}
