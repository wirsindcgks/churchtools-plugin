<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Settings;

final class Shortcode
{
    public function register(): void
    {
        add_shortcode('ctp_events', [$this, 'render']);
        add_shortcode('ctp_groups', [$this, 'renderGroups']);
    }

    /**
     * [ctp_groups homepage="Kleingruppen" columns="3"] - die Homepage als Name
     * oder ID, wie `calendar` bei den Terminen. Ohne Angabe gilt die einzige
     * angehakte Homepage (siehe GroupSettings::resolveHomepageId()).
     *
     * [ctp_groups groups="514,269" layout="featured"] - einzelne Gruppen nach
     * ID, in dieser Reihenfolge, statt einer Homepage (plan.md, G1/G2). IDs und
     * keine Namen: Gruppennamen sind nicht eindeutig und werden oefter
     * umbenannt als Homepages. Steht beides da, gilt `groups` - ausser
     * `source="homepage"` sagt es anders (so schreibt es das WPBakery-Element).
     *
     * [ctp_groups homepage="Kleingruppen" finder="1" search="1"] - mit
     * Gruppenfinder (Kategorie, Wochentag, Zielgruppe) und Suchleiste ueber
     * dem Raster; zwei Schalter wie `eventfinder` und `search` bei den Terminen.
     */
    public function renderGroups($atts): string
    {
        $atts = shortcode_atts([
            'source' => '',
            'homepage' => '',
            'groups' => '',
            'layout' => 'grid',
            'columns' => 3,
            'finder' => '0',
            'search' => '0',
        ], $atts, 'ctp_groups');

        return (new GroupListRenderer())->render([
            'source' => (string) $atts['source'],
            'homepage' => (string) $atts['homepage'],
            'groups' => (string) $atts['groups'],
            'layout' => (string) $atts['layout'],
            'columns' => (int) $atts['columns'],
            // Gelesen wie bei [ctp_events], damit dieselbe Schreibweise dasselbe tut.
            'finder' => (bool) $atts['finder'],
            'search' => (bool) $atts['search'],
        ]);
    }

    /**
     * Der Eventfinder ist an, wenn `finder` oder der aeltere Name `eventfinder`
     * ihn einschaltet - `finder` heisst der Schalter wie bei [ctp_groups].
     *
     * @param array<string, mixed> $atts nach shortcode_atts()
     */
    public static function finderEnabled(array $atts): bool
    {
        return (bool) ($atts['finder'] ?? false) || (bool) ($atts['eventfinder'] ?? false);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'calendar' => '',
            'layout' => 'list',
            // 0 = uncapped: how much is shown is decided by the time window
            // ("months" below), not by a count. Still honored when set, as a
            // safety cap per page — and it remains the only knob for the
            // count-based "upcoming" layout, which isn't paged.
            'limit' => 0,
            'columns' => 3,
            'click' => 'default',
            'filter' => '0',
            'search' => '0',
            'month_dividers' => '0',
            // `finder` heisst der Schalter wie bei [ctp_groups]; `eventfinder`
            // ist der aeltere Name und gilt weiter (Kompatibilitaetszusage).
            'finder' => '0',
            'eventfinder' => '0',
            // 0 = fall back to the Design tab's global "Zeitraum pro Seite".
            'months' => 0,
            'paging' => '1',
        ], $atts, 'ctp_events');

        $refs = array_filter(array_map('trim', explode(',', (string) $atts['calendar'])));
        $calendarIds = Settings::resolveCalendarIds($refs);

        return (new EventListRenderer())->render([
            'calendar_ids' => $calendarIds,
            'layout' => $atts['layout'],
            'limit' => (int) $atts['limit'],
            'columns' => (int) $atts['columns'],
            'click' => $atts['click'],
            'filter' => (bool) $atts['filter'],
            'search' => (bool) $atts['search'],
            'month_dividers' => (bool) $atts['month_dividers'],
            'eventfinder' => self::finderEnabled($atts),
            'months' => (int) $atts['months'],
            'paging' => (bool) $atts['paging'],
        ]);
    }
}
