<?php
/**
 * Default template for the ctp_events output.
 * Override by copying this file to yourtheme/churchtools-plugin/event-list.php.
 *
 * @var array $events
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events ctp-events--<?php echo esc_attr($args['layout']); ?>">
    <?php if (empty($events)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <ul class="ctp-events__list">
            <?php foreach ($events as $event) : ?>
                <li class="ctp-events__item">
                    <span class="ctp-events__date">
                        <?php echo esc_html(mysql2date(get_option('date_format'), $event['start_date'])); ?>
                    </span>
                    <span class="ctp-events__title"><?php echo esc_html($event['title']); ?></span>
                    <?php if (!empty($event['location'])) : ?>
                        <span class="ctp-events__location"><?php echo esc_html($event['location']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
