<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;

/**
 * Der Abo-Feed: dieselben Termine als iCalendar-Datei, die ein Kalender in
 * Ruhe abholt statt sie einmalig herunterzuladen.
 *
 * Der Unterschied zum „Importieren"-Knopf (Frontend\EventIcs) ist nicht die
 * Datei, sondern die Verbindung: Ein Download gehört ab dem Klick dem Kalender
 * des Besuchers und weiß von keiner Änderung mehr; ein Abonnement fragt von
 * selbst nach, übernimmt Verschiebungen und lässt abgesagte Termine
 * verschwinden. Beide beantworten verschiedene Fragen - „ich will *diesen*
 * Termin" gegen „ich will sehen, was bei euch läuft" -, deshalb steht der eine
 * neben dem anderen und ersetzt ihn nicht.
 *
 * **Warum ein eigener Feed und nicht ChurchTools' fertiger.** ChurchTools baut
 * aus der `randomUrl` jedes Kalenders selbst einen Feed, und ihn zu verlinken
 * wäre in einer Stunde erledigt gewesen. Er enthält aber, was ChurchTools
 * hineinlegt, nicht das, was diese Website zeigt: Ob er einen Termin mit
 * „nur für angemeldete Benutzer" herausfiltert, ist ungeprüft (an der
 * Instanz gibt es keinen einzigen, es liesse sich also nur durch einen
 * Eingriff in eine produktive Gemeinde beantworten), und an der
 * Kalenderauswahl des Backends geht er ohnehin vorbei. Dieser Feed dagegen
 * liest die Tabelle, in der genau das steht, was die Website zeigt - interne
 * Termine sind dort beim Abgleich schon weg (SyncEngine::mapOccurrence()),
 * nicht angehakte Kalender nie hineingekommen. Der Preis ist etwas Code; was
 * man dafür bekommt, ist eine Zusage, die auch in zwei Jahren noch gilt.
 *
 * Und ein zweiter Punkt, der nichts kostet: Die `randomUrl` ist ein
 * Zugangsschlüssel. Sie zu veröffentlichen wäre bei einem öffentlichen
 * Kalender harmlos und bei einem nicht öffentlichen der Schlüssel zu allem,
 * was darin steht.
 */
final class EventFeed
{
    private const QUERY_VAR = 'ctp_feed';
    private const PATH = 'churchtools-termine.ics';

    /**
     * Der Parameter, der die Auswahl trägt - dieselbe Schreibweise wie im
     * Shortcode-Attribut `calendar`: IDs oder Namen, mit Komma getrennt.
     */
    private const CALENDAR_PARAM = 'kalender';

    /**
     * Obergrenze einer Antwort. Der Sync-Zeitraum ist einstellbar (Vorgabe ein
     * Jahr), und eine Gemeinde mit wöchentlichen Terminen in dreizehn
     * Kalendern liegt bei einigen hundert - diese Grenze ist also weit weg und
     * verhindert nur, dass eine fehlgeleitete Einstellung eine Antwort von
     * vielen Megabyte baut.
     */
    private const MAX_EVENTS = 2000;

    public static function registerHooks(): void
    {
        // Dieselbe Reihenfolge wie bei EventSitemap, aus demselben Grund:
        // EventDetailPage::registerRewriteRule() haengt auf Prioritaet 10 und
        // schreibt den Regelsatz bei Bedarf neu - diese Regel muss vorher
        // stehen, sonst kaeme sie erst beim uebernaechsten Anlass mit.
        add_action('init', [self::class, 'registerRewriteRule'], 9);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        // Prioritaet 9, also vor `redirect_canonical` (10): Sonst schickt
        // WordPress die Adresse auf ihre Schraegstrich-Fassung, genau wie es
        // die Termin-Sitemap einmal getroffen hat (1.17.x).
        add_action('template_redirect', [self::class, 'maybeRenderFeed'], 9);
    }

    public static function registerRewriteRule(): void
    {
        add_rewrite_rule('^' . preg_quote(self::PATH, '/') . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
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
     * Die Adresse des Feeds - ohne sprechende Permalinks die
     * Query-String-Form, weil Rewrite-Regeln dort gar nicht greifen (dieselbe
     * Rueckfallebene wie EventSitemap::url()).
     *
     * @param string $calendars Auswahl wie im Shortcode: IDs oder Namen mit Komma
     */
    public static function url(string $calendars = ''): string
    {
        $base = get_option('permalink_structure') === ''
            ? home_url('/?' . self::QUERY_VAR . '=1')
            : home_url('/' . self::PATH);

        $calendars = trim($calendars);

        if ($calendars === '') {
            return $base;
        }

        return add_query_arg(self::CALENDAR_PARAM, rawurlencode($calendars), $base);
    }

    /**
     * Dieselbe Adresse mit `webcal://`. Das ist kein eigenes Protokoll,
     * sondern eine Verabredung: iOS, macOS, Outlook und Thunderbird erkennen
     * daran ein Abonnement und richten es ein, statt die Datei einmalig zu
     * oeffnen - und genau das ist der Unterschied, um den es hier geht. Wer
     * damit nichts anfangen kann, bekommt ueber `https://` dieselbe Datei.
     */
    public static function webcalUrl(string $calendars = ''): string
    {
        return (string) preg_replace('#^https?://#', 'webcal://', self::url($calendars));
    }

    public static function maybeRenderFeed(): void
    {
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }

        $events = self::events(self::requestedCalendars());

        // Kein nocache_headers(): Ein Feed darf zwischengespeichert werden, er
        // aendert sich mit dem Abgleich und nicht mit dem Besucher. Die Stunde
        // entspricht der Vorgabe des Sync-Intervalls - oefter nachzufragen
        // brauchte niemand, seltener liesse eine Absage zu lange stehen.
        status_header(200);
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Cache-Control: max-age=3600');
        header('X-Robots-Tag: noindex, follow', true);

        /*
         * Bewusst *kein* Content-Disposition: attachment. Der Kopf wuerde aus
         * dem Abonnement wieder einen Download machen - genau die Unterscheidung,
         * um die es hier geht (EventIcs setzt ihn deshalb sehr wohl).
         */
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ics::forFeed() maskiert jeden Wert nach RFC 5545 selbst, siehe dort.
        echo Ics::forFeed($events, self::feedName());

        exit;
    }

    /**
     * Die Termine des Feeds: kuenftige, in der Auswahl, gedeckelt.
     *
     * Vergangene bleiben draussen. Ein Abonnement spiegelt den Feed - was
     * herausfaellt, raeumt der Kalender des Besuchers weg -, und eine Liste,
     * die rueckwaerts waechst, waere fuer niemanden hier eine Auskunft.
     *
     * @param array<int, int> $calendarIds
     *
     * @return array<int, array<string, mixed>>
     */
    private static function events(array $calendarIds): array
    {
        $events = (new EventRepository())->findUpcoming($calendarIds, self::MAX_EVENTS);

        // Dieselbe Anreicherung wie beim Download: Kalendername, Adresse des
        // Termins und das Bild aus der Mediathek.
        return EventIcs::withMeta($events);
    }

    /**
     * Die Kalenderauswahl aus der Adresse, aufgeloest wie im Shortcode (IDs
     * oder Namen). Eine leere oder unbekannte Angabe heisst „alle aktiven" -
     * dasselbe, was der Shortcode ohne `calendar` zeigt.
     *
     * Ohne Nonce, wie beim Nachlade-Endpunkt (Frontend\EventsEndpoint): Das
     * hier ist ein oeffentlicher Lesezugriff auf Daten, die ohnehin auf der
     * Seite stehen, und ein Kalenderprogramm kann keinen Nonce mitschicken.
     *
     * @return array<int, int>
     */
    private static function requestedCalendars(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- oeffentlicher Lesezugriff, Begruendung im Docblock.
        $raw = isset($_GET[self::CALENDAR_PARAM]) ? sanitize_text_field(wp_unslash((string) $_GET[self::CALENDAR_PARAM])) : '';

        // Zerlegt wie im Shortcode: resolveCalendarIds() nimmt die einzelnen
        // Angaben, nicht die Zeile.
        $refs = array_filter(array_map('trim', explode(',', $raw)));

        return $refs === [] ? [] : SettingsPage::resolveCalendarIds($refs);
    }

    /**
     * Der Name, unter dem das Abonnement im Kalender des Besuchers steht. Der
     * Name der Website und nicht „Termine": In einer Kalender-App stehen die
     * Abonnements nebeneinander, und „Termine" saehe dort aus wie der Kalender
     * von irgendwem.
     */
    private static function feedName(): string
    {
        $name = trim((string) get_bloginfo('name'));

        return $name === '' ? __('Termine', 'churchtools-plugin') : $name;
    }
}
