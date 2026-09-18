<?php

/**
 * Hervorgehobene Gruppen ([ctp_groups layout="featured"]): je Gruppe eine
 * grosse Kachel, Bild neben dem Text, auf schmalem Platz darueber
 * (plan.md, G2). Gedacht fuer wenige, einzeln ausgewaehlte Gruppen, geht aber
 * ebenso mit einer ganzen Homepage. Ueberschreibbar durch eine Kopie unter
 * yourtheme/churchtools-plugin/group-featured.php.
 *
 * Dieselben Design-Variablen wie das Raster. Der Text ist der Auszug der
 * Hero-Kachel von „Nächster Termin" (`feature_excerpt_html`, gleiche Wortzahl, gleiche
 * Klasse mit drei Zeilen): So bestimmt wie dort das Bild die Kachelhoehe
 * (Nutzerwunsch 2026-09-18), der ganze Text steht im Popup.
 *
 * Ein Klick auf die Kachel oeffnet das Popup wie bei der Hero-Kachel der
 * Termine und im Raster (partials/group-detail.php, partials/modal.php); nach
 * ChurchTools fuehrt der Button (partials/group-cta.php). Die Zelle um die
 * Kachel ist die Einheit, in der frontend.js das <template> sucht.
 *
 * @var array $groups
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events ctp-groups ctp-groups--featured <?php echo esc_attr($args['design_class']); ?>"
    style="<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if (empty($groups)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Zurzeit sind keine Gruppen eingetragen.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div class="ctp-groups__features" role="list">
            <?php foreach ($groups as $index => $group) : ?>
                <?php $titleId = $args['instance'] . '-' . (int) $index; ?>
                <div class="ctp-events__cell" role="listitem">
                <article class="ctp-events__card ctp-events__hero--clickable ctp-groups__feature<?php echo $group['image_src'] === '' ? ' ctp-groups__feature--no-media' : ''; ?>">
                    <?php if ($group['image_src'] !== '') : ?>
                        <div class="ctp-events__media ctp-groups__feature-media">
                            <img
                                src="<?php echo esc_url($group['image_src']); ?>"
                                <?php if ($group['image_srcset'] !== '') : ?>
                                    srcset="<?php echo esc_attr($group['image_srcset']); ?>"
                                    sizes="(min-width: 46rem) 40vw, 100vw"
                                <?php endif; ?>
                                alt=""
                                loading="lazy"
                            />
                        </div>
                    <?php endif; ?>
                    <div class="ctp-events__content ctp-groups__feature-content">
                        <h3 class="ctp-events__title ctp-groups__feature-title">
                            <?php // Ein Verweis und kein Knopf: Ohne JavaScript fuehrt er nach ChurchTools, wie der Button unten. ?>
                            <a class="ctp-events__card-trigger" data-ctp-modal="1" href="<?php echo esc_url($group['url']); ?>">
                                <span id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($group['name']); ?></span>
                            </a>
                            <?php if ($group['places_label'] !== '') : ?>
                                <span class="ctp-events__badge"><?php echo esc_html($group['places_label']); ?></span>
                            <?php endif; ?>
                        </h3>
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
                        <?php if ($group['feature_excerpt_html'] !== '') : ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- GroupListRenderer::featureExcerptHtml() runs the note through EventFormatter::descriptionHtml() (wp_kses() with its own allowlist, obfuscated mail addresses). ?>
                            <p class="ctp-events__excerpt"><?php echo $group['feature_excerpt_html']; ?></p>
                        <?php endif; ?>
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
