<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventSitemap;
use ChurchToolsPlugin\Tests\Support\SqliteWpdb;
use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * Die Termin-Sitemap beantwortet ein Problem, das man dem Frontend nicht
 * ansieht: Eine Terminliste zeigt ihr erstes Zeitfenster, alles Weitere kommt
 * über einen Knopf, der nachlädt — und ein Crawler klickt nicht. Ohne diese
 * Datei sind also genau die Termine auffindbar, die gerade zufällig auf der
 * ersten Seite stehen.
 *
 * Was hier zu prüfen ist, ist deshalb nicht „sieht gut aus", sondern: Steht
 * jeder kommende Termin drin, steht keiner drin, der nicht hineingehört, und
 * ist die Datei wirklich XML.
 */
final class EventSitemapTest extends TestCase
{
    private SqliteWpdb $wpdb;

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_current_time('2026-08-18 12:00:00');
        ctp_test_set_option('permalink_structure', '/%postname%/');
        ctp_test_set_option('ctp_settings', [
            'click_behavior' => 'popup',
            'calendars' => [
                7 => ['name' => 'Gottesdienste', 'enabled' => true],
                9 => ['name' => 'Intern', 'enabled' => false],
            ],
        ]);

        $this->wpdb = ctp_test_install_wpdb();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public function testEveryUpcomingEventOfAnEnabledCalendarIsListed(): void
    {
        $this->wpdb->seedEvent(7, '2026-09-06 19:30:00', '2026-09-06 21:00:00', null, null, ['title' => 'Gottesdienst']);
        $this->wpdb->seedEvent(7, '2026-12-24 16:00:00', '2026-12-24 17:30:00', null, null, ['title' => 'Christvesper']);

        $locations = array_column(EventSitemap::entries(), 'loc');

        $this->assertSame(
            [
                'https://beispiel.test/churchtools-termin/1/',
                'https://beispiel.test/churchtools-termin/2/',
            ],
            $locations
        );
    }

    /**
     * Der Weihnachtstermin im Dezember ist der eigentliche Grund für diese
     * Datei: Er steht in keiner Liste, die ein Crawler zu sehen bekommt, weil
     * er weit jenseits des ersten Zeitfensters liegt.
     */
    public function testAnEventFarBeyondTheFirstWindowIsIncluded(): void
    {
        $this->wpdb->seedEvent(7, '2027-06-01 10:00:00', '2027-06-01 12:00:00', null, null, ['title' => 'Gemeindefest']);

        $this->assertCount(1, EventSitemap::entries());
    }

    /**
     * Dieselbe Grenze wie überall im Frontend: Was nicht freigegeben ist,
     * verlässt diese Website nicht — auch nicht über eine Datei, die niemand
     * ansieht.
     */
    public function testEventsOfADisabledCalendarStayOut(): void
    {
        $this->wpdb->seedEvent(9, '2026-09-06 19:30:00', '2026-09-06 21:00:00', null, null, ['title' => 'Vorstandssitzung']);

        $this->assertSame([], EventSitemap::entries());
    }

    public function testPastEventsStayOut(): void
    {
        $this->wpdb->seedEvent(7, '2026-05-01 19:30:00', '2026-05-01 21:00:00', null, null, ['title' => 'Vorbei']);

        $this->assertSame([], EventSitemap::entries());
    }

    /**
     * „Nichts" als Klickverhalten heißt: Der Betreiber will keine
     * Terminseiten. Sie dann in einer Sitemap anzubieten, wäre eine
     * Entscheidung hinter seinem Rücken.
     */
    public function testTheClickBehaviorNoneEmptiesTheSitemap(): void
    {
        ctp_test_set_option('ctp_settings', [
            'click_behavior' => 'none',
            'calendars' => [7 => ['name' => 'Gottesdienste', 'enabled' => true]],
        ]);
        $this->wpdb->seedEvent(7, '2026-09-06 19:30:00', '2026-09-06 21:00:00', null, null, ['title' => 'Gottesdienst']);

        $this->assertSame([], EventSitemap::entries());
    }

    /**
     * <lastmod> beantwortet „hat sich seit meinem letzten Besuch etwas
     * geändert?" — also der Zeitpunkt des Abgleichs, nicht der des Termins.
     */
    public function testLastmodIsTheSyncTimeWithTimezoneOffset(): void
    {
        $this->wpdb->seedEvent(7, '2026-09-06 19:30:00', '2026-09-06 21:00:00', null, null, [
            'title' => 'Gottesdienst',
            'updated_at' => '2026-08-17 04:15:00',
        ]);

        $this->assertSame('2026-08-17T04:15:00+02:00', EventSitemap::entries()[0]['lastmod']);
    }

    public function testAnUnreadableSyncTimeIsLeftOutInsteadOfGuessed(): void
    {
        $this->wpdb->seedEvent(7, '2026-09-06 19:30:00', '2026-09-06 21:00:00', null, null, ['title' => 'Gottesdienst']);

        $this->assertSame('', EventSitemap::entries()[0]['lastmod']);
    }

    public function testTheXmlParsesAndCarriesEveryUrl(): void
    {
        $xml = EventSitemap::renderXml([
            ['loc' => 'https://beispiel.test/termine/gottesdienst-06-09-2026/', 'lastmod' => '2026-08-17T04:15:00+02:00'],
            ['loc' => 'https://beispiel.test/termine/fest-01-06-2027/', 'lastmod' => ''],
        ]);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'die Sitemap ist kein gültiges XML');

        $this->assertSame(2, $dom->getElementsByTagName('url')->length);
        $this->assertSame(1, $dom->getElementsByTagName('lastmod')->length, 'ohne Wert keine leere Angabe');
        $this->assertSame(
            'https://beispiel.test/termine/gottesdienst-06-09-2026/',
            $dom->getElementsByTagName('loc')->item(0)->textContent
        );
    }

    /**
     * Ein „&" in einer Adresse (etwa bei einfachen Permalinks, wo die Termine
     * über Query-Parameter laufen) beendet sonst das Dokument — und zwar
     * still: Die Datei liegt da, nur liest sie keine Suchmaschine mehr.
     */
    public function testAnAmpersandInAnAddressDoesNotBreakTheDocument(): void
    {
        $xml = EventSitemap::renderXml([
            ['loc' => 'https://beispiel.test/?ctp_event_slug=fest&page=2', 'lastmod' => ''],
        ]);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame(
            'https://beispiel.test/?ctp_event_slug=fest&page=2',
            $dom->getElementsByTagName('loc')->item(0)->textContent
        );
    }

    public function testTheAddressFallsBackToAQueryStringWithoutPrettyPermalinks(): void
    {
        $this->assertSame('https://beispiel.test/churchtools-termine-sitemap.xml', EventSitemap::url());

        ctp_test_set_option('permalink_structure', '');

        $this->assertSame('https://beispiel.test/?ctp_sitemap=1', EventSitemap::url());
    }

    /**
     * Der Eintrag in der robots.txt ist der Weg, auf dem eine Suchmaschine die
     * Datei überhaupt findet, ohne dass jemand sie irgendwo anmeldet.
     */
    public function testTheRobotsTxtAnnouncesTheSitemapOnPublicSitesOnly(): void
    {
        $this->assertStringContainsString(
            "\nSitemap: https://beispiel.test/churchtools-termine-sitemap.xml\n",
            EventSitemap::announceInRobotsTxt("User-agent: *\nDisallow:\n", 1)
        );

        $this->assertSame(
            "User-agent: *\nDisallow: /\n",
            EventSitemap::announceInRobotsTxt("User-agent: *\nDisallow: /\n", 0)
        );
    }
}
