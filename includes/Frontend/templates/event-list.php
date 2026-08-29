<?php

/**
 * Default "list" layout template for the ctp_events output.
 * Override by copying this file to yourtheme/churchtools-plugin/event-list.php.
 *
 * The items themselves live in partials/event-list-items.php so the load-more
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
<div class="ctp-events ctp-events--list <?php echo esc_attr($args['design_class']); ?>" style="<?php echo esc_attr($args['design_style']); ?>">
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
            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-list-items.php'; ?>
        </div>
        <?php if ($args['paging']) : ?>
            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/load-more.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($args['click_behavior'] === 'popup') : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
