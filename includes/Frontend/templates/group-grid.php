<?php

/**
 * Kachelraster der Gruppenliste ([ctp_groups], Block, WPBakery). Ueberschreibbar
 * durch eine Kopie unter yourtheme/churchtools-plugin/group-grid.php.
 *
 * Dieselben Klassen wie event-grid.php, damit die Einstellungen des Design-Tabs
 * greifen; `ctp-groups` steht zusaetzlich daneben, fuer eigene Regeln eines
 * Themes. Die Zusatzfelder jeder Gruppe (image_src, show_media, schedule,
 * places_label, excerpt, excerpt_html, target_group_label) rechnet
 * GroupListRenderer::prepareGroups() vor.
 *
 * Mit `finder` und/oder `search` steht die Werkzeugleiste darueber
 * (partials/group-finder.php); sie filtert ueber die data-ctp-group-*-Attribute
 * der Zellen.
 *
 * Ein Klick auf die Kachel oeffnet den ganzen Text im Popup
 * (partials/group-detail.php, partials/modal.php); nach ChurchTools fuehrt der
 * Button darunter (partials/group-cta.php, siehe GroupListRenderer).
 *
 * @var array $groups
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events ctp-events--grid ctp-groups <?php echo esc_attr($args['design_class']); ?>"
    style="--ctp-columns:<?php echo (int) $args['columns']; ?>;<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if (!empty($args['show_toolbar'])) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/group-finder.php'; ?>
    <?php endif; ?>
    <?php if (empty($groups)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Zurzeit sind keine Gruppen eingetragen.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div class="ctp-events__list" role="list">
            <?php foreach ($groups as $index => $group) : ?>
                <?php $titleId = $args['instance'] . '-' . (int) $index; ?>
                <div
                    class="ctp-events__cell"
                    role="listitem"
                    <?php if (!empty($args['show_toolbar'])) : ?>
                        data-ctp-group-category="<?php echo esc_attr($group['finder_category']); ?>"
                        data-ctp-group-weekday="<?php echo esc_attr($group['finder_weekday']); ?>"
                        data-ctp-group-target="<?php echo esc_attr($group['finder_target']); ?>"
                        data-ctp-group-search="<?php echo esc_attr($group['finder_search']); ?>"
                    <?php endif; ?>
                >
                    <article class="ctp-events__card ctp-events__card--clickable ctp-groups__card">
                        <?php if ($group['show_media']) : ?>
                            <div class="ctp-events__media">
                                <?php if ($group['image_src'] !== '') : ?>
                                <img
                                    src="<?php echo esc_url($group['image_src']); ?>"
                                    <?php if ($group['image_srcset'] !== '') : ?>
                                        srcset="<?php echo esc_attr($group['image_srcset']); ?>"
                                        sizes="<?php echo esc_attr(CardImage::gridSizes((int) $args['columns'])); ?>"
                                    <?php endif; ?>
                                    alt=""
                                    loading="lazy"
                                />
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="ctp-events__content">
                            <span class="ctp-events__title">
                                <?php
                                // Auslöser des Popups ueber die ganze Kachel (wie ClickTrigger bei
                                // den Terminen). Ein Verweis und kein Knopf: Ohne JavaScript fuehrt er
                                // nach ChurchTools, wie der Button unten.
                                ?>
                                <a class="ctp-events__card-trigger" data-ctp-modal="1" href="<?php echo esc_url($group['url']); ?>">
                                    <span id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($group['name']); ?></span>
                                </a>
                                <?php if ($group['places_label'] !== '') : ?>
                                    <span class="ctp-events__badge"><?php echo esc_html($group['places_label']); ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($group['schedule'] !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--time">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::clock(); ?>
                                    <?php echo esc_html($group['schedule']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($group['target_group_label'] !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--target-group">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::person(); ?>
                                    <?php echo esc_html($group['target_group_label']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($group['excerpt_html'] !== '') : ?>
                                <div class="ctp-events__excerpt ctp-groups__excerpt">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- GroupListRenderer::excerptHtml() runs the note through EventFormatter::descriptionHtml() (wp_kses() with its own allowlist) or esc_html(). ?>
                                    <?php echo $group['excerpt_html']; ?>
                                </div>
                            <?php endif; ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup. ?>
                            <?php echo $args['design_separators']; ?>
                            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/group-cta.php'; ?>
                        </div>
                    </article>
                    <template class="ctp-events__detail-template"><?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/group-detail.php'; ?></template>
                </div>
            <?php endforeach; ?>
        </div>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
