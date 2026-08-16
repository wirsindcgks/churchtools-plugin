<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Renders the opening/closing tag for a card's clickable title, wrapped
 * around the existing title text (not replacing the surrounding .ctp-events__title
 * span/.ctp-events__hero-title heading — those keep their current tag/classes
 * unconditionally). Deliberately just an <a>/<button> around the title text
 * rather than nesting the whole card markup in it: article/h3/p aren't valid
 * <button> content, and only the title needs to be the accessible name of the
 * link/button anyway. The full-card click target comes from CSS instead — see
 * .ctp-events__card-trigger's ::after "stretched link" rule in frontend.css,
 * which positions absolutely against the nearest positioned ancestor (the
 * card/item/hero container), not against this element's own small bounding box.
 */
final class ClickTrigger
{
    public static function open(array $event, string $clickBehavior): string
    {
        if ($clickBehavior === 'page') {
            return '<a class="ctp-events__card-trigger" href="' . esc_url($event['detail_url']) . '">';
        }

        if ($clickBehavior === 'popup') {
            return '<button type="button" class="ctp-events__card-trigger">';
        }

        return '';
    }

    public static function close(string $clickBehavior): string
    {
        if ($clickBehavior === 'page') {
            return '</a>';
        }

        if ($clickBehavior === 'popup') {
            return '</button>';
        }

        return '';
    }
}
