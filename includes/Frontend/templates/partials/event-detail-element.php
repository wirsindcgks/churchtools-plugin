<?php

/**
 * Renders exactly one element of the single-event detail view. Split out of
 * event-detail-content.php so that file can render the elements twice over in
 * two different groupings — flat for the popup, wrapped for the "own page"
 * layout — without the markup of every field existing twice.
 *
 * @var array  $event         Already enriched via EventListRenderer::withCalendarMeta().
 * @var string $key           One of DetailDesign::ELEMENT_KEYS.
 * @var string $detailContext 'popup' or 'page', see event-detail-content.php.
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
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
                    <?php
                    /*
                     * skip-lazy/data-no-lazy: Dieses Bild steckt im
                     * <template> jeder Kachel und wird erst beim
                     * Oeffnen des Popups in die Seite kopiert. Ein
                     * Lazyload-Plugin (WP Rocket & Co.) ersetzt beim
                     * Ausliefern trotzdem das src durch einen
                     * Platzhalter und merkt sich die echte Adresse in
                     * data-src - seinen Beobachter bekommt der Klon
                     * danach aber nie zu sehen, das Popup blieb also
                     * ohne Bild. Diese beiden Kennzeichen sind die
                     * gaengigen Ausnahmen; unabhaengig davon holt
                     * assets/js/frontend.js beim Klonen ein bereits
                     * ersetztes src wieder zurueck.
                     */
                    ?>
                    <img
                        src="<?php echo esc_url($event['image_url']); ?>"
                        <?php if (($event['image_srcset_full'] ?? '') !== '') : ?>
                            srcset="<?php echo esc_attr($event['image_srcset_full']); ?>"
                            sizes="<?php echo esc_attr(CardImage::detailSizes()); ?>"
                        <?php endif; ?>
                        alt=""
                        class="skip-lazy"
                        data-no-lazy="1"
                        loading="eager"
                    />
                </div>
            </div>
        <?php endif; ?>
        <?php
        break;

    case 'calendar':
        ?>
        <?php if ($event['calendar_name'] !== '') : ?>
            <span class="ctp-events__eyebrow">
                <?php echo esc_html($event['calendar_name']); ?>
            </span>
        <?php endif; ?>
        <?php
        break;

    case 'title':
        ?>
        <?php
        /*
         * Datums-Chip vor dem Titel, wie in den Kacheln: Er ist die
         * Marke, an der man einen Termin wiedererkennt, und stand
         * bisher nur in den Listen. Die Datumszeile weiter unten
         * bleibt daneben bestehen - sie nennt Wochentag und volles
         * Datum, der Chip ist aria-hidden und damit fuer Screenreader
         * nicht die zweite Stimme derselben Angabe.
         *
         * Auf der eigenen Seite ist der Titel ein h1: Dort ist der
         * Termin der Gegenstand der Seite und nichts anderes steht
         * ueber ihm. Im Popup bleibt es ein h2 - das Dokument hat dort
         * bereits eine eigene Ueberschrift, und der Dialog ist ein
         * Ausschnitt daraus, keine neue Seite.
         */
        $titleTag = $detailContext === 'page' ? 'h1' : 'h2';
        ?>
        <div class="ctp-events__detail-heading">
            <span class="ctp-events__date-chip ctp-events__date-chip--detail" aria-hidden="true">
                <span class="ctp-events__day">
                    <?php echo esc_html(EventFormatter::dayNumber($event['start_date'])); ?>
                </span>
                <span class="ctp-events__month">
                    <?php echo esc_html(EventFormatter::monthAbbreviation($event['start_date'])); ?>
                </span>
            </span>
            <?php printf('<%s class="ctp-events__detail-title">', esc_html($titleTag)); ?>
                <?php echo esc_html($event['title']); ?>
                <?php if (!empty($event['all_day'])) : ?>
                    <span class="ctp-events__badge">
                        <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                    </span>
                <?php endif; ?>
            <?php printf('</%s>', esc_html($titleTag)); ?>
        </div>
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
endswitch;
