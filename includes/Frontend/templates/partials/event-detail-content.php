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
 *   page  — everything except image and description moves into one wrapper,
 *           .ctp-events__detail-text, in the configured order. That wrapper is
 *           the left column of the two-column layout: the image sits beside the
 *           whole block rather than between two of its lines, and the
 *           description runs the full width underneath. Only for those two does
 *           the layout override the configured position — everything else keeps
 *           it exactly.
 *
 *           1.4.0 hatte diesen Block noch nach Art sortiert, in „Kopf" und
 *           „Eckdaten". Das hat die eingestellte Reihenfolge still überstimmt:
 *           Ein Kalender-Etikett, das im Design-Tab ganz nach hinten gezogen
 *           war, tauchte wieder zwischen Titel und Datum auf, weil es als
 *           „Kopf" zählte. Eine Sortierung nach Art ist eine zweite Meinung zu
 *           einer Reihenfolge, die der Betreiber bereits angegeben hat — eine
 *           einzige Hülle hat keine.
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

/** @var callable(string[]): string[] $ctpKeysOutside */
$ctpKeysOutside = static fn (array $group): array => array_values(
    array_filter($order, static fn (string $key): bool => !in_array($key, $group, true))
);
?>
<div
    class="ctp-events__detail<?php echo $event['image_url'] === '' ? ' ctp-events__detail--no-media' : ''; ?>"
    <?php if ($event['calendar_color'] !== '') : ?>
        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
    <?php endif; ?>
>
    <?php if ($detailContext === 'page') : ?>
        <div class="ctp-events__detail-text">
            <?php foreach ($ctpKeysOutside(['media', 'description']) as $key) : ?>
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
