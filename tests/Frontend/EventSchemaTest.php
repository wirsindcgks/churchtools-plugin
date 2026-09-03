<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventSchema;
use PHPUnit\Framework\TestCase;

/**
 * Die strukturierten Daten sind der einzige Teil der Ausgabe, den niemand
 * ansieht: Sie stehen in der Seite, wirken aber erst Wochen später in einer
 * Trefferliste, die man selbst nie zu Gesicht bekommt. Ein falscher
 * Zeitzonenversatz oder ein „T00:00" an einem ganztägigen Termin fällt hier
 * also nur auf, wenn ein Test danach fragt.
 */
final class EventSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        EventSchema::reset();
        ctp_test_set_option('blogname', 'Musterkirche');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
        EventSchema::reset();
    }

    /**
     * Der Punkt, an dem eine Terminangabe still falsch wird: Ohne Versatz
     * liest jeder Empfänger die Zeit in seiner eigenen Zeitzone — im Sommer
     * eine Stunde daneben, im Winter zwei.
     */
    public function testTheStartCarriesTheSitesTimezoneOffset(): void
    {
        $data = EventSchema::forEvent($this->event());

        $this->assertSame('2026-09-06T19:30:00+02:00', $data['startDate']);
        $this->assertSame('2026-09-06T21:00:00+02:00', $data['endDate']);
    }

    /**
     * Ganztägig heißt: Der Zeitanteil bedeutet nichts. Die Ansichten zeigen
     * ihn deshalb nicht (EventFormatter::timeRange()), und hier steht er
     * ebenso wenig — „00:00" wäre eine Uhrzeit, die niemand gemeint hat.
     */
    public function testAnAllDayEventCarriesDatesWithoutATime(): void
    {
        $data = EventSchema::forEvent($this->event(['all_day' => 1]));

        $this->assertSame('2026-09-06', $data['startDate']);
        $this->assertArrayNotHasKey('endDate', $data, 'Anfang und Ende sind derselbe Tag');
    }

    public function testTheLocationBecomesAPlace(): void
    {
        $data = EventSchema::forEvent($this->event());

        $this->assertSame(
            ['@type' => 'Place', 'name' => 'Gemeindehaus, Musterstraße 1', 'address' => 'Gemeindehaus, Musterstraße 1'],
            $data['location']
        );
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $data['eventAttendanceMode']);
    }

    public function testAnEventWithoutALocationHasNoPlaceAtAll(): void
    {
        $data = EventSchema::forEvent($this->event(['location' => '']));

        $this->assertArrayNotHasKey('location', $data);
        $this->assertArrayNotHasKey('eventAttendanceMode', $data, 'ohne Ort ist nichts über die Form des Termins bekannt');
    }

    /**
     * Die Klickart „Nichts" heißt: Dieser Termin hat auf dieser Website keine
     * eigene Adresse, die jemand aufrufen soll. Dann darf hier auch keine
     * stehen.
     */
    public function testAnUnlinkedEventCarriesNoUrl(): void
    {
        $this->assertArrayHasKey('url', EventSchema::forEvent($this->event(), true));
        $this->assertArrayNotHasKey('url', EventSchema::forEvent($this->event(), false));
    }

    /**
     * Die Beschreibung ist eine Zusammenfassung, kein zweiter Abdruck des
     * Textes: Ein Programmheft als Beschreibung würde die Seite sonst um ein
     * Vielfaches ihres sichtbaren Inhalts aufblähen, und zwar je Termin.
     */
    public function testTheDescriptionIsShortenedAndFreeOfMarkup(): void
    {
        $data = EventSchema::forEvent($this->event([
            'description' => '<p>Erste Zeile</p><p>' . str_repeat('Wort ', 200) . '</p>',
        ]));

        $this->assertStringStartsWith('Erste Zeile Wort', $data['description']);
        $this->assertStringNotContainsString('<', $data['description']);
        $this->assertStringEndsWith('…', $data['description'], 'gekürzt, und das sagt das Auslassungszeichen');
        $this->assertLessThan(400, mb_strlen($data['description']));
    }

    public function testTheSubtitleStandsInWhenThereIsNoDescription(): void
    {
        $data = EventSchema::forEvent($this->event(['description' => '', 'subtitle' => 'Mit Kindergottesdienst']));

        $this->assertSame('Mit Kindergottesdienst', $data['description']);
    }

    /**
     * Eine Zeile ohne brauchbares Datum darf die Seite nicht mit einem Fatal
     * beenden — sie fällt aus den strukturierten Daten heraus, mehr nicht.
     */
    public function testAnUnreadableDateDropsTheEventInsteadOfFailing(): void
    {
        $this->assertSame([], EventSchema::forEvent($this->event(['start_date' => 'kein Datum'])));
        $this->assertSame([], EventSchema::forEvent($this->event(['start_date' => '0000-00-00 00:00:00'])));
        $this->assertSame([], EventSchema::forEvent($this->event(['title' => '  '])));
    }

    /**
     * Der eine Weg, auf dem ausgerechnet ein Zusatz für Suchmaschinen zur
     * Sicherheitslücke würde: Ein Termintitel, der „</script>" enthält, dürfte
     * den Block nicht beenden und den Rest als Markup in die Seite bringen.
     */
    public function testATitleCannotCloseTheScriptBlock(): void
    {
        $script = EventSchema::detailScript($this->event([
            'title' => 'Konzert </script><img src=x onerror=alert(1)>',
        ]));

        $this->assertStringNotContainsString('</script><img', $script);
        $this->assertStringEndsWith('</script>', $script);
        $this->assertSame(1, substr_count($script, '</script>'));
    }

    public function testTheDetailBlockIsASingleEvent(): void
    {
        $data = $this->decode(EventSchema::detailScript($this->event()));

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Event', $data['@type']);
        $this->assertSame('Gottesdienst', $data['name']);
        $this->assertSame('Musterkirche', $data['organizer']['name']);
    }

    /**
     * In der Liste zählt die Reihenfolge: itemListElement nummeriert die
     * Termine so durch, wie sie auf der Seite stehen.
     */
    public function testTheListBlockNumbersTheEventsInOrder(): void
    {
        $events = [
            $this->event(['id' => 11, 'title' => 'Erster']),
            $this->event(['id' => 12, 'title' => 'Zweiter']),
        ];

        $data = $this->decode(EventSchema::listScript($events, 'popup'));

        $this->assertSame('ItemList', $data['@type']);
        $this->assertCount(2, $data['itemListElement']);
        $this->assertSame(1, $data['itemListElement'][0]['position']);
        $this->assertSame('Erster', $data['itemListElement'][0]['item']['name']);
        $this->assertSame(2, $data['itemListElement'][1]['position']);
        $this->assertSame('Zweiter', $data['itemListElement'][1]['item']['name']);
    }

    /**
     * Eine Liste, in der keine einzige Zeile brauchbar ist, gibt gar keinen
     * Block aus — ein leeres <script> wäre eine Behauptung ohne Inhalt.
     */
    public function testAnEmptyListRendersNothing(): void
    {
        $this->assertSame('', EventSchema::listScript([], 'page'));
        $this->assertSame('', EventSchema::listScript([$this->event(['title' => ''])], 'page'));
    }

    /**
     * Steht derselbe Termin zweimal auf einer Seite — „Nächster Termin" oben,
     * die vollständige Liste darunter ist das übliche Muster —, zählt der
     * erste Block. Zweimal dieselbe Veranstaltung mit derselben Adresse
     * anzumelden, wäre eine Angabe, die niemand so gemeint hat.
     */
    public function testAnEventAlreadyAnnouncedIsNotRepeatedInASecondList(): void
    {
        $teaser = $this->decode(EventSchema::listScript([$this->event(['id' => 77])], 'page'));
        $this->assertCount(1, $teaser['itemListElement']);

        $this->assertSame(
            '',
            EventSchema::listScript([$this->event(['id' => 77])], 'page'),
            'derselbe Termin, zweite Ansicht'
        );

        $second = $this->decode(EventSchema::listScript([
            $this->event(['id' => 77]),
            $this->event(['id' => 78, 'title' => 'Anderer']),
        ], 'page'));

        $this->assertCount(1, $second['itemListElement'], 'nur der noch nicht genannte Termin');
        $this->assertSame(1, $second['itemListElement'][0]['position'], 'die Nummerierung beginnt neu');
        $this->assertSame('Anderer', $second['itemListElement'][0]['item']['name']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'id' => 4021,
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'subtitle' => '',
            'description' => 'Gemeinsam feiern, mit Musik und Predigt.',
            'location' => 'Gemeindehaus, Musterstraße 1',
            'start_date' => '2026-09-06 19:30:00',
            'end_date' => '2026-09-06 21:00:00',
            'all_day' => 0,
            // Wie nach EventListRenderer::withCalendarMeta(): die Adresse aus
            // der Mediathek, nicht die von ChurchTools.
            'image_url' => 'https://beispiel.test/wp-content/uploads/flyer.webp',
            'detail_url' => 'https://beispiel.test/termine/gottesdienst-06-09-2026/',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $script): array
    {
        $json = (string) preg_replace('#^<script type="application/ld\+json">(.*)</script>$#s', '$1', $script);

        return (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
