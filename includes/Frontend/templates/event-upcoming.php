<?php

/**
 * "Upcoming" layout template for the ctp_events output — a large hero card for the
 * very next event, plus a compact list of the events after it (if the shortcode's
 * "limit" is greater than 1).
 * Override by copying this file to yourtheme/churchtools-plugin/event-upcoming.php.
 *
 * @var array $events
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\EventFormatter;

if (!defined('ABSPATH')) {
    exit;
}

$hero = $events[0] ?? null;
$upcoming = array_slice($events, 1);
?>
<div class="ctp-events ctp-events--upcoming">
    <?php if ($hero === null) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div
            class="ctp-events__hero"
            <?php if ($hero['calendar_color'] !== '') : ?>
                style="--ctp-accent:<?php echo esc_attr($hero['calendar_color']); ?>;"
            <?php endif; ?>
        >
            <div class="ctp-events__hero-media">
                <?php if ($hero['image_url'] !== '') : ?>
                    <img src="<?php echo esc_url($hero['image_url']); ?>" alt="" loading="lazy" />
                <?php endif; ?>
            </div>
            <div class="ctp-events__hero-body">
                <span class="ctp-events__eyebrow">
                    <?php
                    $eyebrow = $hero['calendar_name'] !== ''
                        ? $hero['calendar_name']
                        : __('Nächster Termin', 'churchtools-plugin');
                    echo esc_html($eyebrow);
                    ?>
                </span>
                <h3 class="ctp-events__hero-title"><?php echo esc_html($hero['title']); ?></h3>
                <?php if ($hero['subtitle'] !== '') : ?>
                    <p class="ctp-events__subtitle"><?php echo esc_html($hero['subtitle']); ?></p>
                <?php endif; ?>
                <p class="ctp-events__hero-meta">
                    <span>
                        <?php echo esc_html(EventFormatter::dateRange($hero)); ?>
                        <?php if (!empty($hero['all_day'])) : ?>
                            <span class="ctp-events__badge">
                                <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php if ($hero['location'] !== '') : ?>
                        <span><?php echo esc_html($hero['location']); ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($upcoming !== []) : ?>
            <p class="ctp-events__more-label"><?php esc_html_e('Weitere Termine', 'churchtools-plugin'); ?></p>
            <ul class="ctp-events__list">
                <?php foreach ($upcoming as $event) : ?>
                    <li
                        class="ctp-events__item"
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
                            <span class="ctp-events__title">
                                <?php echo esc_html($event['title']); ?>
                                <?php if (!empty($event['all_day'])) : ?>
                                    <span class="ctp-events__badge">
                                        <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="ctp-events__meta">
                                <span><?php echo esc_html(EventFormatter::dateRange($event)); ?></span>
                                <?php if ($event['location'] !== '') : ?>
                                    <span><?php echo esc_html($event['location']); ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
