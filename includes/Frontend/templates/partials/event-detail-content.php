<?php

/**
 * Single-event detail content, shared by the "own page" template
 * (event-detail.php) and the popup <template> embedded per card in
 * event-list.php/event-grid.php/event-upcoming.php. Renders the six
 * DetailDesign::ELEMENT_KEYS in the admin-configured order (see
 * SettingsPage::get()['detail_element_order']) — no CSS `order` trick here
 * since this is a single event, not a repeated list item (see DetailDesign
 * docblock).
 *
 * @var array $event Already enriched via EventListRenderer::withCalendarMeta().
 * @var array $order Validated DetailDesign::ELEMENT_KEYS permutation.
 */

use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events__detail"
    <?php if ($event['calendar_color'] !== '') : ?>
        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
    <?php endif; ?>
>
    <?php foreach ($order as $key) : ?>
        <?php switch ($key) :
            case 'media':
                ?>
                <?php if ($event['image_url'] !== '') : ?>
                    <?php
                    // The outer element is the full-width row the detail view's
                    // flex layout gives it; the inner frame is what shrink-wraps
                    // the (possibly portrait) image and carries radius, shadow
                    // and the fallback scrim. Splitting the two is what lets a
                    // narrow image sit centred without a full-width frame
                    // around empty space beside it.
                    ?>
                    <div class="ctp-events__detail-media">
                        <div class="ctp-events__detail-media-frame<?php echo $event['image_is_fallback'] ? ' ctp-events__detail-media-frame--fallback' : ''; ?>">
                            <img src="<?php echo esc_url($event['image_url']); ?>" alt="" />
                        </div>
                    </div>
                <?php endif; ?>
                <?php
                break;

            case 'calendar':
                ?>
                <?php if ($event['calendar_name'] !== '') : ?>
                    <span class="ctp-events__eyebrow">
                        <?php if ($event['calendar_color'] !== '') : ?>
                            <span class="ctp-events__color-dot" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php echo esc_html($event['calendar_name']); ?>
                    </span>
                <?php endif; ?>
                <?php
                break;

            case 'title':
                ?>
                <h2 class="ctp-events__detail-title">
                    <?php echo esc_html($event['title']); ?>
                    <?php if (!empty($event['all_day'])) : ?>
                        <span class="ctp-events__badge">
                            <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <?php
                break;

            case 'subtitle':
                ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <p class="ctp-events__subtitle"><?php echo esc_html($event['subtitle']); ?></p>
                <?php endif; ?>
                <?php
                break;

            case 'date':
                ?>
                <p class="ctp-events__meta-item ctp-events__meta-item--date">
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input, same trust boundary as the rest of this template's static HTML (see Icons.php docblock). ?>
                    <?php echo Icons::calendar(); ?>
                    <?php echo esc_html(EventFormatter::dateOnly($event)); ?>
                </p>
                <?php
                break;

            case 'time':
                ?>
                <?php if (EventFormatter::timeRange($event) !== '') : ?>
                    <p class="ctp-events__meta-item ctp-events__meta-item--time">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                        <?php echo Icons::clock(); ?>
                        <?php echo esc_html(EventFormatter::timeRange($event)); ?>
                    </p>
                <?php endif; ?>
                <?php
                break;

            case 'location':
                ?>
                <?php if ($event['location'] !== '') : ?>
                    <p class="ctp-events__meta-item ctp-events__meta-item--location">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                        <?php echo Icons::location(); ?>
                        <?php echo esc_html($event['location']); ?>
                    </p>
                <?php endif; ?>
                <?php
                break;

            case 'description':
                ?>
                <?php if ($event['description'] !== '') : ?>
                    <div class="ctp-events__detail-description">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses_post() before adding any markup of its own (see its docblock). ?>
                        <?php echo EventFormatter::descriptionHtml($event['description']); ?>
                    </div>
                <?php endif; ?>
                <?php
                break;
        endswitch; ?>
    <?php endforeach; ?>
</div>
