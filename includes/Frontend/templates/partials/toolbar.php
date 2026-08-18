<?php

/**
 * Shared toolbar markup for the list/grid layouts — search input and/or
 * calendar filter select, both opt-in (shortcode/block/WPBakery attributes
 * "search"/"filter", see EventListRenderer::render()). Not part of the
 * theme-override contract (only event-{layout}.php files are looked up via
 * locate_template()); a theme wanting a different toolbar overrides the
 * whole layout template instead.
 *
 * @var array $args
 * @var array $filterCalendars
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ctp-events__toolbar">
    <?php if ($args['search']) : ?>
        <div class="ctp-events__search">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
            <?php echo Icons::search(); ?>
            <input
                type="search"
                class="ctp-events__search-input"
                data-ctp-search-config="<?php echo esc_attr((string) wp_json_encode($args['search_config'])); ?>"
                placeholder="<?php esc_attr_e('Termine durchsuchen …', 'churchtools-plugin'); ?>"
                aria-label="<?php esc_attr_e('Termine durchsuchen', 'churchtools-plugin'); ?>"
            />
        </div>
    <?php endif; ?>
    <?php if ($filterCalendars !== []) : ?>
        <select class="ctp-events__filter" aria-label="<?php esc_attr_e('Nach Kalender filtern', 'churchtools-plugin'); ?>">
            <option value=""><?php esc_html_e('Alle Kalender', 'churchtools-plugin'); ?></option>
            <?php foreach ($filterCalendars as $filterCalendar) : ?>
                <option value="<?php echo esc_attr($filterCalendar['id']); ?>">
                    <?php echo esc_html($filterCalendar['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <p class="ctp-events__toolbar-empty" hidden><?php esc_html_e('Keine Termine gefunden.', 'churchtools-plugin'); ?></p>
</div>
