<?php

/**
 * Shared filter-bar markup for the list/grid layouts — extracted since both
 * templates render the exact same select. Not part of the theme-override contract
 * (only event-{layout}.php files are looked up via locate_template()); a theme
 * wanting a different filter control overrides the whole layout template instead.
 *
 * @var array $filterCalendars
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events__filter-bar">
    <select class="ctp-events__filter" aria-label="<?php esc_attr_e('Nach Kalender filtern', 'churchtools-plugin'); ?>">
        <option value=""><?php esc_html_e('Alle Kalender', 'churchtools-plugin'); ?></option>
        <?php foreach ($filterCalendars as $filterCalendar) : ?>
            <option value="<?php echo esc_attr($filterCalendar['id']); ?>">
                <?php echo esc_html($filterCalendar['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
