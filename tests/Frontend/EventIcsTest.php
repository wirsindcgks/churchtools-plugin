<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventIcs;
use PHPUnit\Framework\TestCase;

/**
 * Die Adresse, unter der die Kalenderdatei liegt, und der Zeitpunkt, zu dem sie
 * ausgegeben wird — zwei Zusagen, die man einer laufenden Seite nicht ansieht.
 *
 * Sie hängt bewusst an der Adresse des Termins statt an einer eigenen Route
 * (siehe EventIcs). Damit erbt sie alles, was an jener schon richtig ist:
 * sprechende Permalinks, Elternseite, der Rückfall auf die Query-String-Form.
 * Genau das ist hier behauptet — sonst wäre es beim nächsten Umbau der
 * Terminadressen still eine zweite Wahrheit.
 */
final class EventIcsTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_hooks();
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    /**
     * Priorität 9 ist keine Feinheit, sondern trägt die ganze Route: Bei 10
     * hängen `redirect_canonical` *und* EventDetailPage::maybeRenderDetail().
     * Das zweite ist das gefährlichere — mit gesetzter Elternseite schickt es
     * die ID-Adresse per 301 auf die sprechende Fassung und verliert dabei den
     * Query-Parameter, sodass statt der Datei die Seite käme.
     */
    public function testTheFileIsWrittenBeforeWordPressCanRedirectOrRenderThePage(): void
    {
        EventIcs::registerHooks();

        $prioritaet = ctp_test_hook_priority('template_redirect', [EventIcs::class, 'maybeRenderIcs']);

        $this->assertNotNull($prioritaet, 'Die Ausgabe hängt gar nicht an template_redirect.');
        $this->assertLessThan(10, $prioritaet);
    }

    public function testTheQueryVariableIsRegistered(): void
    {
        $this->assertContains(EventIcs::QUERY_VAR, EventIcs::addQueryVar([]));
    }

    /**
     * Mit sprechenden Permalinks und Elternseite hängt der Parameter an der
     * sprechenden Adresse — nicht an einer eigenen.
     */
    public function testTheUrlIsTheEventsOwnAddressPlusOneParameter(): void
    {
        ctp_test_set_option('permalink_structure', '/%postname%/');
        ctp_test_set_post(43, 'page', 'publish');
        ctp_test_set_option('ctp_settings', ['detail_page_id' => 43, 'calendars' => []]);

        $url = EventIcs::urlForEvent($this->event());

        $this->assertStringContainsString('gottesdienst-06-09-2026', $url);
        $this->assertStringContainsString(EventIcs::QUERY_VAR . '=1', $url);
    }

    /**
     * Ohne sprechende Permalinks greift keine Rewrite-Regel, und die
     * Terminadresse ist selbst schon eine Query-String-Adresse. Der Parameter
     * muss dann *zusaetzlich* daran hängen und sie nicht ersetzen — sonst
     * zeigte die Datei auf die Startseite.
     */
    public function testWithoutPrettyPermalinksTheParameterIsAppendedNotSubstituted(): void
    {
        ctp_test_set_option('permalink_structure', '');
        ctp_test_set_option('ctp_settings', ['detail_page_id' => 0, 'calendars' => []]);

        $url = EventIcs::urlForEvent($this->event());

        $this->assertStringContainsString('ctp_event=12', $url);
        $this->assertStringContainsString(EventIcs::QUERY_VAR . '=1', $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(): array
    {
        return [
            'id' => 12,
            'ct_event_id' => 900,
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'start_date' => '2026-09-06 10:30:00',
            'end_date' => '2026-09-06 12:00:00',
            'all_day' => 0,
        ];
    }
}
