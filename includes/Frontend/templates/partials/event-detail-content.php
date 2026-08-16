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
<div class="ctp-events__detail">
    <?php foreach ($order as $key) : ?>
        <?php switch ($key) :
            case 'media':
                ?>
                <?php if ($event['image_url'] !== '') : ?>
                    <div class="ctp-events__detail-media">
                        <img src="<?php echo esc_url($event['image_url']); ?>" alt="" />
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

            case 'meta':
                ?>
                <p class="ctp-events__meta">
                    <span class="ctp-events__meta-item">
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input, same trust boundary as the rest of this template's static HTML (see Icons.php docblock). ?>
                        <?php echo Icons::clock(); ?>
                        <?php echo esc_html(EventFormatter::dateRange($event)); ?>
                    </span>
                    <?php if ($event['location'] !== '') : ?>
                        <span class="ctp-events__meta-item">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                            <?php echo Icons::location(); ?>
                            <?php echo esc_html($event['location']); ?>
                        </span>
                    <?php endif; ?>
                </p>
                <?php
                break;

            case 'description':
                ?>
                <?php if ($event['description'] !== '') : ?>
                    <div class="ctp-events__detail-description">
                        <?php echo wp_kses_post($event['description']); ?>
                    </div>
                <?php endif; ?>
                <?php
                break;
        endswitch; ?>
    <?php endforeach; ?>
</div>
