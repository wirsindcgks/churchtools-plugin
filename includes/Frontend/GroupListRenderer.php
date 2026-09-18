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
 * Kein Paging und keine eigene Detailseite. Der Weg nach ChurchTools ist ein
 * sichtbarer Button „In ChurchTools ansehen".
 *
 * Seit 1.31.0 auf Wunsch mit Gruppenfinder (`finder`: Knoepfe fuer
 * Kategorie, Wochentag und Zielgruppe) und Suchleiste (`search`), gefiltert
 * im Browser - alle Gruppen stehen ohnehin in der Seite. Beide Schalter
 * heissen und wirken wie beim Eventfinder: `search` ist ein eigener Schalter,
 * mit oder ohne Finder, und steht mit Finder in dessen Leiste. Welche Knoepfe erscheinen, entscheiden
 * die Homepage in ChurchTools (dort eingeschaltete Filter) und die Gruppen
 * selbst (finderRows()). Bis dahin war die Liste bewusst kein Suchwerkzeug
 * (Grillrunde 2026-09-01); geaendert hat das der Wunsch, die Gruppen auf der
 * Produktivseite einzubauen (plan.md, Gruppenfinder).
 *
 * Die Rasterkachel oeffnet seit dem Gruppen-Popup (2026-09-15) den ganzen Text
 * in einem Dialog auf *dieser* Website - genau das, was ein Klick auf eine
 * Terminkachel auch verspricht. Bis dahin war sie nicht klickbar (plan.md, G4),
 * weil die einzige moegliche Geste nach ChurchTools gefuehrt haette. Ohne
 * JavaScript fuehrt der Auslöser weiter dorthin, wie der Button.
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

    /**
     * Woerter im Auszug als eine Zeile (`excerpt`) - fuer Vorlagen im Theme,
     * die noch aus der Zeit vor dem formatierten Auszug stammen.
     */
    private const EXCERPT_WORDS = 24;

    /**
     * Woerter im formatierten Auszug der Rasterkachel (`excerpt_html`).
     * Zuerst 40 („die Texte etwas laenger anzeigen"), mit dem Popup wieder
     * 24 wie die einzeilige Fassung: Den ganzen Text zeigt jetzt ein Klick
     * auf die Kachel, die Kachel selbst soll nur neugierig machen
     * (Nutzerwunsch 2026-09-15).
     */
    public const GRID_EXCERPT_WORDS = 24;

    /**
     * Ab hier nennt der Platzhinweis keine genaue Zahl mehr: „Noch 52 Plaetze
     * frei" liest sich eher nach einer leeren Gruppe als nach einem Anlass,
     * sich anzumelden.
     */
    private const PLACES_EXACT_UP_TO = 10;

    public const LAYOUTS = ['grid', 'featured'];

    /**
     * Die Reihen des Gruppenfinders in ihrer Reihenfolge, je mit dem Filtertyp
     * der Homepage in ChurchTools, der sie freigibt. Die Kategorie steht oben
     * und ohne eigene Ueberschrift - sie ist das Thema, wie die Kalender im
     * Eventfinder, und die Frage darueber benennt sie.
     */
    public const FINDER_FILTERS = [
        'category' => 'groupcategory',
        'weekday' => 'weekday',
        'target' => 'targetgroups',
    ];

    /** Schluessel der Zielgruppe „Jeder" in ChurchTools, siehe GroupSync::normalizeGroup(). */
    private const TARGET_EVERYONE = 'everyone';

    /**
     * @param array{source?: string, homepage?: string, groups?: string, layout?: string, columns?: int|string, finder?: bool|int|string, search?: bool|int|string} $args
     */
    public function render(array $args): string
    {
        $args = wp_parse_args($args, ['source' => '', 'homepage' => '', 'groups' => '', 'layout' => 'grid', 'columns' => self::DEFAULT_COLUMNS, 'finder' => false, 'search' => false]);

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

        $selected = self::selectGroups((string) $args['homepage'], (string) $args['groups']);
        $groups = self::prepareGroups(
            $selected,
            GroupSync::imageMap(),
            $args['hidden_elements'],
            $args['layout'] === 'featured'
        );

        // Nur im Raster: Die hervorgehobene Ansicht ist fuer wenige Gruppen
        // gedacht, dort gibt es nichts zu suchen.
        $args['finder'] = $args['layout'] === 'grid' && filter_var($args['finder'], FILTER_VALIDATE_BOOLEAN) && $groups !== [];
        $args['search'] = $args['layout'] === 'grid' && filter_var($args['search'], FILTER_VALIDATE_BOOLEAN) && $groups !== [];
        $args['show_toolbar'] = $args['finder'] || $args['search'];
        $args['finder_rows'] = $args['finder']
            ? self::finderRows($groups, GroupSync::filtersFor(self::sourceHomepages((string) $args['homepage'], (string) $args['groups'], $selected)))
            : [];

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

    /**
     * Die Homepages, deren Filter-Freigaben fuer diese Liste gelten: die eine
     * gewaehlte Homepage, oder bei einzelnen Gruppen alle angehakten, auf denen
     * eine davon steht - dieselbe Unterscheidung wie selectGroups().
     *
     * @param list<array> $selected
     *
     * @return int[]
     */
    private static function sourceHomepages(string $homepage, string $groupIds, array $selected): array
    {
        if (GroupSync::parseIds($groupIds) !== []) {
            return GroupSync::homepagesContaining(array_map(static fn (array $group): int => (int) ($group['id'] ?? 0), $selected));
        }

        $homepageId = GroupSettings::resolveHomepageId($homepage);

        return $homepageId !== null ? [$homepageId] : [];
    }

    /**
     * Die Knopfreihen des Gruppenfinders.
     *
     * Ein Knopf erscheint nur, wenn er etwas bewirkt: Seine Auswahl muss
     * mindestens eine Gruppe ausblenden. Eine Reihe ohne solchen Knopf faellt
     * weg - an der Referenzinstanz (1 von 19 Gruppen fuer „Frauen", der Rest
     * „Jeder" oder ohne Angabe) zeigte „Frauen" sonst alle Gruppen und saehe
     * kaputt aus. Werden die Daten in ChurchTools gepflegt, erscheinen die
     * Reihen von selbst.
     *
     * Die Zielgruppe „Jeder" ist kein eigener Knopf, sondern passt zu jeder
     * Auswahl; eine Gruppe ohne Zielgruppe ebenso - sie schliesst niemanden
     * aus. Kategorie und Wochentag dagegen muessen passen: Wer „Donnerstag"
     * waehlt, will keine Gruppe ohne Tag sehen.
     *
     * Sortiert nach dem sortKey aus ChurchTools (Montag bis Sonntag, die
     * Reihenfolge der Stammdaten), bei Gleichstand nach Namen.
     *
     * @param list<array>       $groups       aus prepareGroups()
     * @param list<string>|null $shownFilters GroupSync::filtersFor(); null = unbekannt, alles erlaubt
     *
     * @return list<array{key: string, options: list<string>}>
     */
    public static function finderRows(array $groups, ?array $shownFilters): array
    {
        $rows = [];

        foreach (self::FINDER_FILTERS as $key => $filterType) {
            if ($shownFilters !== null && !in_array($filterType, $shownFilters, true)) {
                continue;
            }

            $sorts = [];
            foreach ($groups as $group) {
                $value = (string) $group['finder_' . $key];

                if ($value !== '' && !isset($sorts[$value])) {
                    $sort = $group[['category' => 'category_sort', 'weekday' => 'weekday_sort', 'target' => 'target_group_sort'][$key]] ?? null;
                    $sorts[$value] = is_int($sort) ? $sort : PHP_INT_MAX;
                }
            }

            $options = [];
            foreach (array_keys($sorts) as $value) {
                $value = (string) $value;
                $matching = array_filter($groups, static fn (array $group): bool => self::finderMatches($group, $key, $value));

                if (count($matching) < count($groups)) {
                    $options[] = $value;
                }
            }

            usort($options, static fn (string $a, string $b): int => [$sorts[$a], $a] <=> [$sorts[$b], $b]);

            if ($options !== []) {
                $rows[] = ['key' => $key, 'options' => $options];
            }
        }

        return $rows;
    }

    /** Dieselbe Regel wie groupMatches() in assets/js/frontend.js. */
    private static function finderMatches(array $group, string $key, string $value): bool
    {
        $groupValue = (string) $group['finder_' . $key];

        return $groupValue === $value || ($key === 'target' && $groupValue === '');
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
            $group['excerpt_html'] = $group['excerpt'] === '' || $withDescription
                ? ''
                : self::excerptHtml((string) $group['note']);
            // Der volle Text geht durch dieselbe Aufbereitung wie eine
            // Terminbeschreibung: enge kses-Liste, klickbare Links,
            // verschleierte E-Mail-Adressen (EventFormatter::descriptionHtml()).
            // Gebraucht in der hervorgehobenen Ansicht und im Popup des Rasters -
            // dort auch, wenn der Auszug auf der Kachel ausgeblendet ist.
            $group['description_html'] = (string) ($group['note'] ?? '') !== ''
                ? EventFormatter::descriptionHtml((string) $group['note'])
                : '';
            // Das Popup zeigt das Bild groesser als die Kachel, also ohne den
            // Deckel der Kachel-srcset (wie die Detailansicht der Termine).
            // In beiden Darstellungen: Auch die hervorgehobene oeffnet es.
            $group['image_srcset_full'] = $imageUrl !== ''
                ? CardImage::srcsetFor($attachmentId)
                : '';

            // Werte fuer den Gruppenfinder, als Attribute an der Zelle. Sie
            // haengen nicht an den ausblendbaren Feldern: Wer die Treffzeit auf
            // der Kachel ausblendet, kann trotzdem nach dem Wochentag suchen.
            $group['finder_category'] = trim((string) ($group['category'] ?? ''));
            $group['finder_weekday'] = trim((string) ($group['weekday'] ?? ''));
            $group['finder_target'] = ($group['target_group_key'] ?? '') === self::TARGET_EVERYONE
                ? ''
                : trim((string) ($group['target_group'] ?? ''));
            $group['finder_search'] = mb_strtolower(implode(' ', array_filter([
                (string) $group['name'],
                $group['finder_category'],
                $group['finder_weekday'],
                trim((string) ($group['target_group'] ?? '')),
                EventFormatter::plainText((string) ($group['note'] ?? '')),
            ])));

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
     * Der Anfang des Textes mit seinen Absaetzen und Zeilenumbruechen, durch
     * dieselbe Aufbereitung wie der volle Text der hervorgehobenen Ansicht
     * (enge kses-Liste, klickbare Links, verschleierte Adressen).
     *
     * Die Texte kommen aus ChurchTools als Klartext (an der Referenzinstanz
     * alle 17). Liefert eine Instanz doch HTML, gibt es den Auszug als eine
     * Zeile wie bisher - ihn an Wortgrenzen zu kuerzen, koennte ein Element
     * mittendrin abschneiden. Auch diese Zeile geht durch descriptionHtml(),
     * sonst stuende eine E-Mail-Adresse darin unverschleiert im Quelltext.
     *
     * Ob HTML vorliegt, entscheidet der Text *nach* der engen kses-Liste: Ein
     * eingeschmuggeltes `<img>` in einem Klartext faellt dort ohnehin heraus
     * und soll dem Rest nicht die Absaetze nehmen (so im Integrationstest
     * gefunden, 2026-09-15).
     */
    public static function excerptHtml(string $note): string
    {
        $allowed = wp_kses($note, EventFormatter::DESCRIPTION_TAGS);

        if (EventFormatter::containsHtml($allowed)) {
            return EventFormatter::descriptionHtml(
                EventFormatter::excerpt(EventFormatter::plainText($allowed), self::GRID_EXCERPT_WORDS)
            );
        }

        return EventFormatter::descriptionHtml(EventFormatter::trimWordsKeepingLines($allowed, self::GRID_EXCERPT_WORDS));
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
