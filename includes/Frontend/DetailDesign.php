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
 *
 * "share" ist der einzige Schlüssel, der nicht immer etwas ausgibt: Ob der
 * „Teilen"-Knopf erscheint, entscheidet die eigene Einstellung
 * `detail_share_enabled` (Design-Tab), nicht seine Anwesenheit in der
 * Reihenfolge. Er steht trotzdem *fest* im Schlüsselsatz und wird nicht bei
 * Bedarf ein- und ausgetragen — dadurch bleiben Drag&Drop, „Standard
 * wiederherstellen" und die Vorschau in assets/js/admin-design.js unverändert,
 * die alle einen festen Schlüsselsatz voraussetzen. Ein Schlüssel, den es mal
 * gibt und mal nicht, hätte jede dieser drei Mechaniken angefasst.
 */
final class DetailDesign
{
    public const ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description', 'share'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;

    /**
     * Der Schlüssel, den es vor 1.17.0 noch nicht gab. Steht hier als Konstante,
     * weil ihn drei Stellen kennen müssen: das Anhängen unten, das Überspringen
     * in partials/event-detail-content.php und die Vorschau im Design-Tab.
     */
    public const SHARE_KEY = 'share';

    /**
     * Same widening CardDesign::upgradeOrder() does for the card order, for
     * the detail view's own key set — date, time and location replaced the
     * single "meta" entry here too, and both orders are stored per site, so
     * both can still arrive on the old shape long after an update.
     *
     * Dazu die zweite Verbreiterung, die es nur hier gibt: Jede vor 1.17.0
     * gespeicherte Reihenfolge kennt "share" nicht, und isValidOrder() prüft
     * auf eine *vollständige* Permutation — ohne das Anhängen fiele damit jede
     * Bestandsseite auf DEFAULT_ORDER zurück und verlöre ihre eingestellte
     * Anordnung. Angehängt wird ans Ende: Der Knopf gehört unter den Termin,
     * nicht zwischen dessen Angaben. Läuft wie die Meta-Verbreiterung bei
     * jedem Lesen (SettingsPage::get()), nicht als einmalige Migration.
     *
     * @param string[] $order
     *
     * @return string[]
     */
    public static function upgradeOrder(array $order): array
    {
        $order = CardDesign::upgradeOrder($order);

        if (!in_array(self::SHARE_KEY, $order, true)) {
            $order[] = self::SHARE_KEY;
        }

        return $order;
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
