<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Address;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Baut aus einer Terminzeile eine iCalendar-Datei (RFC 5545), wie sie der
 * Knopf „Importieren" ausliefert.
 *
 * Bewusst ohne WordPress-Abhängigkeiten außer wp_timezone()/home_url() — die
 * Formatarbeit steckt hier, und sie lässt sich so ohne WordPress prüfen.
 * Dieselbe Trennung wie bei EventSitemap::renderXml().
 *
 * Was eine solche Datei NICHT kann, und das ist die wichtigste Eigenschaft:
 * Sie ist eine Momentaufnahme. Ist sie einmal importiert, gehört der Eintrag
 * dem Kalender des Besuchers; ändert sich der Termin, erfährt davon niemand.
 * Abgefangen ist allein das doppelte Herunterladen — über eine stabile UID
 * und eine mitwachsende SEQUENCE erkennt der Kalender den zweiten Import als
 * *dieselbe* Veranstaltung und aktualisiert sie, statt eine zweite anzulegen.
 * Absagen bleiben außerhalb dessen, was hier möglich ist: Der Sync löscht
 * abgesagte Termine (EventRepository::deleteOrphans()), es gibt also keinen
 * Grabstein, aus dem sich ein METHOD:CANCEL bauen ließe. Wer Absagen
 * mitbekommen will, braucht ein Abonnement statt eines Downloads (siehe
 * plan.md).
 */
final class Ics
{
    /**
     * Bezugspunkt der SEQUENCE. Die Eigenschaft ist laut RFC ein INTEGER, und
     * das heißt höchstens 2147483647 — ein roher Unix-Zeitstempel liefe 2038
     * darüber hinaus. Minuten seit 2020 bleiben klein, wachsen monoton mit
     * jeder Aenderung und reichen bis weit ins nächste Jahrhundert.
     */
    private const SEQUENCE_EPOCH = '2020-01-01 00:00:00';

    /** Nach RFC 5545 höchstens 75 Oktetts je Zeile, Zeilenumbruch nicht mitgezählt. */
    private const MAX_OCTETS = 75;

    /** Bekannte Bildtypen für ATTACH;FMTTYPE — unbekannte bleiben ohne Angabe. */
    private const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    /**
     * @param array<string, mixed> $event Angereichert wie in EventListRenderer::withCalendarMeta().
     */
    public static function forEvent(array $event): string
    {
        return self::forEvents([$event]);
    }

    /**
     * Mehrere Termine in einer Datei — das, was „Alle N Termine“ ausliefert.
     *
     * Ein VCALENDAR darf beliebig viele VEVENTs tragen; mehr ist daran nicht.
     * Jedes behält seine eigene UID aus uid(), der Kalender legt also N
     * Einträge an und erkennt beim zweiten Herunterladen jeden einzelnen
     * wieder.
     *
     * Bewusst *kein* RRULE, obwohl hier eine Serie beisammen liegt: ChurchTools
     * liefert kein Wiederholungsmuster, sondern ein Envelope je tatsächlichem
     * Vorkommnis, und mapOccurrence() speichert das genauso. Aus den Abständen
     * eine Regel zu erschließen wäre Raten, und an den echten Daten ginge es
     * schief — „Gottesdienst“ steht mit 10 Terminen über vier Monate, das sind
     * erkennbar keine sauberen sieben Tage. Ausgeschriebene Einzeltermine sind
     * die ehrliche Fassung.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public static function forEvents(array $events): string
    {
        return self::build($events);
    }

    /**
     * Dieselbe Datei als Abonnement (Frontend\EventFeed).
     *
     * Drei Kopfzeilen mehr, und jede beantwortet eine Frage, die sich beim
     * Download nicht stellt:
     *
     * - `X-WR-CALNAME` ist der Name, unter dem das Abonnement in der Liste des
     *   Besuchers steht. Ohne ihn zeigen manche Programme die Adresse.
     * - `REFRESH-INTERVAL` (RFC 7986) und `X-PUBLISHED-TTL` (die ältere,
     *   weiter verbreitete Fassung derselben Angabe) sagen, wie oft
     *   nachgefragt werden soll. Eine Stunde entspricht dem üblichen
     *   Sync-Intervall; wie oft wirklich abgeholt wird, entscheidet am Ende
     *   der Kalender des Besuchers und nicht diese Datei.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public static function forFeed(array $events, string $name = ''): string
    {
        $kopf = [
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        if (trim($name) !== '') {
            $kopf[] = self::line('X-WR-CALNAME', self::escapeText(trim($name)));
        }

        return self::build($events, $kopf);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param string[]                         $extraHeaders
     */
    private static function build(array $events, array $extraHeaders = []): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            self::line('PRODID', self::prodId()),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...$extraHeaders,
        ];

        foreach ($events as $event) {
            $lines = array_merge($lines, ['BEGIN:VEVENT'], self::eventLines($event), ['END:VEVENT']);
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF, nicht LF: Das Format schreibt es vor, und Outlook nimmt eine
        // Datei mit bloßen Zeilenvorschüben teilweise gar nicht an.
        return implode("\r\n", array_filter($lines)) . "\r\n";
    }

    /**
     * Der Dateiname, unter dem der Browser sie ablegt. Aus dem Slug des
     * Termins, damit im Downloadordner nicht zehnmal „termin.ics" liegt.
     *
     * @param array<string, mixed> $event
     */
    public static function filename(array $event): string
    {
        $slug = EventSlug::forEvent($event);

        return ($slug === '' ? 'termin' : $slug) . '.ics';
    }

    /**
     * Der Dateiname der Serienfassung. Ohne das Datum, das
     * EventSlug::forEvent() anhängt: Die Datei trägt nicht *einen* Termin,
     * und ein Datum im Namen wäre die Behauptung, sie täte es.
     *
     * @param array<string, mixed> $event Irgendein Vorkommnis der Serie.
     */
    public static function seriesFilename(array $event): string
    {
        $slug = sanitize_title((string) ($event['title'] ?? ''));

        return ($slug === '' ? 'termine' : $slug) . '-serie.ics';
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return string[]
     */
    private static function eventLines(array $event): array
    {
        $allDay = !empty($event['all_day']);
        $start = (string) ($event['start_date'] ?? '');
        $end = (string) ($event['end_date'] ?? '');

        $lines = [
            self::line('UID', self::uid($event)),
            self::line('DTSTAMP', self::utc(current_time('mysql'))),
        ];

        /*
         * Ganztägig ist der eine Fall, in dem ein Datum und keine Zeit
         * ausgegeben wird — und in dem DTEND *exklusiv* ist: Es benennt den
         * ersten Tag, der nicht mehr dazugehört. Das gespeicherte end_date
         * ist dagegen inklusiv (an den echten Daten geprüft: „Frauenwochenende"
         * steht als 23.10. bis 25.10. und meint drei Tage), also einen Tag
         * dazu. Ohne diese Verschiebung erschiene jeder ganztägige Termin im
         * Kalender einen Tag zu kurz — der Klassiker dieses Formats.
         *
         * Die Uhrzeit der gespeicherten Werte bleibt hier außen vor. Sie ist
         * bei ganztägigen Terminen ein Umrechnungsartefakt (ChurchTools
         * liefert UTC-Mitternacht, was in Berliner Zeit 01:00 oder 02:00
         * ergibt) und meint nichts.
         */
        if ($allDay) {
            $lines[] = self::line('DTSTART', self::dateOnly($start), ['VALUE' => 'DATE']);
            $lines[] = self::line('DTEND', self::dateOnly($end, 1), ['VALUE' => 'DATE']);
        } else {
            $lines[] = self::line('DTSTART', self::utc($start));
            $lines[] = self::line('DTEND', self::utc($end));
        }

        $lines[] = self::line('SUMMARY', self::escapeText((string) ($event['title'] ?? '')));

        $description = self::description($event);
        if ($description !== '') {
            $lines[] = self::line('DESCRIPTION', self::escapeText($description));
        }

        $location = trim((string) ($event['location'] ?? ''));
        if ($location !== '') {
            /*
             * Benennt die Zeile einen Raum im Haus der Gemeinde (Merker aus
             * dem Sync), kommt deren Anschrift dahinter: „Saal 1" allein ist
             * für einen Kalender auf dem Handy keine Adresse, aus der er eine
             * Route bauen kann. Der Gebäudename bleibt dabei draußen, den
             * stellt hier der Raum.
             */
            $atChurch = !empty($event['location_at_church']);
            $address = $atChurch ? SettingsPage::churchAddress() : self::ownAddress($event);

            /*
             * Die Anschrift kommt nur beim Raum im eigenen Haus dazu — trägt
             * der Termin eine eigene Adresse, steht sie schon in der Zeile
             * (SyncEngine::formatAddress() hat sie zusammengesetzt), und ein
             * zweites Mal wäre sie eine Wiederholung.
             */
            $postalLine = $atChurch && $address !== [] ? Address::postalLine($address) : '';

            if ($postalLine !== '') {
                $location .= ', ' . $postalLine;
            }

            $lines[] = self::line('LOCATION', self::escapeText($location));

            /*
             * Die Koordinaten dagegen gehören in beide Fälle — bei einem
             * auswärtigen Termin sind sie sogar das Wertvollste, was die
             * Datei trägt: Eine Karten-App findet damit auch ein Freibad ohne
             * Hausnummer, statt die Adresszeile raten zu müssen.
             */
            $geo = $address === [] ? [] : Address::geo($address);

            if ($geo !== []) {
                // GEO trennt mit Semikolon und ist kein Text: keine Maskierung,
                // sonst stünde dort „49.06\;8.72".
                $lines[] = self::line('GEO', $geo['latitude'] . ';' . $geo['longitude']);
            }
        }

        $calendar = trim((string) ($event['calendar_name'] ?? ''));
        if ($calendar !== '') {
            $lines[] = self::line('CATEGORIES', self::escapeText($calendar));
        }

        /*
         * URI-Werte werden *nicht* wie Text maskiert — ein Komma in einer
         * Adresse ist ein Komma und kein Trennzeichen. Sie durch escapeText()
         * zu schicken hätte jede Adresse mit Komma oder Semikolon zerlegt.
         */
        $url = trim((string) ($event['detail_url'] ?? ''));
        if ($url !== '') {
            $lines[] = self::line('URL', $url);
        }

        $image = trim((string) ($event['image_url'] ?? ''));
        if ($image !== '') {
            // IMAGE stammt aus RFC 7986 und ist das, was neuere Kalender als
            // Vorschaubild zeigen; ATTACH ist die ältere Fassung derselben
            // Angabe. Was ein Programm nicht kennt, überspringt es.
            $lines[] = self::line('IMAGE', $image, ['VALUE' => 'URI', 'DISPLAY' => 'BADGE']);
            $lines[] = self::line('ATTACH', $image, self::imageType($image));
        }

        $lines[] = 'STATUS:CONFIRMED';
        // Der Termin belegt Zeit — wer ihn speichert, will als beschäftigt
        // gelten. TRANSPARENT wäre „nur zur Kenntnis".
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = self::line('SEQUENCE', (string) self::sequence($event));

        $created = self::utc((string) ($event['created_at'] ?? ''));
        if ($created !== '') {
            $lines[] = self::line('CREATED', $created);
        }

        $modified = self::utc((string) ($event['updated_at'] ?? ''));
        if ($modified !== '') {
            $lines[] = self::line('LAST-MODIFIED', $modified);
        }

        return $lines;
    }

    /**
     * Die Adresse des Termins selbst, wie der Sync sie in `location_data`
     * abgelegt hat — leer, wo keine gespeichert ist oder die Zeile älter ist
     * als dieses Feld.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, string>
     */
    private static function ownAddress(array $event): array
    {
        $stored = trim((string) ($event['location_data'] ?? ''));

        if ($stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Untertitel und Beschreibung in einem Feld — iCalendar kennt keinen
     * Untertitel, und ihn wegzulassen hieße, eine gepflegte Angabe zu
     * verlieren. Der gespeicherte Beschreibungstext kann HTML enthalten (eine
     * ChurchTools-Instanz darf welches liefern, siehe
     * EventFormatter::descriptionHtml()); im Kalender hat Markup nichts zu
     * suchen, also raus damit und Entitäten auflösen.
     *
     * @param array<string, mixed> $event
     */
    private static function description(array $event): string
    {
        $teile = [];

        $subtitle = trim((string) ($event['subtitle'] ?? ''));
        if ($subtitle !== '') {
            $teile[] = $subtitle;
        }

        $description = trim((string) ($event['description'] ?? ''));
        if ($description !== '') {
            $teile[] = trim(html_entity_decode(
                wp_strip_all_tags($description),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }

        return trim(implode("\n\n", array_filter($teile)));
    }

    /**
     * Die Kennung, an der ein Kalender den zweiten Import als denselben Termin
     * erkennt. Aus ChurchTools-ID und Startdatum — genau das Paar, das schon
     * der Unique-Key der Tabelle ist, und damit stabil über einen
     * Neuaufbau der Tabelle hinweg (die lokale `id` wäre es nicht, sie ist ein
     * Auto-Increment). Der Host dahinter, weil die Kennung laut RFC weltweit
     * eindeutig sein soll und zwei Gemeinden dieselbe ChurchTools-ID haben
     * können.
     *
     * @param array<string, mixed> $event
     */
    private static function uid(array $event): string
    {
        // parse_url() statt wp_parse_url(): Letzteres gibt es nur wegen eines
        // PHP-5-Fehlers bei relativen Adressen, und dieses Plugin verlangt 8.1.
        $host = (string) parse_url((string) home_url('/'), PHP_URL_HOST);

        return sprintf(
            'ctp-%d-%s@%s',
            (int) ($event['ct_event_id'] ?? 0),
            self::dateOnly((string) ($event['start_date'] ?? '')),
            $host === '' ? 'churchtools-plugin.invalid' : $host
        );
    }

    /**
     * Minuten seit SEQUENCE_EPOCH. Wächst mit jeder Aenderung am Termin und
     * bleibt im INTEGER-Bereich, den das Format erlaubt.
     *
     * @param array<string, mixed> $event
     */
    private static function sequence(array $event): int
    {
        $updated = trim((string) ($event['updated_at'] ?? ''));
        if ($updated === '' || str_starts_with($updated, '0000-00-00')) {
            return 0;
        }

        try {
            $zone = wp_timezone();
            $seit = (new DateTimeImmutable(self::SEQUENCE_EPOCH, $zone))->getTimestamp();
            $stand = (new DateTimeImmutable($updated, $zone))->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }

        return max(0, (int) floor(($stand - $seit) / 60));
    }

    private static function prodId(): string
    {
        $version = defined('CTP_VERSION') ? CTP_VERSION : 'dev';

        return '-//wirsindcgks//ChurchTools Events ' . $version . '//DE';
    }

    /**
     * @return array<string, string>
     */
    private static function imageType(string $url): array
    {
        $pfad = (string) parse_url($url, PHP_URL_PATH);
        $endung = strtolower((string) pathinfo($pfad, PATHINFO_EXTENSION));

        return isset(self::IMAGE_TYPES[$endung]) ? ['FMTTYPE' => self::IMAGE_TYPES[$endung]] : [];
    }

    /**
     * Ein gespeicherter Zeitpunkt (Ortszeit der Seite) als UTC-Stempel. UTC
     * statt eines eigenen VTIMEZONE-Blocks: Jede Datei trägt genau ein
     * Vorkommnis (eine Serie ist in dieser Datenhaltung keine Regel, sondern
     * eine Zeile je Termin), und dafür ist der Zeitpunkt in UTC eindeutig und
     * von jedem Kalender richtig verstanden.
     */
    private static function utc(string $mysqlDate): string
    {
        $mysqlDate = trim($mysqlDate);
        if ($mysqlDate === '' || str_starts_with($mysqlDate, '0000-00-00')) {
            return '';
        }

        try {
            return (new DateTimeImmutable($mysqlDate, wp_timezone()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Ymd\THis\Z');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Nur der Tag, für ganztägige Termine. $plusDays verschiebt ihn — siehe
     * die Begründung zu DTEND oben.
     */
    private static function dateOnly(string $mysqlDate, int $plusDays = 0): string
    {
        $mysqlDate = trim($mysqlDate);
        if ($mysqlDate === '' || str_starts_with($mysqlDate, '0000-00-00')) {
            return '';
        }

        try {
            $date = new DateTimeImmutable($mysqlDate, wp_timezone());
            if ($plusDays !== 0) {
                $date = $date->modify(sprintf('%+d days', $plusDays));
            }

            return $date->format('Ymd');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Maskiert einen TEXT-Wert. Reihenfolge zählt: Der Backslash zuerst,
     * sonst maskiert der Durchgang die Backslashes wieder mit, die die
     * späteren Ersetzungen selbst eingefügt haben.
     */
    private static function escapeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\;', '\\,', '\\n'],
            $value
        );
    }

    /**
     * @param array<string, string> $params
     */
    private static function line(string $name, string $value, array $params = []): string
    {
        if ($value === '') {
            return '';
        }

        $vorspann = $name;
        foreach ($params as $feld => $wert) {
            $vorspann .= ';' . $feld . '=' . $wert;
        }

        return self::fold($vorspann . ':' . $value);
    }

    /**
     * Faltet eine zu lange Zeile. Gezählt wird in *Oktetts* und nicht in
     * Zeichen, wie das Format es vorschreibt — gebrochen wird aber nur
     * zwischen Zeichen: Ein Schnitt mitten durch ein mehrteiliges UTF-8-Zeichen
     * ergäbe zwei kaputte Hälften, und deutsche Termintitel sind voller
     * Umlaute.
     *
     * Fortsetzungszeilen beginnen mit einem Leerzeichen, das beim Einlesen
     * wieder wegfällt — und das zu den 75 Oktetts dieser Zeile zählt, also
     * bleiben dort 74 für den Inhalt.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_OCTETS) {
            return $line;
        }

        $zeilen = [];
        $aktuell = '';
        $grenze = self::MAX_OCTETS;

        foreach (mb_str_split($line, 1, 'UTF-8') as $zeichen) {
            if (strlen($aktuell) + strlen($zeichen) > $grenze) {
                $zeilen[] = $aktuell;
                $aktuell = '';
                $grenze = self::MAX_OCTETS - 1;
            }

            $aktuell .= $zeichen;
        }

        if ($aktuell !== '') {
            $zeilen[] = $aktuell;
        }

        return implode("\r\n ", $zeilen);
    }
}
