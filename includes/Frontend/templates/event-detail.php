<?php

/**
 * "Own page" template for a single event's detail view, rendered by
 * EventDetailPage::maybeRenderDetail() between get_header()/get_footer().
 * Override by copying this file to yourtheme/churchtools-plugin/event-detail.php.
 *
 * @var array $event Already enriched via EventListRenderer::withCalendarMeta().
 * @var array $order Validated DetailDesign::ELEMENT_KEYS permutation.
 * @var string $backUrl
 * @var string $designClass Preset class from DesignPreset::bodyClass(), '' for the default preset.
 * @var string $designStyle CSS custom properties from CardDesign::styleAttribute(), same as the list layouts.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events ctp-events--detail <?php echo esc_attr($designClass); ?>" style="<?php echo esc_attr($designStyle); ?>">
    <p><a class="ctp-events__back" href="<?php echo esc_url($backUrl); ?>">&larr; <?php esc_html_e('Zurück', 'churchtools-plugin'); ?></a></p>
    <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-content.php'; ?>
</div>
