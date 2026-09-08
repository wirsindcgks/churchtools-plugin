<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;

/**
 * Die Adresse, unter der ein Termin als iCalendar-Datei herauskommt — das, was
 * der Knopf „Importieren" in der Detailansicht verlinkt.
 *
 * Bewusst *keine* eigene Rewrite-Regel, anders als bei der Sitemap: Der Termin
 * ist an dieser Stelle längst aufgelöst, entweder als Elternseiten-Route oder
 * über die ID (siehe EventDetailPage). Eine zweite Adressform hätte dieselbe
 * Auflösung ein zweites Mal gebraucht — Slug zerlegen, Kalender prüfen, 404
 * entscheiden —, und zwei Wege zu derselben Antwort laufen früher oder
 * später auseinander. Stattdessen hängt hier nur ein Query-Parameter an der
 * Adresse, die es ohnehin gibt.
 *
 * Nebenbei umgeht das eine Falle der lokalen Testumgebung: Der eingebaute
 * PHP-Server bedient jede URI mit bekannter Dateiendung von der Platte und
 * erreicht index.php nie — eine Adresse auf `.ics` wäre dort unerreichbar
 * gewesen, so wie es die Sitemap war. Der Dateiname entsteht ohnehin aus
 * `Content-Disposition` und nicht aus der Adresse.
 */
final class EventIcs
{
    public const QUERY_VAR = 'ctp_ics';

    /**
     * Der Wert, unter dem statt des einen Termins die ganze Serie
     * herauskommt. Zwei Werte an einem Parameter und nicht ein zweiter
     * Parameter: Es ist dieselbe Frage („was soll in der Datei stehen?“) und
     * damit eine Angabe, die genau einen Wert hat.
     */
    public const SERIES_VALUE = 'serie';

    public static function registerHooks(): void
    {
        add_filter('query_vars', [self::class, 'addQueryVar']);
        /*
         * Priorität 9, also vor `redirect_canonical` und vor
         * EventDetailPage::maybeRenderDetail() (beide 10). Das erste ist die
         * Lehre aus der Sitemap, die sich sonst selbst umleitet; das zweite
         * wäre hier schlimmer: Mit gesetzter Elternseite schickt
         * maybeRenderDetail() die ID-Adresse per 301 auf die sprechende
         * Fassung — und verlöre dabei diesen Query-Parameter, sodass statt
         * der Datei die Seite käme.
         */
        add_action('template_redirect', [self::class, 'maybeRenderIcs'], 9);
    }

    /**
     * @param array<int, string> $vars
     *
     * @return array<int, string>
     */
    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * Die Adresse der Datei zu einem Termin: die des Termins, mit einem
     * Parameter daran. Damit erbt sie alles, was an der Terminadresse schon
     * richtig ist — sprechende Permalinks, Elternseite, der Rückfall auf die
     * Query-String-Form ohne Permalinks.
     *
     * @param array<string, mixed> $event
     */
    public static function urlForEvent(array $event): string
    {
        $url = EventDetailPage::urlForEvent($event);

        return $url === '' ? '' : add_query_arg(self::QUERY_VAR, '1', $url);
    }

    /**
     * Dieselbe Adresse, aber für alle künftigen Vorkommnisse derselben Serie.
     *
     * @param array<string, mixed> $event
     */
    public static function urlForSeries(array $event): string
    {
        $url = EventDetailPage::urlForEvent($event);

        return $url === '' ? '' : add_query_arg(self::QUERY_VAR, self::SERIES_VALUE, $url);
    }

    public static function maybeRenderIcs(): void
    {
        $mode = (string) get_query_var(self::QUERY_VAR);
        if ($mode !== '1' && $mode !== self::SERIES_VALUE) {
            return;
        }

        $event = EventDetailPage::currentEvent();
        if ($event === null) {
            return;
        }

        $series = $mode === self::SERIES_VALUE;

        /*
         * Die Sichtbarkeitsschranke hängt am Ankertermin, den currentEvent()
         * schon geprüft hat — auch im Serienfall. Das trägt, weil alle Zeilen
         * einer ct_event_id im selben Kalender liegen (siehe
         * EventRepository::findSeries()).
         *
         * Fällt die Serienabfrage leer aus — der Ankertermin liegt in der
         * Vergangenheit, findSeries() schneidet dort ab —, kommt die Datei mit
         * genau diesem einen Termin heraus statt leer. Ein VCALENDAR ohne
         * VEVENT ist für den Kalender des Besuchers nichts als ein
         * Fehldownload.
         */
        $events = $series ? (new EventRepository())->findSeries((int) ($event['ct_event_id'] ?? 0)) : [];
        if ($events === []) {
            $events = [$event];
        }

        /*
         * Nach der Abfrage neu entschieden und nicht vorher: Der Parameter sagt
         * nur, was gewollt war, die Zeilenzahl sagt, was herauskommt. Wer
         * `ctp_ics=serie` von Hand an einen Einzeltermin hängt, bekommt diesen
         * einen Termin — dann soll die Datei aber auch nicht „-serie" heißen.
         * (Beim Prüfen auf dem Testsystem genau so aufgefallen: Der
         * „Männerabend" ist kein Serientermin und kam als
         * `maennerabend-serie.ics` heraus.)
         */
        $series = count($events) > 1;

        $events = self::withMeta($events);

        // Kein nocache_headers(): Eine Termindatei ändert sich mit dem
        // Abgleich und nicht mit dem Besucher — dieselbe Überlegung wie bei
        // der Sitemap.
        $filename = $series ? Ics::seriesFilename($events[0]) : Ics::filename($events[0]);

        status_header(200);
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ics::forEvents() maskiert jeden Wert nach den Regeln des Formats (siehe dort); HTML-Escaping wäre hier sogar falsch, die Datei ist kein Markup.
        echo Ics::forEvents($events);
        exit;
    }

    /**
     * Die paar abgeleiteten Angaben, die eine rohe Tabellenzeile nicht hat und
     * die Datei tragen soll. Bewusst hier und nicht über
     * EventListRenderer::withCalendarMeta(): Das ist ein privater Teil des
     * Rendervorgangs und baut nebenbei das komplette Popup-Markup zusammen —
     * für drei Felder wäre das viel Arbeit für nichts.
     *
     * Über die Liste und nicht über den einzelnen Termin, seit die Serie
     * dazukam: Die Einstellungen einmal zu lesen statt sechzehnmal ist der
     * ganze Unterschied.
     *
     * @param array<int, array<string, mixed>> $events
     *
     * @return array<int, array<string, mixed>>
     */
    private static function withMeta(array $events): array
    {
        $calendars = SettingsPage::get()['calendars'];

        foreach ($events as &$event) {
            $calendar = $calendars[(int) ($event['ct_calendar_id'] ?? 0)] ?? null;

            $event['calendar_name'] = (string) ($calendar['name'] ?? '');
            $event['detail_url'] = EventDetailPage::urlForEvent($event);
            $event['image_url'] = EventListRenderer::resolveImage($event, $calendar)['url'];
        }
        unset($event);

        return $events;
    }
}
