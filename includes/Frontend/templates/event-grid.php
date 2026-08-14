<?php

/**
 * "Grid" layout template for the ctp_events output — card grid with a
 * user-configurable column count (shortcode/block attribute "columns", 2–6).
 * Override by copying this file to yourtheme/churchtools-plugin/event-grid.php.
 *
 * @var array $events
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\EventFormatter;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events ctp-events--grid" style="--ctp-columns:<?php echo (int) $args['columns']; ?>;">
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <ul class="ctp-events__list">
            <?php foreach ($events as $event) : ?>
                <li>
                    <article
                        class="ctp-events__card"
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
                            <span class="ctp-events__title">
                                <?php if ($event['calendar_color'] !== '') : ?>
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
                                <span><?php echo esc_html(EventFormatter::dateRange($event)); ?></span>
                                <?php if ($event['location'] !== '') : ?>
                                    <span><?php echo esc_html($event['location']); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
