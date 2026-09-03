<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use DateTimeImmutable;
use Throwable;

/**
 * Die Termine noch einmal als strukturierte Daten (schema.org/Event als
 * JSON-LD), zusätzlich zum sichtbaren Markup.
 *
 * Der sichtbare Teil einer Kachel ist für einen Menschen eindeutig und für eine
 * Maschine nicht: „Sa, 6. September 2026" und „19:30–22:00" sind Text, aus dem
 * niemand zuverlässig einen Zeitpunkt mit Zeitzone gewinnt, und ob die Zeile
 * mit dem Ortssymbol ein Ort oder ein Untertitel ist, steht nirgends. Genau
 * das sagt dieser Block: Titel, Beginn und Ende mit Zeitzonenversatz, Ort,
 * Bild, Beschreibung und Adresse des Termins — die Voraussetzung dafür, dass
 * eine Suchmaschine einen Termin als Termin behandelt statt als Absatz.
 *
 * Ausgegeben wird er vom Renderer neben dem Markup (siehe
 * EventListRenderer::render()/renderDetail()) und nicht aus einem Template
 * heraus: Ein Theme darf jedes Layout-Template überschreiben, und eine solche
 * Kopie hätte den Block sonst still verloren.
 *
 * Nachgeladene Seiten („Weitere Termine laden") bekommen bewusst keinen: Sie
 * entstehen erst nach einem Klick, und was ein Crawler von den späteren
 * Terminen sehen soll, steht in der Sitemap (siehe EventSitemap) und auf den
 * Terminseiten selbst.
 */
final class EventSchema
{
    /**
     * Wie viele Wörter der Beschreibung mitgehen. Die Angabe ist eine
     * Zusammenfassung für die Suchmaschine, nicht der Text selbst — der steht
     * sichtbar auf der Terminseite. Ein Programmheft als Beschreibung würde
     * die Seite sonst um ein Vielfaches ihres eigenen Inhalts aufblähen, und
     * zwar für jeden Termin der Liste.
     */
    private const DESCRIPTION_WORDS = 60;

    /**
     * Die Termine, die auf dieser Seite bereits als strukturierte Daten
     * ausgegeben wurden.
     *
     * Auf einer Seite können mehrere Ansichten stehen — „Nächster Termin"
     * oben, die vollständige Liste darunter ist das übliche Muster —, und
     * derselbe Termin steht dann in beiden. Als Kachel ist das gewollt; als
     * Angabe für Suchmaschinen wäre es dieselbe Veranstaltung zweimal
     * angemeldet, mit derselben Adresse und demselben Datum. Also zählt der
     * erste Block, der ihn nennt.
     *
     * @var array<int, true>
     */
    private static array $rendered = [];

    /**
     * Der Block für eine Liste/ein Raster: eine ItemList mit einem Event je
     * Kachel, in der Reihenfolge, in der sie auf der Seite stehen.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public static function listScript(array $events, string $clickBehavior): string
    {
        $linked = $clickBehavior !== 'none';
        $items = [];

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            if ($id > 0 && isset(self::$rendered[$id])) {
                continue;
            }

            $data = self::forEvent($event, $linked);
            if ($data === []) {
                continue;
            }

            if ($id > 0) {
                self::$rendered[$id] = true;
            }

            $item = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
            ];

            // Die Adresse gehört an beide Stellen: am ListItem ist sie das,
            // wohin der Eintrag der Liste führt, im Event die Adresse des
            // Termins selbst. Ohne Verweisziel (Klickart „Nichts") entfällt
            // sie hier wie dort - dann ist die Liste die einzige Seite, auf
            // der dieser Termin steht.
            if (isset($data['url'])) {
                $item['url'] = $data['url'];
            }

            $item['item'] = $data;
            $items[] = $item;
        }

        if ($items === []) {
            return '';
        }

        return self::script([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $items,
        ]);
    }

    /**
     * Der Block für die Einzelansicht: genau ein Event, ohne Liste darum.
     *
     * @param array<string, mixed> $event
     */
    public static function detailScript(array $event): string
    {
        $data = self::forEvent($event, true);

        if ($data === []) {
            return '';
        }

        return self::script(['@context' => 'https://schema.org'] + $data);
    }

    /**
     * Nur für Tests: die Buchführung darüber, welche Termine schon
     * ausgegeben wurden, wieder leeren. Im Betrieb endet sie mit dem Request,
     * hier laufen mehrere „Seiten" nacheinander im selben Prozess.
     */
    public static function reset(): void
    {
        self::$rendered = [];
    }

    /**
     * Ein Termin als schema.org/Event — leeres Array, wenn die Zeile kein
     * brauchbares Datum hat. Das ist kein theoretischer Fall: `start_date`
     * kommt aus der Datenbank und damit aus einem fremden System, und ein
     * unlesbares Datum darf die Seite nicht mit einem Fatal beenden, nur weil
     * ein Zusatz zum Markup nicht gebaut werden kann.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    public static function forEvent(array $event, bool $linked = true): array
    {
        $title = trim((string) ($event['title'] ?? ''));
        $allDay = !empty($event['all_day']);

        if ($title === '') {
            return [];
        }

        try {
            $start = self::isoDate((string) ($event['start_date'] ?? ''), $allDay);
            $end = self::isoDate((string) ($event['end_date'] ?? ''), $allDay);
        } catch (Throwable $e) {
            return [];
        }

        if ($start === '') {
            return [];
        }

        $data = [
            '@type' => 'Event',
            'name' => $title,
            'startDate' => $start,
            /*
             * Abgesagte Termine kommen beim Abgleich nicht als „abgesagt" an,
             * sie verschwinden aus der Antwort und damit aus dieser Tabelle
             * (siehe Sync\SyncEngine). Was hier steht, ist also immer ein
             * stattfindender Termin - EventCancelled kann diese Datenlage gar
             * nicht ausdrücken.
             */
            'eventStatus' => 'https://schema.org/EventScheduled',
        ];

        if ($end !== '' && $end !== $start) {
            $data['endDate'] = $end;
        }

        $description = self::description($event);
        if ($description !== '') {
            $data['description'] = $description;
        }

        $location = trim((string) ($event['location'] ?? ''));
        if ($location !== '') {
            $data['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
            /*
             * name und address tragen beide denselben Text, weil es nur einen
             * gibt: ChurchTools führt den Ort als eine Zeile („Gemeindehaus,
             * Musterstraße 1"), nicht als Haus, Straße, Ort. Ihn hier in Teile
             * zu zerlegen hieße raten, und eine falsch geratene Adresse ist
             * schlechter als eine unzerlegte.
             */
            $data['location'] = [
                '@type' => 'Place',
                'name' => $location,
                'address' => $location,
            ];
        }

        $image = trim((string) ($event['image_url'] ?? ''));
        if ($image !== '') {
            $data['image'] = $image;
        }

        $detailUrl = trim((string) ($event['detail_url'] ?? ''));
        if ($linked && $detailUrl !== '') {
            $data['url'] = $detailUrl;
        }

        $organizer = trim((string) get_bloginfo('name'));
        if ($organizer !== '') {
            $data['organizer'] = [
                '@type' => 'Organization',
                'name' => $organizer,
                'url' => home_url('/'),
            ];
        }

        return $data;
    }

    /**
     * Beschreibung, Untertitel oder nichts — in dieser Reihenfolge. Der
     * Untertitel springt nur ein, wenn es keine Beschreibung gibt: Zwei Sätze
     * über denselben Termin wären in einer Angabe, die als Zusammenfassung
     * gilt, eine Verdopplung.
     *
     * @param array<string, mixed> $event
     */
    private static function description(array $event): string
    {
        $text = trim((string) ($event['description'] ?? ''));

        if ($text === '') {
            $text = trim((string) ($event['subtitle'] ?? ''));
        }

        if ($text === '') {
            return '';
        }

        // In JSON-LD steht eine Zusammenfassung, kein gesetzter Text: ein
        // Programm mit einer Zeile je Programmpunkt käme sonst als
        // Absatzsalat an (siehe EventFormatter::plainText()).
        return trim(wp_trim_words(EventFormatter::plainText($text), self::DESCRIPTION_WORDS, '…'));
    }

    /**
     * Der gespeicherte Zeitpunkt als ISO-8601-Wert. Zwei Punkte daran sind
     * nicht offensichtlich:
     *
     * `start_date`/`end_date` stehen in der Zeitzone der Website (Sync\SyncEngine
     * rechnet die UTC-Zeitstempel von ChurchTools beim Import um). Ohne den
     * Versatz („+02:00") wäre die Angabe damit im Sommer eine Stunde und im
     * Winter zwei Stunden falsch — je nachdem, was der Leser annimmt.
     *
     * Ganztägige Termine bekommen nur das Datum. Ihr Zeitanteil ist
     * bedeutungslos (die Ansichten zeigen ihn aus demselben Grund nicht, siehe
     * EventFormatter::timeRange()), und „00:00" wäre eine Uhrzeit, die
     * niemand gemeint hat.
     */
    private static function isoDate(string $mysqlDate, bool $allDay): string
    {
        if (trim($mysqlDate) === '' || str_starts_with($mysqlDate, '0000-00-00')) {
            return '';
        }

        $date = new DateTimeImmutable($mysqlDate, wp_timezone());

        return $allDay ? $date->format('Y-m-d') : $date->format('c');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function script(array $data): string
    {
        /*
         * JSON_HEX_TAG macht aus jedem „<" ein <. Ohne das könnte ein
         * Termintitel, der „</script>" enthält, diesen Block vorzeitig
         * beenden und den Rest als Markup in die Seite bringen - der eine
         * Weg, auf dem ausgerechnet ein Zusatz für Suchmaschinen zur
         * Sicherheitslücke würde. JSON_UNESCAPED_UNICODE hält Umlaute
         * lesbar; es betrifft nur Zeichen jenseits von ASCII und damit
         * nichts, was Markup beenden könnte.
         */
        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
