<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Small inline SVG glyphs for the frontend templates (the date/time/location
 * markers of a meta line, plus the search field's magnifier). Fixed, hard-coded
 * markup with no request input involved, so templates echo the result directly
 * instead of esc_html()'ing it — same trust boundary as the static HTML
 * templates already echo elsewhere.
 */
final class Icons
{
    public static function calendar(): string
    {
        return '<svg class="ctp-events__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"></rect>'
            . '<line x1="3.5" y1="10" x2="20.5" y2="10"></line>'
            . '<line x1="8.5" y1="2.75" x2="8.5" y2="6.5"></line>'
            . '<line x1="15.5" y1="2.75" x2="15.5" y2="6.5"></line></svg>';
    }

    public static function clock(): string
    {
        return '<svg class="ctp-events__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15.5 14"></polyline></svg>';
    }

    public static function location(): string
    {
        return '<svg class="ctp-events__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="M20 10c0 6.5-8 12-8 12s-8-5.5-8-12a8 8 0 0 1 16 0z"></path>'
            . '<circle cx="12" cy="10" r="2.75"></circle></svg>';
    }

    public static function search(): string
    {
        return '<svg class="ctp-events__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
    }
}
