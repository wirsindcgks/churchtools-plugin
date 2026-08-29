<?php

/**
 * "Grid" layout template for the ctp_events output — card grid with a
 * user-configurable column count (shortcode/block attribute "columns", 2–6).
 * Override by copying this file to yourtheme/churchtools-plugin/event-grid.php.
 *
 * The cards themselves live in partials/event-grid-items.php so the load-more
 * endpoint can render further pages of identical markup — see that file's
 * docblock for what an override has to keep in mind.
 *
 * @var array $events
 * @var array $args
 * @var array $filterCalendars
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events ctp-events--grid <?php echo esc_attr($args['design_class']); ?>"
    style="--ctp-columns:<?php echo (int) $args['columns']; ?>;<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if ($args['eventfinder']) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/eventfinder.php'; ?>
    <?php elseif ($args['show_toolbar']) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/toolbar.php'; ?>
    <?php endif; ?>
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div class="ctp-events__list" role="list">
            <?php $currentMonthKey = null; ?>
            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-grid-items.php'; ?>
        </div>
        <?php if ($args['paging']) : ?>
            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/load-more.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($args['click_behavior'] === 'popup') : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
