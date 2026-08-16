<?php

/**
 * Default "list" layout template for the ctp_events output.
 * Override by copying this file to yourtheme/churchtools-plugin/event-list.php.
 *
 * @var array $events
 * @var array $args
 * @var array $filterCalendars
 */

use ChurchToolsPlugin\Frontend\ClickTrigger;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events ctp-events--list" style="<?php echo esc_attr($args['design_style']); ?>">
    <?php if ($args['show_toolbar']) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/toolbar.php'; ?>
    <?php endif; ?>
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <ul class="ctp-events__list">
            <?php $currentMonthKey = null; ?>
            <?php foreach ($events as $event) : ?>
                <?php if ($args['month_dividers'] && EventFormatter::monthKey($event['start_date']) !== $currentMonthKey) : ?>
                    <?php $currentMonthKey = EventFormatter::monthKey($event['start_date']); ?>
                    <li class="ctp-events__month-divider" data-ctp-month="<?php echo esc_attr($currentMonthKey); ?>">
                        <?php echo esc_html(EventFormatter::monthLabel($event['start_date'])); ?>
                    </li>
                <?php endif; ?>
                <li
                    class="ctp-events__item<?php echo $args['click_behavior'] !== 'none' ? ' ctp-events__item--clickable' : ''; ?>"
                    data-ctp-calendar="<?php echo esc_attr($event['ct_calendar_id']); ?>"
                    data-ctp-search="<?php echo esc_attr(mb_strtolower($event['title'] . ' ' . $event['subtitle'] . ' ' . $event['location'])); ?>"
                    <?php if ($event['calendar_color'] !== '') : ?>
                        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
                    <?php endif; ?>
                >
                    <?php if (!in_array('media', $args['hidden_elements'], true)) : ?>
                        <span class="ctp-events__date-chip" aria-hidden="true">
                            <span class="ctp-events__day">
                                <?php echo esc_html(EventFormatter::dayNumber($event['start_date'])); ?>
                            </span>
                            <span class="ctp-events__month">
                                <?php echo esc_html(EventFormatter::monthAbbreviation($event['start_date'])); ?>
                            </span>
                        </span>
                    <?php endif; ?>
                    <span class="ctp-events__body">
                        <?php if (!in_array('calendar', $args['hidden_elements'], true) && $event['calendar_name'] !== '') : ?>
                            <span class="ctp-events__eyebrow">
                                <?php if ($event['calendar_color'] !== '') : ?>
                                    <span class="ctp-events__color-dot" aria-hidden="true"></span>
                                <?php endif; ?>
                                <?php echo esc_html($event['calendar_name']); ?>
                            </span>
                        <?php endif; ?>
                        <span class="ctp-events__title">
                            <?php if ($event['calendar_name'] === '' && $event['calendar_color'] !== '') : ?>
                                <span class="ctp-events__color-dot" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ClickTrigger builds its own escaped attributes internally (esc_url()/esc_attr()), same trust boundary as Icons:: below. ?>
                            <?php echo ClickTrigger::open($event, $args['click_behavior']); ?>
                            <?php echo esc_html($event['title']); ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                            <?php echo ClickTrigger::close($args['click_behavior']); ?>
                            <?php if (!empty($event['all_day'])) : ?>
                                <span class="ctp-events__badge">
                                    <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <?php if (!in_array('subtitle', $args['hidden_elements'], true) && $event['subtitle'] !== '') : ?>
                            <span class="ctp-events__subtitle"><?php echo esc_html($event['subtitle']); ?></span>
                        <?php endif; ?>
                        <?php if (!in_array('meta', $args['hidden_elements'], true)) : ?>
                            <span class="ctp-events__meta">
                                <span class="ctp-events__meta-item">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::clock(); ?>
                                    <?php echo esc_html(EventFormatter::dateRange($event)); ?>
                                </span>
                                <?php if ($event['location'] !== '') : ?>
                                    <span class="ctp-events__meta-item">
                                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                        <?php echo Icons::location(); ?>
                                        <?php echo esc_html($event['location']); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!in_array('excerpt', $args['hidden_elements'], true) && $event['description'] !== '') : ?>
                            <p class="ctp-events__excerpt">
                                <?php echo esc_html(EventFormatter::excerpt($event['description'])); ?>
                            </p>
                        <?php endif; ?>
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup, same trust boundary as $args['design_style'] above. ?>
                        <?php echo $args['design_separators']; ?>
                    </span>
                    <?php if ($args['click_behavior'] === 'popup') : ?>
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- detail_html is this same event's fields already individually escaped by partials/event-detail-content.php, just pre-rendered server-side (see EventListRenderer::withCalendarMeta()). ?>
                        <template class="ctp-events__detail-template"><?php echo $event['detail_html']; ?></template>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($args['click_behavior'] === 'popup') : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
