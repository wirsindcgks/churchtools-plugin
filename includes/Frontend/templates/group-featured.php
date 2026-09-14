<?php

/**
 * Hervorgehobene Gruppen ([ctp_groups layout="featured"]): je Gruppe eine
 * grosse Kachel, Bild neben dem vollen Text, auf schmalem Platz darueber
 * (plan.md, G2). Gedacht fuer wenige, einzeln ausgewaehlte Gruppen, geht aber
 * ebenso mit einer ganzen Homepage. Ueberschreibbar durch eine Kopie unter
 * yourtheme/churchtools-plugin/group-featured.php.
 *
 * Dieselben Design-Variablen wie das Raster; statt des Auszugs der ganze Text
 * (`description_html`, aufbereitet wie eine Terminbeschreibung).
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
                <article class="ctp-events__card ctp-groups__feature<?php echo $group['image_src'] === '' ? ' ctp-groups__feature--no-media' : ''; ?>" role="listitem">
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
                            <span id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($group['name']); ?></span>
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
                        <?php if ($group['description_html'] !== '') : ?>
                            <div class="ctp-groups__feature-text">
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses() with its own allowlist before adding any markup of its own (see its docblock). ?>
                                <?php echo $group['description_html']; ?>
                            </div>
                        <?php endif; ?>
                        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/group-cta.php'; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
