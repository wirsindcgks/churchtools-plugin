<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;
use DateTimeImmutable;
use Throwable;

/**
 * Eine XML-Sitemap nur mit den Terminen, unter
 * `/churchtools-termine-sitemap.xml`, angekündigt in der robots.txt.
 *
 * Sie beantwortet ein Problem, das man dem Frontend nicht ansieht: Eine
 * Terminliste zeigt ihr erstes Zeitfenster, alles Weitere kommt über „Weitere
 * Termine laden" — einen Knopf, der nachlädt. Ein Crawler klickt nicht. Ohne
 * Sitemap sind also genau die Termine auffindbar, die zufällig gerade auf der
 * ersten Seite stehen, und der Rest des Kalenders existiert für Suchmaschinen
 * nicht. Das fällt niemandem auf, weil die Liste ja vollständig aussieht,
 * sobald man selbst auf den Knopf drückt.
 *
 * Warum eine eigene Datei und nicht die Sitemap von WordPress: Sobald ein
 * SEO-Plugin im Haus ist (Yoast, Rank Math), schaltet es `wp-sitemap.xml` ab
 * und macht seine eigene — ein dort eingehängter Anbieter läge damit auf genau
 * den Seiten still, auf denen am meisten Wert auf Auffindbarkeit gelegt wird.
 * Eine eigene Adresse ist von dieser Frage unabhängig, funktioniert in beiden
 * Fällen gleich und ist über die robots.txt für jede Suchmaschine auffindbar,
 * ohne dass jemand etwas eintragen muss.
 *
 * Was drinsteht, ist dieselbe Auswahl wie im Frontend: kommende Termine aus
 * freigegebenen Kalendern. Ist als Klickverhalten „Nichts" eingestellt, gibt es
 * bewusst keine Terminseiten, und dann bleibt auch die Sitemap leer — eine
 * Adresse anzubieten, die der Betreiber im Frontend gerade nicht verlinken
 * will, wäre eine Entscheidung hinter seinem Rücken.
 */
final class EventSitemap
{
    private const QUERY_VAR = 'ctp_sitemap';
    private const PATH = 'churchtools-termine-sitemap.xml';

    /**
     * Obergrenze für eine Sitemap-Datei. Das Format erlaubt 50.000 Adressen;
     * diese Grenze liegt weit darunter, weil eine Gemeinde mit fünftausend
     * kommenden Terminen ein Datenproblem hat und keine Sitemap-Frage — und
     * weil die Antwort in einem Rutsch aufgebaut wird.
     */
    private const MAX_URLS = 5000;

    public static function registerHooks(): void
    {
        // Priorität 9: EventDetailPage::registerRewriteRule() hängt auf
        // derselben Aktion (Priorität 10) und ruft dort flush_rewrite_rules()
        // auf, wenn sich am Regelsatz etwas geändert hat. Diese Regel muss
        // deshalb vorher stehen, sonst würde sie erst beim übernächsten
        // Anlass mitgeschrieben. Der Anlass selbst ist dort vermerkt
        // (REWRITE_VERSION).
        add_action('init', [self::class, 'registerRewriteRule'], 9);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [self::class, 'maybeRenderSitemap']);
        add_filter('robots_txt', [self::class, 'announceInRobotsTxt'], 10, 2);
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
     * Die Adresse der Sitemap — ohne sprechende Permalinks die
     * Query-String-Form, denn Rewrite-Regeln greifen dort gar nicht (dieselbe
     * Rückfallebene wie EventDetailPage::url()).
     */
    public static function url(): string
    {
        if (get_option('permalink_structure') === '') {
            return home_url('/?' . self::QUERY_VAR . '=1');
        }

        return home_url('/' . self::PATH);
    }

    /**
     * Der Eintrag in der robots.txt, über den Suchmaschinen die Datei finden,
     * ohne dass jemand sie irgendwo anmeldet. Nicht auf Seiten, die auf „nicht
     * öffentlich" stehen: Deren robots.txt sperrt alles aus, und eine Sitemap
     * darunter wäre die Einladung, es doch zu versuchen.
     */
    public static function announceInRobotsTxt(string $output, $public): string
    {
        if (!$public) {
            return $output;
        }

        return rtrim($output, "\n") . "\n\nSitemap: " . self::url() . "\n";
    }

    public static function maybeRenderSitemap(): void
    {
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }

        // Kein nocache_headers(): Eine Sitemap darf und soll zwischengespeichert
        // werden - sie ändert sich mit dem Abgleich, nicht mit dem Besucher.
        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderXml() escaped jeden Wert selbst mit esc_url_raw()/esc_xml(), siehe dort.
        echo self::renderXml(self::entries());
        exit;
    }

    /**
     * Die Termine als Adressliste. Öffentlich, weil genau das die Angabe ist,
     * die sich prüfen lässt, ohne eine XML-Antwort zu zerlegen.
     *
     * @return array<int, array{loc: string, lastmod: string}>
     */
    public static function entries(): array
    {
        $settings = SettingsPage::get();

        if (($settings['click_behavior'] ?? '') === 'none') {
            return [];
        }

        $calendarIds = SettingsPage::getEnabledCalendarIds();
        if ($calendarIds === []) {
            return [];
        }

        $entries = [];

        foreach ((new EventRepository())->findUpcoming($calendarIds, self::MAX_URLS) as $event) {
            $loc = EventDetailPage::urlForEvent($event);
            if ($loc === '') {
                continue;
            }

            $entries[] = [
                'loc' => $loc,
                'lastmod' => self::lastmod($event),
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array{loc: string, lastmod: string}> $entries
     */
    public static function renderXml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= "\t<url>\n\t\t<loc>" . esc_xml(esc_url_raw($entry['loc'])) . "</loc>\n";

            if ($entry['lastmod'] !== '') {
                $xml .= "\t\t<lastmod>" . esc_xml($entry['lastmod']) . "</lastmod>\n";
            }

            $xml .= "\t</url>\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * Wann die Zeile zuletzt vom Abgleich angefasst wurde — nicht, wann der
     * Termin stattfindet. Genau das fragt <lastmod>: Hat sich seit dem letzten
     * Besuch etwas geändert? Fehlt oder verrutscht der Wert, bleibt die Angabe
     * lieber weg, als ein falsches Datum zu behaupten.
     *
     * @param array<string, mixed> $event
     */
    private static function lastmod(array $event): string
    {
        $updated = trim((string) ($event['updated_at'] ?? ''));

        if ($updated === '' || str_starts_with($updated, '0000-00-00')) {
            return '';
        }

        try {
            return (new DateTimeImmutable($updated, wp_timezone()))->format('c');
        } catch (Throwable $e) {
            return '';
        }
    }
}
