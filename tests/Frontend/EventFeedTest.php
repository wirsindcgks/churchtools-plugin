<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventFeed;
use ChurchToolsPlugin\Frontend\Ics;
use PHPUnit\Framework\TestCase;

/**
 * Der Abo-Feed ist die eine Ausgabe, die niemand ansieht: Sie landet im
 * Kalender des Besuchers und wird dort stündlich abgeholt. Ein falscher
 * Kopf oder eine Adresse, die kein Kalender als Abonnement erkennt, fällt
 * deshalb nur auf, wenn ein Test danach fragt — oder Wochen später, wenn
 * jemand fragt, warum die Termine nicht ankommen.
 */
final class EventFeedTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_option('permalink_structure', '/%postname%/');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    /**
     * `webcal://` ist kein eigenes Protokoll, sondern die Verabredung, an der
     * iOS, macOS, Outlook und Thunderbird ein Abonnement erkennen. Mit
     * `https://` öffnen sie dieselbe Datei *einmalig* — genau das, was der
     * Importieren-Knopf schon tut, und damit wäre der Knopf sinnlos.
     */
    public function testTheSubscriptionUrlUsesTheWebcalScheme(): void
    {
        $this->assertStringStartsWith('webcal://', EventFeed::webcalUrl());
        $this->assertStringEndsWith('/churchtools-termine.ics', EventFeed::webcalUrl());
    }

    /**
     * Dieselbe Adresse als `https` — zum Kopieren für Android und Google
     * Kalender, die `webcal://` nicht kennen.
     */
    public function testTheSameAddressIsAvailableOverHttps(): void
    {
        $this->assertStringStartsWith('http', EventFeed::url());
        $this->assertStringNotContainsString('webcal', EventFeed::url());
    }

    /**
     * Abonniert wird der Kalender, von dem aus jemand klickt — wer von einem
     * Gottesdienst aus abonniert, will nicht jede Probe dazu.
     */
    public function testACalendarNameTravelsInTheAddress(): void
    {
        $url = EventFeed::url('Gottesdienst');

        $this->assertStringContainsString('kalender=Gottesdienst', $url);
    }

    /**
     * Ohne sprechende Permalinks greift keine Rewrite-Regel — dann muss die
     * Adresse die Query-String-Fassung sein, sonst antwortet der Feed mit der
     * Startseite (dieselbe Rückfallebene wie bei der Termin-Sitemap).
     */
    public function testWithoutPrettyPermalinksTheQueryFormIsUsed(): void
    {
        ctp_test_set_option('permalink_structure', '');

        $this->assertStringContainsString('ctp_feed=1', EventFeed::url());
        $this->assertStringNotContainsString('churchtools-termine.ics', EventFeed::url());
    }

    /**
     * Die Gegenrichtung zur Adressbildung: was aus der Adresse wieder
     * herausgelesen wird. Getrennt geprüft, weil genau hier der erste Anlauf
     * gescheitert ist — die Auswahl ging als *Zeichenkette* an
     * SettingsPage::resolveCalendarIds(), das ein Array erwartet. Die Adresse
     * war korrekt gebildet, der Aufruf antwortete trotzdem mit HTTP 500, und
     * kein Test hat es gemerkt.
     */
    public function testTheCalendarSelectionIsSplitBeforeItIsResolved(): void
    {
        ctp_test_set_option('ctp_settings', ['calendars' => [
            32 => ['name' => 'Gottesdienst', 'enabled' => true],
            41 => ['name' => 'Konzerte', 'enabled' => true],
        ]]);

        $_GET['kalender'] = 'Gottesdienst, 41';

        $this->assertSame([32, 41], $this->requestedCalendars());

        unset($_GET['kalender']);
    }

    /**
     * Ohne Angabe alles — dieselbe Bedeutung, die ein Shortcode ohne
     * `calendar` hat.
     */
    public function testWithoutASelectionNothingIsFilteredOut(): void
    {
        unset($_GET['kalender']);

        $this->assertSame([], $this->requestedCalendars());
    }

    /**
     * @return array<int, int>
     */
    private function requestedCalendars(): array
    {
        $method = new \ReflectionMethod(EventFeed::class, 'requestedCalendars');

        return $method->invoke(null);
    }

    /**
     * Die drei Kopfzeilen, an denen ein Kalender ein Abonnement von einem
     * einmaligen Download unterscheidet: Name in der Liste des Besuchers und
     * zweimal dieselbe Aussage zur Abholfrist (RFC 7986 und die ältere,
     * verbreitetere Schreibweise).
     */
    public function testTheFeedCarriesTheSubscriptionHeaders(): void
    {
        $ics = Ics::forFeed([], 'Musterkirche');

        $this->assertStringContainsString("REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n", $ics);
        $this->assertStringContainsString("X-PUBLISHED-TTL:PT1H\r\n", $ics);
        $this->assertStringContainsString("X-WR-CALNAME:Musterkirche\r\n", $ics);
    }

    /**
     * Der Download bleibt, was er war: Diese Kopfzeilen haben dort nichts zu
     * suchen, sie versprächen eine Aktualisierung, die eine heruntergeladene
     * Datei nie leisten kann.
     */
    public function testTheDownloadStaysWithoutSubscriptionHeaders(): void
    {
        $ics = Ics::forEvents([]);

        $this->assertStringNotContainsString('REFRESH-INTERVAL', $ics);
        $this->assertStringNotContainsString('X-PUBLISHED-TTL', $ics);
        $this->assertStringNotContainsString('X-WR-CALNAME', $ics);
    }

    /**
     * Ein leerer Name darf keine leere Kopfzeile erzeugen — die wäre nach RFC
     * 5545 eine Eigenschaft ohne Wert, und manche Kalender lehnen die Datei
     * dann ab.
     */
    public function testAnEmptyNameLeavesTheHeaderOut(): void
    {
        $this->assertStringNotContainsString('X-WR-CALNAME', Ics::forFeed([], '   '));
    }
}
