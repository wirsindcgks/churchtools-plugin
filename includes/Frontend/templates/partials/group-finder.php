<?php

/**
 * Werkzeugleiste ueber dem Raster (group-grid.php): mit `finder` die
 * Knopfreihen fuer Kategorie, Wochentag und Zielgruppe, mit `search` das
 * Suchfeld - zwei Schalter wie beim Eventfinder (partials/eventfinder.php),
 * dessen Suchfeld ebenfalls am eigenen Schalter haengt.
 *
 * Dieselben Klassen wie der Eventfinder (partials/eventfinder.php), damit
 * Knoepfe, Blaetterpfeile und Umbruch gleich aussehen und dasselbe Skript die
 * Leisten einrichtet. Gefiltert wird aber anders: Alle Gruppen stehen schon in
 * der Seite, frontend.js blendet Zellen nur aus (applyGroupFinder()) und fragt
 * keinen Server. Welche Reihen und Knoepfe es gibt, rechnet
 * GroupListRenderer::finderRows() vor.
 *
 * Nicht einzeln ueberschreibbar, wie alle Partials.
 *
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$questionId = wp_unique_id('ctp-group-finder-question-');
$rowTexts = [
    'category' => [
        'label' => '',
        'prev' => __('Vorherige Themen anzeigen', 'churchtools-plugin'),
        'next' => __('Weitere Themen anzeigen', 'churchtools-plugin'),
    ],
    'weekday' => [
        'label' => __('Wochentag', 'churchtools-plugin'),
        'prev' => __('Vorherige Wochentage anzeigen', 'churchtools-plugin'),
        'next' => __('Weitere Wochentage anzeigen', 'churchtools-plugin'),
    ],
    'target' => [
        'label' => __('Für wen', 'churchtools-plugin'),
        'prev' => __('Vorherige Zielgruppen anzeigen', 'churchtools-plugin'),
        'next' => __('Weitere Zielgruppen anzeigen', 'churchtools-plugin'),
    ],
];
?>
<div class="ctp-events__toolbar<?php echo $args['finder'] ? ' ctp-events__eventfinder ctp-groups__finder' : ' ctp-groups__toolbar'; ?>">
    <?php if ($args['finder']) : ?>
    <p class="ctp-events__finder-label" id="<?php echo esc_attr($questionId); ?>">
        <?php esc_html_e('Welche Gruppe passt zu dir?', 'churchtools-plugin'); ?>
    </p>
    <?php endif; ?>
    <?php foreach ($args['finder_rows'] as $row) : ?>
        <?php
        $texts = $rowTexts[$row['key']];
        $labelId = $texts['label'] !== '' ? wp_unique_id('ctp-group-finder-' . $row['key'] . '-') : $questionId;
        // Die Wochentage sind eine geschlossene Reihe wie die Zeitraeume im
        // Eventfinder und bleiben deshalb einzeilig, statt umzubrechen.
        $stripClass = $row['key'] === 'weekday' ? ' ctp-events__finder-strip--nowrap' : '';
        ?>
        <div class="ctp-events__finder-row ctp-groups__finder-row--<?php echo esc_attr($row['key']); ?>">
            <?php if ($texts['label'] !== '') : ?>
                <span class="ctp-events__finder-row-label" id="<?php echo esc_attr($labelId); ?>"><?php echo esc_html($texts['label']); ?></span>
            <?php endif; ?>
            <div class="ctp-events__finder-strip<?php echo esc_attr($stripClass); ?>">
                <div class="ctp-events__finder-group" role="group" aria-labelledby="<?php echo esc_attr($labelId); ?>">
                    <button
                        type="button"
                        class="ctp-events__finder-btn ctp-events__finder-btn--active"
                        data-ctp-group-filter="<?php echo esc_attr($row['key']); ?>"
                        data-ctp-group-value=""
                        aria-pressed="true"
                    ><?php esc_html_e('Alle', 'churchtools-plugin'); ?></button>
                    <?php foreach ($row['options'] as $option) : ?>
                        <button
                            type="button"
                            class="ctp-events__finder-btn"
                            data-ctp-group-filter="<?php echo esc_attr($row['key']); ?>"
                            data-ctp-group-value="<?php echo esc_attr($option); ?>"
                            aria-pressed="false"
                        ><?php echo esc_html($option); ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="ctp-events__finder-scroll ctp-events__finder-scroll--prev" aria-label="<?php echo esc_attr($texts['prev']); ?>" hidden>
                    <span aria-hidden="true">&lsaquo;</span>
                </button>
                <button type="button" class="ctp-events__finder-scroll ctp-events__finder-scroll--next" aria-label="<?php echo esc_attr($texts['next']); ?>" hidden>
                    <span aria-hidden="true">&rsaquo;</span>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($args['search']) : ?>
    <div class="ctp-events__search">
        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
        <?php echo Icons::search(); ?>
        <input
            type="search"
            class="ctp-events__search-input"
            placeholder="<?php esc_attr_e('Gruppen durchsuchen …', 'churchtools-plugin'); ?>"
            aria-label="<?php esc_attr_e('Gruppen durchsuchen', 'churchtools-plugin'); ?>"
        />
    </div>
    <?php endif; ?>
    <p class="ctp-events__toolbar-empty" role="status" hidden><?php esc_html_e('Keine Gruppen gefunden.', 'churchtools-plugin'); ?></p>
</div>
