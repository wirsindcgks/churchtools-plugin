<?php

/**
 * "Upcoming" layout template for the ctp_events output — a large hero card for the
 * very next event, plus a compact list of the events after it (if the shortcode's
 * "limit" is greater than 1).
 * Override by copying this file to yourtheme/churchtools-plugin/event-upcoming.php.
 *
 * With the "popup" click behavior, an event's <template class="ctp-events__detail-template">
 * has to stay inside the unit its trigger sits in — the hero <div> for the hero, the
 * <li> for each item below it. openDetailModal() in assets/js/frontend.js resolves it
 * from there (closest('li, .ctp-events__hero')), so a template placed next to the unit
 * rather than inside it leaves the click with nothing to open.
 *
 * @var array $events
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\ClickTrigger;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$hero = $events[0] ?? null;
$upcoming = array_slice($events, 1);

/*
 * Ohne Bild gibt es keine Bildzelle - und damit auch keine zweite Spalte, in
 * der sonst eine leere Flaeche stuende. Solange dort ein Farbverlauf lag, sah
 * die leere Zelle nach Absicht aus; auf ruhigem Kachelgrund (siehe
 * frontend.css) waere sie einfach ein Loch neben dem Text.
 */
$heroHasMedia = $hero !== null
    && $hero['image_url'] !== ''
    && !in_array('media', $args['hidden_elements'], true);
?>
<div class="ctp-events ctp-events--upcoming" style="<?php echo esc_attr($args['design_style']); ?>">
    <?php if ($hero === null) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Keine anstehenden Termine.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div
            class="ctp-events__hero<?php echo $args['click_behavior'] !== 'none' ? ' ctp-events__hero--clickable' : ''; ?><?php echo $heroHasMedia ? '' : ' ctp-events__hero--no-media'; ?>"
            <?php if ($hero['calendar_color'] !== '') : ?>
                style="--ctp-accent:<?php echo esc_attr($hero['calendar_color']); ?>;"
            <?php endif; ?>
        >
            <?php if ($heroHasMedia) : ?>
                <div class="ctp-events__hero-media<?php echo $hero['image_is_fallback'] ? ' ctp-events__hero-media--fallback' : ''; ?>">
                    <img src="<?php echo esc_url($hero['image_url']); ?>" alt="" loading="lazy" />
                </div>
            <?php endif; ?>
            <?php
            /*
             * Chip und Angaben stehen zusammen in einer Gruppe, statt jeder
             * fuer sich im Kachelgitter: Nur so laesst sich beides gemeinsam
             * senkrecht mittig stellen und der Chip trotzdem auf der Linie
             * der ersten Textzeile halten - zwei getrennte Gitterzellen
             * kennen die Hoehe der jeweils anderen nicht. Die Gruppe
             * uebernimmt per subgrid die Spalten der Kachel, damit die
             * Angaben weiter exakt so breit bleiben wie das Bild.
             */
            ?>
            <div class="ctp-events__hero-main">
                <?php
                /*
                 * Eigene Spalte links, nicht mehr im Textteil: Datums-Chip,
                 * daneben die Angaben zum Termin, dann das Bild. Der Chip steht
                 * dabei auf der Linie der ersten Textzeile (siehe
                 * .ctp-events__hero-main in frontend.css). An "media" gebunden wie
                 * in jeder anderen Ansicht - wer den Bildbereich abwaehlt, waehlt
                 * den Chip mit ab, die Datumszeile im Text bleibt.
                 */
                ?>
                <?php if (!in_array('media', $args['hidden_elements'], true)) : ?>
                    <span class="ctp-events__date-chip ctp-events__date-chip--hero" aria-hidden="true">
                        <span class="ctp-events__day">
                            <?php echo esc_html(EventFormatter::dayNumber($hero['start_date'])); ?>
                        </span>
                        <span class="ctp-events__month">
                            <?php echo esc_html(EventFormatter::monthAbbreviation($hero['start_date'])); ?>
                        </span>
                    </span>
                <?php endif; ?>
                <div class="ctp-events__hero-body">
                    <?php if (!in_array('calendar', $args['hidden_elements'], true)) : ?>
                        <span class="ctp-events__eyebrow">
                            <?php
                            $eyebrow = $hero['calendar_name'] !== ''
                                ? $hero['calendar_name']
                                : __('Nächster Termin', 'churchtools-plugin');
                            echo esc_html($eyebrow);
                            ?>
                        </span>
                    <?php endif; ?>
                    <h3 class="ctp-events__hero-title">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ClickTrigger builds its own escaped attributes internally (esc_url()/esc_attr()), same trust boundary as Icons:: below. ?>
                        <?php echo ClickTrigger::open($hero, $args['click_behavior']); ?>
                        <?php echo esc_html($hero['title']); ?>
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                        <?php echo ClickTrigger::close($args['click_behavior']); ?>
                    </h3>
                    <?php if (!in_array('subtitle', $args['hidden_elements'], true) && $hero['subtitle'] !== '') : ?>
                        <p class="ctp-events__subtitle"><?php echo esc_html($hero['subtitle']); ?></p>
                    <?php endif; ?>
                    <?php if (!in_array('date', $args['hidden_elements'], true)) : ?>
                        <span class="ctp-events__meta-item ctp-events__meta-item--date">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                            <?php echo Icons::calendar(); ?>
                            <?php echo esc_html(EventFormatter::dateOnly($hero)); ?>
                            <?php if (!empty($hero['all_day'])) : ?>
                                <span class="ctp-events__badge">
                                    <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!in_array('time', $args['hidden_elements'], true) && EventFormatter::timeRange($hero) !== '') : ?>
                        <span class="ctp-events__meta-item ctp-events__meta-item--time">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                            <?php echo Icons::clock(); ?>
                            <?php echo esc_html(EventFormatter::timeRange($hero)); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!in_array('location', $args['hidden_elements'], true) && $hero['location'] !== '') : ?>
                        <span class="ctp-events__meta-item ctp-events__meta-item--location">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                            <?php echo Icons::location(); ?>
                            <?php echo esc_html($hero['location']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!in_array('excerpt', $args['hidden_elements'], true) && $hero['description'] !== '') : ?>
                        <p class="ctp-events__excerpt">
                            <?php echo esc_html(EventFormatter::excerpt($hero['description'])); ?>
                        </p>
                    <?php endif; ?>
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup, same trust boundary as $args['design_style'] above. ?>
                    <?php echo $args['design_separators']; ?>
                </div>
            </div>
            <?php if ($args['click_behavior'] === 'popup') : ?>
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- detail_html is this same event's fields already individually escaped by partials/event-detail-content.php, just pre-rendered server-side (see EventListRenderer::withCalendarMeta()). ?>
                <template class="ctp-events__detail-template"><?php echo $hero['detail_html']; ?></template>
            <?php endif; ?>
        </div>

        <?php if ($upcoming !== []) : ?>
            <p class="ctp-events__more-label"><?php esc_html_e('Weitere Termine', 'churchtools-plugin'); ?></p>
            <div class="ctp-events__list" role="list">
                <?php foreach ($upcoming as $event) : ?>
                    <div
                        class="ctp-events__item<?php echo $args['click_behavior'] !== 'none' ? ' ctp-events__item--clickable' : ''; ?>"
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
                            <?php if (!in_array('date', $args['hidden_elements'], true)) : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--date">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::calendar(); ?>
                                    <?php echo esc_html(EventFormatter::dateOnly($event)); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!in_array('time', $args['hidden_elements'], true) && EventFormatter::timeRange($event) !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--time">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::clock(); ?>
                                    <?php echo esc_html(EventFormatter::timeRange($event)); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!in_array('location', $args['hidden_elements'], true) && $event['location'] !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--location">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::location(); ?>
                                    <?php echo esc_html($event['location']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!in_array('excerpt', $args['hidden_elements'], true) && $event['description'] !== '') : ?>
                                <span class="ctp-events__excerpt">
                                    <?php echo esc_html(EventFormatter::excerpt($event['description'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup, same trust boundary as $args['design_style'] above. ?>
                            <?php echo $args['design_separators']; ?>
                        </span>
                        <?php if ($args['click_behavior'] === 'popup') : ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- detail_html is this same event's fields already individually escaped by partials/event-detail-content.php, just pre-rendered server-side (see EventListRenderer::withCalendarMeta()). ?>
                            <template class="ctp-events__detail-template"><?php echo $event['detail_html']; ?></template>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($args['click_behavior'] === 'popup') : ?>
            <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
