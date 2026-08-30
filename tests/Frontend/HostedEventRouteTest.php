<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventDetailPage;
use PHPUnit\Framework\TestCase;

/**
 * Die Rewrite-Regel der Elternseite (`^termine/([^/]+)/?$`) steht mit `top` vor
 * den eigenen Regeln von WordPress — sie muss dort stehen, denn die generische
 * Seitenregel darunter fängt ohnehin alles. Damit fängt sie aber auch, was gar
 * kein Termin ist: eine echte Unterseite `/termine/anmeldung/`, die zweite
 * Seite eines langen Inhalts `/termine/2/`.
 *
 * Das ist der gefährlichste Teil dieser Funktion, weil sein Fehler leise ist:
 * Wer die Einstellung setzt, prüft danach seine Termine — nicht seine
 * Unterseiten. Die wären wortlos zu 404 geworden.
 */
final class HostedEventRouteTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        $GLOBALS['ctp_test_posts'] = [];

        ctp_test_set_post(43, 'page', 'publish', 'termine');
        ctp_test_set_option('ctp_settings', ['detail_page_id' => 43]);
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
        $GLOBALS['ctp_test_posts'] = [];
    }

    /**
     * Eine echte Unterseite geht als Seitenpfad an WordPress zurück — genau
     * das, was dessen eigene Regel daraus gemacht hätte.
     */
    public function testARealSubpageIsHandedBackToWordPress(): void
    {
        $this->assertSame(
            ['pagename' => 'termine/anmeldung'],
            EventDetailPage::resolveHostedRequest(['page_id' => 43, 'ctp_event_slug' => 'anmeldung'])
        );
    }

    /**
     * Eine reine Zahl ist die Seitennummer eines langen Seiteninhalts
     * (`<!--nextpage-->`), nicht ein Termin.
     */
    public function testAPageNumberStaysAPageNumber(): void
    {
        $this->assertSame(
            ['pagename' => 'termine', 'page' => '2'],
            EventDetailPage::resolveHostedRequest(['page_id' => 43, 'ctp_event_slug' => '2'])
        );
    }

    /**
     * @dataProvider notOursProvider
     */
    public function testAnythingWithoutOurDateSuffixIsNotOurs(string $slug): void
    {
        $result = EventDetailPage::resolveHostedRequest(['page_id' => 43, 'ctp_event_slug' => $slug]);

        $this->assertArrayNotHasKey('ctp_event_slug', $result, "„{$slug}\" ist keine unserer Adressen.");
    }

    public function notOursProvider(): array
    {
        return [
            ['anmeldung'],
            ['archiv-2025'],
            ['kontakt-formular'],
            // Sieht fast aus wie unsere Form, ist es aber nicht: einstellig.
            ['sommerfest-1-8-2026'],
        ];
    }

    /**
     * Der Platzhalter, der auf der Elternseite an die Stelle ihres Inhalts
     * tritt, ist die Innenseite dieser Route und keine öffentliche
     * Schnittstelle. Landet er versehentlich in einer gewöhnlichen Seite —
     * kopiert, aus einer Revision zurückgeholt —, gibt er nichts aus.
     */
    public function testTheContentPlaceholderRendersNothingOutsideAnEventRequest(): void
    {
        $this->assertSame('', EventDetailPage::renderHostedContent());
    }

    /**
     * Ohne eingestellte Elternseite hat dieser Filter nichts zu entscheiden —
     * dann greift die Regel gar nicht erst.
     */
    public function testWithoutAHostPageNothingIsTouched(): void
    {
        ctp_test_set_option('ctp_settings', ['detail_page_id' => 0]);

        $vars = ['page_id' => 43, 'ctp_event_slug' => 'anmeldung'];

        $this->assertSame($vars, EventDetailPage::resolveHostedRequest($vars));
    }

    public function testRequestsWithoutOurQueryVarPassThroughUntouched(): void
    {
        $vars = ['pagename' => 'ueber-uns'];

        $this->assertSame($vars, EventDetailPage::resolveHostedRequest($vars));
    }

    /**
     * Die Startseite als Elternseite ergäbe die Regel `^([^/]+)/?$` — mit
     * `top` davor läge sie über *jeder* Adresse der obersten Ebene. Deshalb
     * gilt sie als „keine Elternseite gesetzt".
     */
    public function testTheFrontPageIsNeverAHostPage(): void
    {
        ctp_test_set_option('page_on_front', 43);

        $vars = ['page_id' => 43, 'ctp_event_slug' => 'anmeldung'];

        $this->assertSame($vars, EventDetailPage::resolveHostedRequest($vars));
    }

    public function testThePostsPageIsNeverAHostPageEither(): void
    {
        ctp_test_set_option('page_for_posts', 43);

        $vars = ['page_id' => 43, 'ctp_event_slug' => 'anmeldung'];

        $this->assertSame($vars, EventDetailPage::resolveHostedRequest($vars));
    }
}
