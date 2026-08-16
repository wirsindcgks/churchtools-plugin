<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Configures which fields the single-event detail view (popup/own page, see
 * EventDetailPage) shows and in what order. Simpler counterpart to
 * CardDesign: the detail view renders once per event, not repeated in a
 * list, so the order is applied directly while building the markup
 * (partials/event-detail-content.php loops over ELEMENT_KEYS in order)
 * instead of via CSS flex `order` custom properties.
 *
 * Deliberately no spacer/divider separator support here (unlike CardDesign) —
 * scope cut for the first version of this feature, see plan.md.
 */
final class DetailDesign
{
    public const ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'meta', 'description'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;

    /**
     * @param string[] $order
     */
    public static function isValidOrder(array $order): bool
    {
        return count($order) === count(self::ELEMENT_KEYS)
            && count($order) === count(array_unique($order))
            && array_diff(self::ELEMENT_KEYS, $order) === [];
    }
}
