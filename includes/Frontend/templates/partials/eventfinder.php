<?php

/**
 * Gefuehrte Werkzeugleiste fuer die Layouts list/grid — Knopfleisten fuer
 * shortcuts for calendar and timeframe, plus (wenn "search" eingeschaltet
 * ist) ein Suchfeld, in place of the plain filter dropdown/search input from
 * partials/toolbar.php (opt-in via the shortcode/block/WPBakery "eventfinder"
 * attribute, see EventListRenderer::render()). Das Suchfeld hing frueher am
 * Eventfinder statt am eigenen Schalter: Wer die Suche abwaehlte und den
 * Eventfinder anliess, bekam sie trotzdem. Not part of the theme-override contract
 * (only event-{layout}.php files are looked up via locate_template()); a
 * theme wanting a different toolbar overrides the whole layout template
 * instead.
 *
 * Buttons toggle a "ctp-events__finder-btn--active" state, which
 * assets/js/frontend.js reads twice over: once to filter the items already in
 * the DOM (applyToolbarState(), same data-ctp-calendar/data-ctp-search
 * attributes as the plain toolbar, plus data-ctp-start (Y-m-d) per item for
 * the timeframe buttons) and once to ask the server the same question against
 * the whole synced horizon (refreshFromServer(), via data-ctp-toolbar-config
 * an dieser Werkzeugleiste - nicht am Suchfeld, das es hier auch ohne geben
 * darf).
 *
 * @var array $args
 * @var array $filterCalendars
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Die Ueberschriften sind zugleich der zugaengliche Name der Knopfgruppe
 * darunter - aria-labelledby statt eines fest verdrahteten aria-label, damit
 * Screenreader dieselben Worte vorlesen, die auf dem Schirm stehen. Die IDs
 * kommen aus wp_unique_id(), weil eine Seite mehr als einen
 * [ctp_events eventfinder="1"] tragen kann und doppelte IDs jede Gruppe auf die
 * Ueberschrift der ersten zeigen liessen.
 *
 * Die Themenreihe hat seit 2026-09-02 keine eigene sichtbare Ueberschrift mehr
 * (Nutzerentscheidung: „Thema streichen, das sollte dann selbsterklaerend
 * sein") - ihren Namen leiht ihr deshalb die Frage darueber, die dieselbe
 * Auskunft gibt und ohnehin dasteht.
 */
$questionId = wp_unique_id('ctp-finder-question-');
$timeframeHeadingId = wp_unique_id('ctp-finder-timeframe-');
?>
<div class="ctp-events__toolbar ctp-events__eventfinder" data-ctp-toolbar-config="<?php echo esc_attr((string) wp_json_encode($args['toolbar_config'])); ?>">
    <p class="ctp-events__finder-label" id="<?php echo esc_attr($questionId); ?>">
        <?php esc_html_e('Welche Angebote sprechen dich an?', 'churchtools-plugin'); ?>
    </p>
    <?php if ($filterCalendars !== []) : ?>
        <div class="ctp-events__finder-row">
            <div class="ctp-events__finder-strip">
            <div
                class="ctp-events__finder-group"
                role="group"
                aria-labelledby="<?php echo esc_attr($questionId); ?>"
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
                        class="ctp-events__finder-btn ctp-events__finder-btn--calendar"
                        data-ctp-finder-calendar="<?php echo esc_attr($filterCalendar['id']); ?>"
                        <?php if (($filterCalendar['color'] ?? '') !== '') : ?>
                            style="--ctp-accent:<?php echo esc_attr($filterCalendar['color']); ?>;"
                        <?php endif; ?>
                        aria-pressed="false"
                    ><?php echo esc_html($filterCalendar['name']); ?></button>
                <?php endforeach; ?>
            </div>
            <?php
            /*
             * Zwei Blaetterknoepfe, je einer pro Richtung. Sie sind erst da, wenn
             * das Skript sie einschaltet - ohne JavaScript scrollt die Leiste
             * weiterhin mit dem Finger, aber ein Knopf, der dann nichts tut,
             * waere schlimmer als keiner. Der nach links erscheint erst, sobald
             * tatsaechlich etwas hinter einem liegt.
             */
            ?>
            <button
                type="button"
                class="ctp-events__finder-scroll ctp-events__finder-scroll--prev"
                aria-label="<?php esc_attr_e('Vorherige Themen anzeigen', 'churchtools-plugin'); ?>"
                hidden
            >
                <span aria-hidden="true">&lsaquo;</span>
            </button>
            <button
                type="button"
                class="ctp-events__finder-scroll ctp-events__finder-scroll--next"
                aria-label="<?php esc_attr_e('Weitere Themen anzeigen', 'churchtools-plugin'); ?>"
                hidden
            >
                <span aria-hidden="true">&rsaquo;</span>
            </button>
            </div>
        </div>
    <?php endif; ?>
    <div class="ctp-events__finder-row ctp-events__finder-row--timeframe">
        <?php
        /*
         * Dieselbe schiebbare Leiste wie bei den Themen. Ein Segmentschalter
         * stand hier einen Nachmittag lang und ist verworfen: Auf schmalen
         * Schirmen brachen die Segmentbreiten weg und die Knoepfe klebten
         * aneinander, und die kurzen Beschriftungen, die er erzwang („Woche"
         * statt „Diese Woche"), waren fuer sich genommen nicht verstaendlich -
         * beides vom Nutzer gemeldet. Vier gleich aussehende Pillen wie oben
         * sind langweiliger und funktionieren.
         *
         * Die Ueberschrift „Zeitraum" ist am 2026-09-02 zurueckgeholt worden
         * (Nutzerentscheidung). Sie hat hier eine andere Aufgabe als das
         * gestrichene „Thema": Die Themen stehen unter der Frage, die sie
         * benennt - die Zeitraeume haetten sonst gar keine Zuordnung und saehen
         * wie eine zweite Reihe Themen aus. Zusammen mit der Trennlinie darueber
         * trennt sie die beiden Fragen sichtbar voneinander.
         */
        ?>
        <span class="ctp-events__finder-row-label" id="<?php echo esc_attr($timeframeHeadingId); ?>">
            <?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?>
        </span>
        <?php
        /*
         * `--nowrap` heisst: Diese Reihe bleibt immer einzeilig und schiebt sich
         * lieber, statt umzubrechen. Bei den Themen entscheidet das Skript nach
         * der Zahl der Zeilen; hier waere dieselbe Rechnung falsch. Vier
         * Zeitraeume sind eine geschlossene Skala von „jederzeit" bis „diesen
         * Monat", und die zerfaellt beim Umbruch in zwei Haelften, die wie zwei
         * verschiedene Fragen aussehen (Nutzerbefund 2026-09-02: „Diese sollen
         * immer 1 Zeile sein").
         */
        ?>
        <div class="ctp-events__finder-strip ctp-events__finder-strip--nowrap">
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
        <button
            type="button"
            class="ctp-events__finder-scroll ctp-events__finder-scroll--prev"
            aria-label="<?php esc_attr_e('Vorherige Zeiträume anzeigen', 'churchtools-plugin'); ?>"
            hidden
        >
            <span aria-hidden="true">&lsaquo;</span>
        </button>
        <button
            type="button"
            class="ctp-events__finder-scroll ctp-events__finder-scroll--next"
            aria-label="<?php esc_attr_e('Weitere Zeiträume anzeigen', 'churchtools-plugin'); ?>"
            hidden
        >
            <span aria-hidden="true">&rsaquo;</span>
        </button>
        </div>
    </div>
    <?php if ($args['search']) : ?>
        <div class="ctp-events__search">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
            <?php echo Icons::search(); ?>
            <input
                type="search"
                class="ctp-events__search-input"
                placeholder="<?php esc_attr_e('Termine durchsuchen …', 'churchtools-plugin'); ?>"
                aria-label="<?php esc_attr_e('Termine durchsuchen', 'churchtools-plugin'); ?>"
            />
        </div>
    <?php endif; ?>
    <p class="ctp-events__toolbar-empty" hidden><?php esc_html_e('Keine Termine gefunden.', 'churchtools-plugin'); ?></p>
</div>
