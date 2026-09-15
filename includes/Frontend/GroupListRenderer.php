<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Settings;

/**
 * Gruppen als Kacheln, fuer [ctp_groups], den Block und das WPBakery-Element -
 * entweder alle Gruppen einer Homepage oder einzeln ausgewaehlte.
 *
 * Eine Gruppen*liste*, kein Gruppen*finder* (Grillrunde 2026-09-01): keine
 * Filterleiste, kein Paging, keine eigene Detailseite. Der Weg nach
 * ChurchTools ist ein sichtbarer Button „In ChurchTools ansehen" - die Kachel
 * selbst ist nicht klickbar (plan.md, G4): Bei Terminen verspricht ein Klick
 * auf die Kachel eine Ansicht auf *dieser* Website, bei Gruppen fuehrte
 * dieselbe Geste unangekuendigt in ein anderes System.
 *
 * Zwei Darstellungen: `grid` (Raster mit Auszug) und `featured` (grosse
 * Kachel je Gruppe, Bild neben dem vollen Text) fuer wenige, hervorgehobene
 * Gruppen.
 *
 * Die Kacheln tragen dieselben Klassen wie die der Termine, damit Vorlage,
 * Farben, Ecken, Bildformat und Reihenfolge aus dem Design-Tab ohne eigene
 * Einstellungen greifen. Von den ausblendbaren Feldern gelten die, die es an
 * einer Gruppe gibt: Bild, Uhrzeit (Wochentag und Treffzeit), Auszug - und
 * „Kalendername" fuer die Zielgruppe. Die steht als Angabezeile mit
 * Personensymbol unter der Treffzeit; ausgeblendet wird sie mit dem
 * Kalendernamen, weil beide dasselbe sagen: in welche Sparte etwas gehoert.
 */
final class GroupListRenderer
{
    private const DEFAULT_COLUMNS = 3;
    private const MIN_COLUMNS = 2;
    private const MAX_COLUMNS = 6;

    /** Woerter im Auszug - der laengste Text der Referenzinstanz hat 1330 Zeichen. */
    private const EXCERPT_WORDS = 24;

    /**
     * Ab hier nennt der Platzhinweis keine genaue Zahl mehr: „Noch 52 Plaetze
     * frei" liest sich eher nach einer leeren Gruppe als nach einem Anlass,
     * sich anzumelden.
     */
    private const PLACES_EXACT_UP_TO = 10;

    public const LAYOUTS = ['grid', 'featured'];

    /**
     * @param array{source?: string, homepage?: string, groups?: string, layout?: string, columns?: int|string} $args
     */
    public function render(array $args): string
    {
        $args = wp_parse_args($args, ['source' => '', 'homepage' => '', 'groups' => '', 'layout' => 'grid', 'columns' => self::DEFAULT_COLUMNS]);

        // `source` sagt ausdruecklich, welche Angabe gilt - die andere kann in
        // Block und WPBakery noch gespeichert sein, nachdem umgeschaltet wurde.
        // Ohne `source` (Shortcode von Hand, Einbindungen aus 1.28) gilt wie
        // bisher: einzelne Gruppen vor der Homepage.
        if ($args['source'] === 'homepage') {
            $args['groups'] = '';
        } elseif ($args['source'] === 'groups') {
            $args['homepage'] = '';
        }
        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));
        $args['layout'] = in_array($args['layout'], self::LAYOUTS, true) ? $args['layout'] : 'grid';
        $args['instance'] = wp_unique_id('ctp-groups-');
        $args = array_merge($args, EventListRenderer::designArgs(Settings::get()));

        $groups = self::prepareGroups(
            self::selectGroups((string) $args['homepage'], (string) $args['groups']),
            GroupSync::imageMap(),
            $args['hidden_elements'],
            $args['layout'] === 'featured'
        );

        $file = $args['layout'] === 'featured' ? 'group-featured.php' : 'group-grid.php';
        $template = locate_template('churchtools-plugin/' . $file);
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/' . $file;
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * Einzelne Gruppen vor der Homepage: Wer beides angibt, hat mit `groups`
     * die genauere Auswahl getroffen.
     *
     * @return list<array>
     */
    public static function selectGroups(string $homepage, string $groups): array
    {
        $ids = GroupSync::parseIds($groups);

        if ($ids !== []) {
            return GroupSync::groupsByIds($ids);
        }

        // Eine Angabe, aus der keine einzige ID wird („abc"), ist eine
        // misslungene Auswahl und nicht „alle der Homepage".
        if (trim($groups) !== '') {
            return [];
        }

        $homepageId = GroupSettings::resolveHomepageId($homepage);

        return $homepageId !== null ? GroupSync::groupsFor($homepageId) : [];
    }

    /** Die Beschriftung des Absprungs - ein Wort fuer alle Gruppen, siehe plan.md G3. */
    public static function ctaLabel(): string
    {
        return __('In ChurchTools ansehen', 'churchtools-plugin');
    }

    /**
     * Ergaenzt, was das Template anzeigt, statt es dort auszurechnen: Bild aus
     * der Mediathek, Zeitangabe, Platzhinweis, Auszug - und fuer die
     * hervorgehobene Darstellung den vollen Text.
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
    public static function prepareGroups(array $groups, array $imageMap, array $hiddenElements = [], bool $withDescription = false): array
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
            // Gruppen aus einem Abgleich vor dieser Aenderung tragen das Feld noch
            // nicht; bis zum naechsten Lauf bleibt die Zeile dann leer.
            $group['target_group_label'] = in_array('calendar', $hiddenElements, true)
                ? ''
                : trim((string) ($group['target_group'] ?? ''));
            $group['places_label'] = self::placesLabel($group);
            $group['excerpt'] = in_array('excerpt', $hiddenElements, true) || (string) ($group['note'] ?? '') === ''
                ? ''
                : EventFormatter::excerpt((string) $group['note'], self::EXCERPT_WORDS);
            // Der volle Text geht durch dieselbe Aufbereitung wie eine
            // Terminbeschreibung: enge kses-Liste, klickbare Links,
            // verschleierte E-Mail-Adressen (EventFormatter::descriptionHtml()).
            $group['description_html'] = $withDescription && (string) ($group['note'] ?? '') !== ''
                ? EventFormatter::descriptionHtml((string) $group['note'])
                : '';

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
     * werden koennte. Zwei Saetze statt der Plural-Funktion von WordPress:
     * bin/make-pot.php kennt keine Plurale und bricht schon ab, wenn ihr Name
     * mit Klammern in einem Kommentar steht.
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

        if ($free > self::PLACES_EXACT_UP_TO) {
            /* translators: %d: threshold above which the exact number of free places is not shown (10) */
            return sprintf(__('%d+ Plätze frei', 'churchtools-plugin'), self::PLACES_EXACT_UP_TO);
        }

        /* translators: %d: number of free places in a group (2 to 10) */
        return sprintf(__('Noch %d Plätze frei', 'churchtools-plugin'), $free);
    }
}
