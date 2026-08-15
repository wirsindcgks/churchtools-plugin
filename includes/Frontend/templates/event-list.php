<?php

/**
 * Default "list" layout template for the ctp_events output.
 * Override by copying this file to yourtheme/churchtools-plugin/event-list.php.
 *
 * @var array $events
 * @var array $args
 * @var array $filterCalendars
 */

use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events ctp-events--list" style="<?php echo esc_attr($args['design_style']); ?>">
    <?php if ($filterCalendars !== []) : ?>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/filter-bar.php'; ?>
    <?php endif; ?>
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <ul class="ctp-events__list">
            <?php foreach ($events as $event) : ?>
                <li
                    class="ctp-events__item"
                    data-ctp-calendar="<?php echo esc_attr($event['ct_calendar_id']); ?>"
                    <?php if ($event['calendar_color'] !== '') : ?>
                        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
                    <?php endif; ?>
                >
                    <span class="ctp-events__date-chip" aria-hidden="true">
                        <span class="ctp-events__day">
                            <?php echo esc_html(EventFormatter::dayNumber($event['start_date'])); ?>
                        </span>
                        <span class="ctp-events__month">
                            <?php echo esc_html(EventFormatter::monthAbbreviation($event['start_date'])); ?>
                        </span>
                    </span>
                    <span class="ctp-events__body">
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
                            <?php echo esc_html($event['title']); ?>
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
                                <?php echo Icons::clock(); ?>
                                <?php echo esc_html(EventFormatter::dateRange($event)); ?>
                            </span>
                            <?php if ($event['location'] !== '') : ?>
                                <span class="ctp-events__meta-item">
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
                        <?php echo $args['design_separators']; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
