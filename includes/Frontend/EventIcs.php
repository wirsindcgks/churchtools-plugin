<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;

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

    public static function maybeRenderIcs(): void
    {
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }

        $event = EventDetailPage::currentEvent();
        if ($event === null) {
            return;
        }

        $event = self::withMeta($event);

        // Kein nocache_headers(): Eine Termindatei ändert sich mit dem
        // Abgleich und nicht mit dem Besucher — dieselbe Überlegung wie bei
        // der Sitemap.
        status_header(200);
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . Ics::filename($event) . '"');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ics::forEvent() maskiert jeden Wert nach den Regeln des Formats (siehe dort); HTML-Escaping wäre hier sogar falsch, die Datei ist kein Markup.
        echo Ics::forEvent($event);
        exit;
    }

    /**
     * Die paar abgeleiteten Angaben, die eine rohe Tabellenzeile nicht hat und
     * die Datei tragen soll. Bewusst hier und nicht über
     * EventListRenderer::withCalendarMeta(): Das ist ein privater Teil des
     * Rendervorgangs und baut nebenbei das komplette Popup-Markup zusammen —
     * für drei Felder wäre das viel Arbeit für nichts.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private static function withMeta(array $event): array
    {
        $calendars = SettingsPage::get()['calendars'];
        $calendar = $calendars[(int) ($event['ct_calendar_id'] ?? 0)] ?? null;

        $event['calendar_name'] = (string) ($calendar['name'] ?? '');
        $event['detail_url'] = EventDetailPage::urlForEvent($event);
        $event['image_url'] = EventListRenderer::resolveImage($event, $calendar)['url'];

        return $event;
    }
}
