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
    public const ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;

    /**
     * Same widening CardDesign::upgradeOrder() does for the card order, for
     * the detail view's own key set — date, time and location replaced the
     * single "meta" entry here too, and both orders are stored per site, so
     * both can still arrive on the old shape long after an update.
     *
     * @param string[] $order
     *
     * @return string[]
     */
    public static function upgradeOrder(array $order): array
    {
        return CardDesign::upgradeOrder($order);
    }

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
