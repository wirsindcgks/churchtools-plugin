<?php

/**
 * The <li> items (and their month dividers) of the "list" layout, without the
 * surrounding <ul> — pulled out of event-list.php so the load-more endpoint can
 * render one further page of the exact same markup and the JS can append it
 * (see EventListRenderer::renderItems()).
 *
 * Not part of the theme-override contract: only event-{layout}.php files are
 * looked up via locate_template(), same as partials/toolbar.php. A theme that
 * overrides event-list.php and drops this include keeps working, but its
 * appended pages would then fall back to this default markup — so an override
 * should either require this partial or turn paging off (paging="0").
 *
 * @var array $events
 * @var array $args
 * @var string|null $currentMonthKey Divider bookkeeping, carried in from the caller.
 */

use ChurchToolsPlugin\Frontend\ClickTrigger;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;
use ChurchToolsPlugin\Frontend\ReturnAnchor;

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php foreach ($events as $event) : ?>
    <?php if ($args['month_dividers'] && EventFormatter::monthKey($event['start_date']) !== $currentMonthKey) : ?>
        <?php $currentMonthKey = EventFormatter::monthKey($event['start_date']); ?>
        <div class="ctp-events__month-divider" role="listitem" data-ctp-month="<?php echo esc_attr($currentMonthKey); ?>">
            <?php echo esc_html(EventFormatter::monthLabel($event['start_date'])); ?>
        </div>
    <?php endif; ?>
    <?php
    /*
     * Sprungziel fuer den „Zurueck"-Knopf der Detailseite: Der Termin dort
     * kennt seine eigene ID, haengt sie als #ctp-event-<id> an die
     * Ruecksprung-Adresse und landet damit genau auf der Kachel, die
     * angeklickt wurde, statt am Seitenanfang (siehe
     * EventListRenderer::renderDetail()).
     *
     * Steht derselbe Termin zweimal auf einer Seite (Teaser plus vollstaendige
     * Liste), gibt es die ID zweimal. Browser springen dann zum ersten
     * Vorkommen - was hier genau das gewuenschte Verhalten ist.
     *
     * Ohne ID gar kein Attribut: Eine Zeile ohne `id` kommt aus dieser
     * Datenbank nicht, wohl aber aus einem Theme-Override oder einem Test,
     * der sich seine Termine selbst baut. `id="ctp-event-0"` an jeder
     * Kachel waere dort schlechter als kein Sprungziel.
     */
    ?>
    <div
        <?php $ctpAnchorId = ReturnAnchor::idFor($event); ?>
        <?php if ($ctpAnchorId !== '') : ?>
            id="<?php echo esc_attr($ctpAnchorId); ?>"
        <?php endif; ?>
        class="ctp-events__item<?php echo $args['click_behavior'] !== 'none' ? ' ctp-events__item--clickable' : ''; ?>"
        data-ctp-calendar="<?php echo esc_attr($event['ct_calendar_id']); ?>"
        data-ctp-search="<?php echo esc_attr(mb_strtolower($event['title'] . ' ' . $event['subtitle'] . ' ' . $event['location'])); ?>"
        data-ctp-start="<?php echo esc_attr(EventFormatter::dateKey($event['start_date'])); ?>"
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
            <?php if (!in_array('subtitle', $args['hidden_elements'], true) && $event['subtitle'] !== '') : ?>
                <span class="ctp-events__subtitle"><?php echo esc_html($event['subtitle']); ?></span>
            <?php endif; ?>
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
