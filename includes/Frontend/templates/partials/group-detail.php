<?php

/**
 * Inhalt des Gruppen-Popups im Raster (group-grid.php). Steht je Kachel in
 * einem <template> und wird beim Klick in den gemeinsamen Dialog kopiert -
 * derselbe Weg wie beim Termin-Popup (partials/modal.php, frontend.js).
 *
 * Dieselben Klassen wie die Detailansicht eines Termins, damit Groessen,
 * Abstaende und Bildrahmen gleich aussehen; es fehlt nur der Datums-Chip.
 * Die Kennung des Titels bekommt einen Zusatz: Das Template landet als Kopie
 * im Dialog, waehrend die Kachel mit ihrer eigenen Kennung stehen bleibt.
 *
 * @var array  $group
 * @var string $titleId
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$ctpCardTitleId = $titleId;
$titleId = $titleId . '-detail';
?>
<div class="ctp-events__detail ctp-groups__detail<?php echo $group['image_src'] === '' ? ' ctp-events__detail--no-media' : ''; ?>">
    <?php if ($group['image_src'] !== '') : ?>
        <div class="ctp-events__detail-media">
            <div class="ctp-events__detail-media-frame">
                <img
                    src="<?php echo esc_url($group['image_src']); ?>"
                    <?php if ($group['image_srcset_full'] !== '') : ?>
                        srcset="<?php echo esc_attr($group['image_srcset_full']); ?>"
                        sizes="<?php echo esc_attr(CardImage::detailSizes()); ?>"
                    <?php endif; ?>
                    alt=""
                    class="skip-lazy"
                    data-no-lazy="1"
                    loading="eager"
                />
            </div>
        </div>
    <?php endif; ?>
    <div class="ctp-events__detail-heading">
        <h2 class="ctp-events__detail-title" id="<?php echo esc_attr($titleId); ?>">
            <?php echo esc_html($group['name']); ?>
            <?php if ($group['places_label'] !== '') : ?>
                <span class="ctp-events__badge"><?php echo esc_html($group['places_label']); ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <?php if ($group['schedule'] !== '') : ?>
        <p class="ctp-events__meta-item ctp-events__meta-item--time">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
            <?php echo Icons::clock(); ?>
            <?php echo esc_html($group['schedule']); ?>
        </p>
    <?php endif; ?>
    <?php if ($group['target_group_label'] !== '') : ?>
        <p class="ctp-events__meta-item ctp-events__meta-item--target-group">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
            <?php echo Icons::person(); ?>
            <?php echo esc_html($group['target_group_label']); ?>
        </p>
    <?php endif; ?>
    <?php if ($group['description_html'] !== '') : ?>
        <div class="ctp-events__detail-description">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses() with its own allowlist before adding any markup of its own (see its docblock). ?>
            <?php echo $group['description_html']; ?>
        </div>
    <?php endif; ?>
    <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/group-cta.php'; ?>
</div>
<?php
$titleId = $ctpCardTitleId;
