<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventDetailPage;
use ChurchToolsPlugin\Frontend\EventFeed;
use ChurchToolsPlugin\Frontend\EventSitemap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Drei Klassen melden eigene Adressen an, und der Regelsatz wird nur dann neu
 * geschrieben, wenn `EventDetailPage::REWRITE_VERSION` sich ändert (dort steht
 * die Begründung). Das ist die Falle: Eine neue Route ohne Hochzählen
 * funktioniert auf der eigenen Testinstallation tadellos — dort wurde der
 * Regelsatz beim Einrichten ohnehin geschrieben — und antwortet auf jeder
 * bestehenden Seite mit 404, bis jemand die Permalinks von Hand speichert.
 * Auffallen würde das erst, wenn ein Besucher den Knopf drückt.
 *
 * Deshalb hängt hier eine Zahl an der Zusage: Wer eine Regel hinzufügt, muss
 * diesen Test anfassen — und das ist genau der Moment, in dem die Erinnerung
 * ans Hochzählen ankommt.
 */
final class RewriteRulesTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_rewrite_rules();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_rewrite_rules();
    }

    public function testThePluginRegistersExactlyTheKnownRoutes(): void
    {
        EventSitemap::registerRewriteRule();
        EventFeed::registerRewriteRule();
        EventDetailPage::registerRewriteRule();

        $regexes = array_column(ctp_test_rewrite_rules(), 'regex');

        $this->assertCount(
            3,
            $regexes,
            'Neue Route? Dann auch EventDetailPage::REWRITE_VERSION hochzählen, sonst 404 auf Bestandsseiten.'
        );
        $this->assertContains('^churchtools\-termine\-sitemap\.xml$', $regexes);
        $this->assertContains('^churchtools\-termine\.ics$', $regexes);
    }

    /**
     * Die Gegenprobe zur Zahl oben: Der Stempel, gegen den verglichen wird,
     * muss den aktuellen Stand tragen. „4" ist der Abo-Feed; wäre er beim
     * Hinzufügen der Route auf „3" stehen geblieben, hätte sich am
     * gespeicherten Wert nichts geändert und der Regelsatz wäre nie neu
     * geschrieben worden.
     */
    public function testTheRewriteVersionCountsUpWithEveryNewRoute(): void
    {
        $version = (new ReflectionClass(EventDetailPage::class))->getConstant('REWRITE_VERSION');

        $this->assertGreaterThanOrEqual(
            4,
            (int) $version,
            'Der Abo-Feed ist Version 4 des Regelsatzes.'
        );
    }

    /**
     * Beide eigenen Adressen stehen mit `top` im Regelsatz. Ohne das fängt die
     * allgemeine Seitenregel von WordPress sie vorher ab, und statt der Datei
     * käme eine Seite oder ein 404 — dieselbe Falle, die schon die
     * Termin-Sitemap getroffen hat.
     */
    public function testTheOwnFilesAreRegisteredAheadOfWordPressOwnRules(): void
    {
        EventSitemap::registerRewriteRule();
        EventFeed::registerRewriteRule();

        foreach (ctp_test_rewrite_rules() as $rule) {
            $this->assertSame('top', $rule['after'], $rule['regex']);
        }
    }
}
