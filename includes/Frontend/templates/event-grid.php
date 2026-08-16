<?php

/**
 * "Grid" layout template for the ctp_events output — card grid with a
 * user-configurable column count (shortcode/block attribute "columns", 2–6).
 * Override by copying this file to yourtheme/churchtools-plugin/event-grid.php.
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
<div
    class="ctp-events ctp-events--grid"
    style="--ctp-columns:<?php echo (int) $args['columns']; ?>;<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if ($filterCalendars !== []) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/filter-bar.php'; ?>
    <?php endif; ?>
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <ul class="ctp-events__list">
            <?php foreach ($events as $event) : ?>
                <li data-ctp-calendar="<?php echo esc_attr($event['ct_calendar_id']); ?>">
                    <article
                        class="ctp-events__card<?php echo $args['click_behavior'] !== 'none' ? ' ctp-events__card--clickable' : ''; ?>"
                        <?php if ($event['calendar_color'] !== '') : ?>
                            style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
                        <?php endif; ?>
                    >
                        <div class="ctp-events__media">
                            <?php if ($event['image_url'] !== '') : ?>
                                <img src="<?php echo esc_url($event['image_url']); ?>" alt="" loading="lazy" />
                            <?php endif; ?>
                            <span class="ctp-events__date-badge" aria-hidden="true">
                                <span class="ctp-events__day">
                                    <?php echo esc_html(EventFormatter::dayNumber($event['start_date'])); ?>
                                </span>
                                <span class="ctp-events__month">
                                    <?php echo esc_html(EventFormatter::monthAbbreviation($event['start_date'])); ?>
                                </span>
                            </span>
                        </div>
                        <div class="ctp-events__content">
                            <?php if ($event['calendar_name'] !== '') : ?>
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
                            <?php if ($event['subtitle'] !== '') : ?>
                                <span class="ctp-events__subtitle"><?php echo esc_html($event['subtitle']); ?></span>
                            <?php endif; ?>
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
                            <?php if ($event['description'] !== '') : ?>
                                <p class="ctp-events__excerpt">
                                    <?php echo esc_html(EventFormatter::excerpt($event['description'])); ?>
                                </p>
                            <?php endif; ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup, same trust boundary as $args['design_style'] above. ?>
                            <?php echo $args['design_separators']; ?>
                        </div>
                    </article>
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
