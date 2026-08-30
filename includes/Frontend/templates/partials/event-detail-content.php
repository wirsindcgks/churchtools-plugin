<?php

/**
 * Single-event detail content, shared by the "own page" template
 * (event-detail.php) and the popup <template> embedded per card in
 * event-list.php/event-grid.php/event-upcoming.php. Renders the
 * DetailDesign::ELEMENT_KEYS in the admin-configured order (see
 * SettingsPage::get()['detail_element_order']) — no CSS `order` trick here
 * since this is a single event, not a repeated list item (see DetailDesign
 * docblock).
 *
 * Two groupings of the same elements, chosen by $detailContext:
 *
 *   popup — flat, every element a direct child of .ctp-events__detail, which
 *           lays them out as a single wrapping column. The configured order is
 *           reproduced one-to-one.
 *   page  — the elements are grouped into .ctp-events__detail-lead (calendar
 *           badge, title, subtitle) and .ctp-events__detail-facts (date, time,
 *           location), with image and description outside both. That is what the two-column layout of
 *           the own page needs: the image sits beside the whole heading block
 *           rather than between two of its lines, and the description runs the
 *           full width underneath. The configured order still decides the
 *           sequence *within* each group — what it no longer decides there is
 *           where the image and the description sit relative to the rest,
 *           because on that page the layout answers that, not the order.
 *
 * @var array  $event         Already enriched via EventListRenderer::withCalendarMeta().
 * @var array  $order         Validated DetailDesign::ELEMENT_KEYS permutation.
 * @var string $detailContext 'popup' or 'page', set by EventListRenderer.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Der Kontext kommt aus EventListRenderer, nicht aus dem Template darüber —
// deshalb bekommt auch ein Theme mit einer alten event-detail.php-Kopie das
// Seitenlayout. Der Rückfall hier gilt dem Fall, dass fremder Code dieses
// Partial direkt einbindet: Die flache Fassung steht in jedem Container für
// sich, die zweispaltige braucht .ctp-events--detail um sich herum.
$detailContext = isset($detailContext) && $detailContext === 'page' ? 'page' : 'popup';

$ctpElement = CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-element.php';

/** @var callable(string[]): string[] $ctpKeysIn */
$ctpKeysIn = static fn (array $group): array => array_values(
    array_filter($order, static fn (string $key): bool => in_array($key, $group, true))
);
?>
<div
    class="ctp-events__detail<?php echo $event['image_url'] === '' ? ' ctp-events__detail--no-media' : ''; ?>"
    <?php if ($event['calendar_color'] !== '') : ?>
        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
    <?php endif; ?>
>
    <?php if ($detailContext === 'page') : ?>
        <div class="ctp-events__detail-lead">
            <?php foreach ($ctpKeysIn(['calendar', 'title', 'subtitle']) as $key) : ?>
                <?php require $ctpElement; ?>
            <?php endforeach; ?>
        </div>
        <div class="ctp-events__detail-facts">
            <?php foreach ($ctpKeysIn(['date', 'time', 'location']) as $key) : ?>
                <?php require $ctpElement; ?>
            <?php endforeach; ?>
        </div>
        <?php foreach ($ctpKeysIn(['media', 'description']) as $key) : ?>
            <?php require $ctpElement; ?>
        <?php endforeach; ?>
    <?php else : ?>
        <?php foreach ($order as $key) : ?>
            <?php require $ctpElement; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
