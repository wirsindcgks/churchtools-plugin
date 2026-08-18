<?php

/**
 * Guided "Du suchst …" toolbar for the list/grid layouts — button-based
 * shortcuts for calendar and timeframe, plus a search field, in place of the
 * plain filter dropdown/search input from partials/toolbar.php (opt-in via
 * the shortcode/block/WPBakery "eventfinder" attribute, see
 * EventListRenderer::render()). Not part of the theme-override contract
 * (only event-{layout}.php files are looked up via locate_template()); a
 * theme wanting a different toolbar overrides the whole layout template
 * instead.
 *
 * Buttons toggle a "ctp-events__finder-btn--active" state and drive the
 * client-side filtering in assets/js/frontend.js (applyToolbarState()) —
 * same data-ctp-calendar/data-ctp-search attributes as the plain toolbar,
 * plus data-ctp-start (Y-m-d) on each item for the timeframe buttons.
 *
 * @var array $args
 * @var array $filterCalendars
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/*
 * The two section headings ("Thema"/"Zeitraum") double as the accessible name
 * of the button group under each — aria-labelledby rather than a hard-coded
 * aria-label, so screen readers announce the same words that are on screen.
 * IDs come from wp_unique_id() because a page can carry more than one
 * [ctp_events eventfinder="1"] instance and duplicate IDs would point every
 * group at the first one's heading.
 */
$topicHeadingId = wp_unique_id('ctp-finder-topic-');
$timeframeHeadingId = wp_unique_id('ctp-finder-timeframe-');
?>
<div class="ctp-events__toolbar ctp-events__eventfinder">
    <p class="ctp-events__finder-label"><?php esc_html_e('Du suchst …', 'churchtools-plugin'); ?></p>
    <?php if ($filterCalendars !== []) : ?>
        <div class="ctp-events__finder-row">
            <span class="ctp-events__finder-row-label" id="<?php echo esc_attr($topicHeadingId); ?>">
                <?php esc_html_e('Thema', 'churchtools-plugin'); ?>
            </span>
            <div
                class="ctp-events__finder-group"
                role="group"
                aria-labelledby="<?php echo esc_attr($topicHeadingId); ?>"
            >
                <button
                    type="button"
                    class="ctp-events__finder-btn ctp-events__finder-btn--active"
                    data-ctp-finder-calendar=""
                    aria-pressed="true"
                ><?php esc_html_e('Alle', 'churchtools-plugin'); ?></button>
                <?php foreach ($filterCalendars as $filterCalendar) : ?>
                    <button
                        type="button"
                        class="ctp-events__finder-btn"
                        data-ctp-finder-calendar="<?php echo esc_attr($filterCalendar['id']); ?>"
                        aria-pressed="false"
                    ><?php echo esc_html($filterCalendar['name']); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="ctp-events__finder-row">
        <span class="ctp-events__finder-row-label" id="<?php echo esc_attr($timeframeHeadingId); ?>">
            <?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?>
        </span>
        <div
            class="ctp-events__finder-group"
            role="group"
            aria-labelledby="<?php echo esc_attr($timeframeHeadingId); ?>"
        >
            <button
                type="button"
                class="ctp-events__finder-btn ctp-events__finder-btn--active"
                data-ctp-finder-timeframe=""
                aria-pressed="true"
            ><?php esc_html_e('Jederzeit', 'churchtools-plugin'); ?></button>
            <button
                type="button"
                class="ctp-events__finder-btn"
                data-ctp-finder-timeframe="week"
                aria-pressed="false"
            ><?php esc_html_e('Diese Woche', 'churchtools-plugin'); ?></button>
            <button
                type="button"
                class="ctp-events__finder-btn"
                data-ctp-finder-timeframe="weekend"
                aria-pressed="false"
            ><?php esc_html_e('Dieses Wochenende', 'churchtools-plugin'); ?></button>
            <button
                type="button"
                class="ctp-events__finder-btn"
                data-ctp-finder-timeframe="month"
                aria-pressed="false"
            ><?php esc_html_e('Diesen Monat', 'churchtools-plugin'); ?></button>
        </div>
    </div>
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
    <p class="ctp-events__toolbar-empty" hidden><?php esc_html_e('Keine Termine gefunden.', 'churchtools-plugin'); ?></p>
</div>
