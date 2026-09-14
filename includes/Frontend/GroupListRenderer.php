<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;

/**
 * Die Gruppen einer Gruppen-Homepage als Kachelraster, fuer [ctp_groups], den
 * Block und das WPBakery-Element.
 *
 * Eine Gruppen*liste*, kein Gruppen*finder* (Grillrunde 2026-09-01): keine
 * Filterleiste, kein Paging, keine eigene Detailseite. Ein Klick fuehrt direkt
 * zur Gruppe in ChurchTools - dort steht der volle Text, und angemeldet wird
 * ohnehin dort.
 *
 * Die Kacheln tragen dieselben Klassen wie die der Termine, damit Vorlage,
 * Farben, Ecken, Bildformat und Reihenfolge aus dem Design-Tab ohne eigene
 * Einstellungen greifen. Von den ausblendbaren Feldern gelten die, die es an
 * einer Gruppe gibt: Bild, Uhrzeit (Wochentag und Treffzeit) und Auszug.
 */
final class GroupListRenderer
{
    private const DEFAULT_COLUMNS = 3;
    private const MIN_COLUMNS = 2;
    private const MAX_COLUMNS = 6;

    /** Woerter im Auszug - der laengste Text der Referenzinstanz hat 1330 Zeichen. */
    private const EXCERPT_WORDS = 24;

    public function render(array $args): string
    {
        $args = wp_parse_args($args, ['homepage' => '', 'columns' => self::DEFAULT_COLUMNS]);
        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));
        $args = array_merge($args, EventListRenderer::designArgs(SettingsPage::get()));

        $homepageId = GroupSettings::resolveHomepageId((string) $args['homepage']);
        $groups = $homepageId !== null
            ? self::prepareGroups(GroupSync::groupsFor($homepageId), GroupSync::imageMap(), $args['hidden_elements'])
            : [];

        $template = locate_template('churchtools-plugin/group-grid.php');
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/group-grid.php';
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * Ergaenzt, was das Template anzeigt, statt es dort auszurechnen: Bild aus
     * der Mediathek, Zeitangabe, Platzhinweis, Auszug.
     *
     * Ein Bild erscheint nur, wenn die Homepage selbst eins liefert
     * (`image_url` der Gruppe auf *dieser* Homepage) *und* es importiert ist.
     * Ohne Import gibt es kein Bild statt eines Verweises auf ChurchTools - die
     * Termine verlinken aus demselben Grund nie auf das Original.
     *
     * Die Bildflaeche (`show_media`) steht an jeder Kachel, sobald *eine*
     * Gruppe der Liste ein Bild hat - ohne eigenes Bild zeigt sie den
     * Farbverlauf, wie eine Terminkachel ohne Bild, und die Reihen fluchten.
     * Hat keine eins (Homepage mit abgeschalteten Gruppenbildern), faellt sie
     * ueberall weg: Eine Liste aus leeren Verlaeufen saehe nach fehlenden
     * Bildern aus, nicht nach einer Gestaltung.
     *
     * @param list<array> $groups
     * @param array<int, int> $imageMap
     *
     * @return list<array>
     */
    public static function prepareGroups(array $groups, array $imageMap, array $hiddenElements = []): array
    {
        $prepared = [];

        foreach ($groups as $group) {
            $attachmentId = (string) ($group['image_url'] ?? '') !== '' ? (int) ($imageMap[(int) $group['id']] ?? 0) : 0;
            $imageUrl = $attachmentId > 0 && !in_array('media', $hiddenElements, true)
                ? (string) wp_get_attachment_image_url($attachmentId, 'large')
                : '';

            $group['image_src'] = $imageUrl;
            $group['image_srcset'] = $imageUrl !== ''
                ? CardImage::srcsetFor($attachmentId, CardImage::CARD_MAX_SRCSET_WIDTH, CardImage::CARD_REFERENCE_SIZE)
                : '';
            $group['schedule'] = in_array('time', $hiddenElements, true) ? '' : self::schedule($group);
            $group['places_label'] = self::placesLabel($group);
            $group['excerpt'] = in_array('excerpt', $hiddenElements, true) || (string) ($group['note'] ?? '') === ''
                ? ''
                : EventFormatter::excerpt((string) $group['note'], self::EXCERPT_WORDS);

            $prepared[] = $group;
        }

        $showMedia = !in_array('media', $hiddenElements, true)
            && array_filter($prepared, static fn (array $group): bool => $group['image_src'] !== '') !== [];

        foreach ($prepared as &$group) {
            $group['show_media'] = $showMedia;
        }
        unset($group);

        return $prepared;
    }

    /**
     * „Donnerstag, 19:30 Uhr" - die Treffzeit ist in ChurchTools ein freies
     * Textfeld („9:30", „19:30 Uhr"), sie wird deshalb nicht umformatiert.
     */
    public static function schedule(array $group): string
    {
        $parts = array_filter([
            trim((string) ($group['weekday'] ?? '')),
            trim((string) ($group['meeting_time'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    /**
     * Nur bei Gruppen mit Hoechstzahl; ohne sie gibt es nichts, was knapp
     * werden koennte. Zwei Saetze statt _n(): bin/make-pot.php kennt keine
     * Plurale.
     */
    public static function placesLabel(array $group): string
    {
        if (($group['max_members'] ?? null) === null || ($group['free_places'] ?? null) === null) {
            return '';
        }

        $free = (int) $group['free_places'];

        if ($free <= 0) {
            return !empty($group['waitinglist'])
                ? __('Ausgebucht – Warteliste', 'churchtools-plugin')
                : __('Ausgebucht', 'churchtools-plugin');
        }

        if ($free === 1) {
            return __('Noch 1 Platz frei', 'churchtools-plugin');
        }

        /* translators: %d: number of free places in a group (2 or more) */
        return sprintf(__('Noch %d Plätze frei', 'churchtools-plugin'), $free);
    }
}
