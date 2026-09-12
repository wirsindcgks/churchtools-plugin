<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\Ics;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5545 ist ein Format, das nachsichtig *aussieht* und es nicht ist: Eine
 * Datei mit falschen Zeilenenden, einem unmaskierten Semikolon oder einem um
 * einen Tag verschobenen Ende wird von manchen Kalendern klaglos eingelesen und
 * von anderen abgelehnt — und wo sie durchkommt, steht der Termin falsch drin,
 * ohne dass irgendwo ein Fehler auftaucht. Deshalb sind die vier Stellen, an
 * denen das erfahrungsgemäß schiefgeht, hier einzeln behauptet.
 */
final class IcsTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_current_time('2026-09-07 12:00:00');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
    }

    public function testTheFileHasTheFrameEveryCalendarLooksFor(): void
    {
        $ics = Ics::forEvent($this->event());

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("VERSION:2.0\r\n", $ics);
        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        $this->assertStringContainsString("END:VEVENT\r\n", $ics);
    }

    /**
     * Falle 1: CRLF. Ein blosser Zeilenvorschub ist laut Format falsch, und
     * Outlook nimmt eine solche Datei teilweise gar nicht erst an.
     */
    public function testEveryLineEndsWithCarriageReturnAndNewline(): void
    {
        $ics = Ics::forEvent($this->event());

        $this->assertSame(
            substr_count($ics, "\n"),
            substr_count($ics, "\r\n"),
            'Es gibt Zeilenvorschübe ohne vorangehenden Wagenrücklauf.'
        );
    }

    /**
     * Falle 2: Maskierung. Semikolon, Komma und Backslash trennen im Format
     * selbst — unmaskiert zerlegen sie den Wert, in den sie geraten.
     */
    public function testSeparatorsInsideTextAreEscaped(): void
    {
        $event = $this->event();
        $event['title'] = 'Konzert; Chor, Orchester \\ Orgel';
        $event['location'] = 'Halle 1, Eingang B';

        $ics = Ics::forEvent($event);

        $this->assertStringContainsString('SUMMARY:Konzert\; Chor\\, Orchester \\\\ Orgel', $ics);
        $this->assertStringContainsString('LOCATION:Halle 1\\, Eingang B', $ics);
    }

    /**
     * Zeilenumbrueche im Text werden zu `\n` — als zwei Zeichen, nicht als
     * echter Umbruch. Ein echter beendete die Eigenschaft.
     */
    public function testLineBreaksInsideTheDescriptionBecomeEscapedSequences(): void
    {
        $event = $this->event();
        $event['description'] = "Erste Zeile\nZweite Zeile";
        $event['subtitle'] = '';

        $ics = Ics::forEvent($event);

        $this->assertStringContainsString('DESCRIPTION:Erste Zeile\\nZweite Zeile', $ics);
    }

    /**
     * Falle 3: Die Faltung zählt Oktetts, darf aber nur zwischen Zeichen
     * brechen. Ein Schnitt mitten durch ein UTF-8-Zeichen ergäbe zwei kaputte
     * Hälften — und deutsche Termintitel sind voller Umlaute.
     */
    public function testLongLinesFoldWithoutCuttingThroughAUmlaut(): void
    {
        $event = $this->event();
        $event['title'] = str_repeat('ä', 120);

        $ics = Ics::forEvent($event);

        foreach (explode("\r\n", $ics) as $zeile) {
            $this->assertLessThanOrEqual(75, strlen($zeile), "Zeile länger als 75 Oktetts: {$zeile}");
        }

        // Jede Zeile für sich muss gueltiges UTF-8 sein - genau das wäre sie
        // nicht, wenn die Faltung mitten in ein Zeichen geschnitten hätte.
        foreach (explode("\r\n", $ics) as $zeile) {
            $this->assertTrue(mb_check_encoding($zeile, 'UTF-8'), 'Faltung hat ein Zeichen zerschnitten.');
        }

        // Und zusammengesetzt muss der Titel wieder vollständig da sein.
        $entfaltet = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString('SUMMARY:' . str_repeat('ä', 120), $entfaltet);
    }

    /**
     * Falle 4, die teuerste: Bei ganztägigen Terminen ist DTEND *exklusiv* —
     * es benennt den ersten Tag, der nicht mehr dazugehört. Das gespeicherte
     * end_date ist dagegen inklusiv (an den echten Daten geprüft:
     * „Frauenwochenende" steht als 23.10. bis 25.10. und meint drei Tage).
     * Ohne die Verschiebung erschiene jeder ganztägige Termin im Kalender
     * einen Tag zu kurz.
     */
    public function testAnAllDayEventEndsOnTheDayAfterItsLastOne(): void
    {
        $event = $this->event();
        $event['all_day'] = 1;
        $event['start_date'] = '2026-10-23 02:00:00';
        $event['end_date'] = '2026-10-25 02:00:00';

        $ics = Ics::forEvent($event);

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261023', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261026', $ics);
        $this->assertStringNotContainsString('DTSTART;VALUE=DATE:20261023T', $ics, 'Ganztaegig traegt keine Uhrzeit.');
    }

    /**
     * Der eintaegige Fall derselben Regel — der, bei dem der Fehler am
     * haeufigsten sichtbar wird: Der Termin verschwände ganz.
     */
    public function testASingleAllDayEventStillSpansOneDay(): void
    {
        $event = $this->event();
        $event['all_day'] = 1;
        $event['start_date'] = '2026-12-27 01:00:00';
        $event['end_date'] = '2026-12-27 01:00:00';

        $ics = Ics::forEvent($event);

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261227', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261228', $ics);
    }

    /**
     * Zeiten in UTC, damit sie ohne einen eigenen VTIMEZONE-Block eindeutig
     * sind. Die Testumgebung steht auf Europe/Berlin, im September also
     * Sommerzeit: 10:30 Ortszeit sind 08:30 UTC.
     */
    public function testTimesAreWrittenInUtc(): void
    {
        $ics = Ics::forEvent($this->event());

        $this->assertStringContainsString('DTSTART:20260906T083000Z', $ics);
        $this->assertStringContainsString('DTEND:20260906T100000Z', $ics);
    }

    /**
     * Die Zusage, um die es beim doppelten Herunterladen geht: Dieselbe
     * Veranstaltung trägt dieselbe Kennung — auch dann, wenn die lokale `id`
     * eine andere ist, denn die vergibt ein Vollsync neu.
     */
    public function testTheSameEventKeepsItsIdentityAcrossDownloads(): void
    {
        $ersteDatei = Ics::forEvent($this->event());
        $spaeter = $this->event();
        $spaeter['id'] = 4711;
        $spaeter['updated_at'] = '2026-09-07 09:00:00';
        $zweiteDatei = Ics::forEvent($spaeter);

        preg_match('/^UID:(.+)$/m', $ersteDatei, $a);
        preg_match('/^UID:(.+)$/m', $zweiteDatei, $b);

        $this->assertNotEmpty($a);
        $this->assertSame(trim($a[1]), trim($b[1]), 'Gleiche Veranstaltung, verschiedene Kennung.');
    }

    /**
     * Und die zweite Hälfte davon: Nach einer Aenderung muss die SEQUENCE
     * größer sein, sonst behandelt der Kalender den zweiten Import als
     * veraltet und ignoriert ihn.
     */
    public function testAChangedEventCarriesAHigherSequence(): void
    {
        $alt = $this->event();
        $neu = $this->event();
        $neu['updated_at'] = '2026-09-07 09:00:00';

        $this->assertGreaterThan(
            $this->sequence(Ics::forEvent($alt)),
            $this->sequence(Ics::forEvent($neu))
        );
    }

    /**
     * Adressen sind URI-Werte und werden *nicht* wie Text maskiert: Ein Komma
     * darin ist ein Komma. Durch escapeText() geschickt wäre jede Adresse mit
     * Trennzeichen zerlegt.
     */
    public function testUrlsAreNotTextEscaped(): void
    {
        $event = $this->event();
        $event['detail_url'] = 'https://example.test/termine/a,b;c/';

        $ics = Ics::forEvent($event);
        $entfaltet = str_replace("\r\n ", '', $ics);

        $this->assertStringContainsString('URL:https://example.test/termine/a,b;c/', $entfaltet);
    }

    public function testMetadataIsCarriedAlong(): void
    {
        $entfaltet = str_replace("\r\n ", '', Ics::forEvent($this->event()));

        $this->assertStringContainsString('CATEGORIES:Gottesdienste', $entfaltet);
        $this->assertStringContainsString('IMAGE;VALUE=URI;DISPLAY=BADGE:https://example.test/flyer.jpg', $entfaltet);
        $this->assertStringContainsString('ATTACH;FMTTYPE=image/jpeg:https://example.test/flyer.jpg', $entfaltet);
        $this->assertStringContainsString('STATUS:CONFIRMED', $entfaltet);
        $this->assertStringContainsString('TRANSP:OPAQUE', $entfaltet);
        // Untertitel und Beschreibung teilen sich ein Feld, iCalendar kennt
        // keinen Untertitel.
        $this->assertStringContainsString('DESCRIPTION:mit Kinderprogramm', $entfaltet);
    }

    /**
     * Der gespeicherte Beschreibungstext darf HTML enthalten - im Kalender hat
     * Markup nichts zu suchen.
     */
    public function testHtmlIsStrippedFromTheDescription(): void
    {
        $event = $this->event();
        $event['subtitle'] = '';
        $event['description'] = '<p>Mit <strong>Abendmahl</strong> &amp; Kaffee</p>';

        $entfaltet = str_replace("\r\n ", '', Ics::forEvent($event));

        $this->assertStringContainsString('Mit Abendmahl & Kaffee', $entfaltet);
        $this->assertStringNotContainsString('<strong>', $entfaltet);
        $this->assertStringNotContainsString('&amp;', $entfaltet);
    }

    public function testTheFilenameFollowsTheEventSlug(): void
    {
        $this->assertSame('gottesdienst-06-09-2026.ics', Ics::filename($this->event()));
    }

    /**
     * Die Serienfassung: ein VCALENDAR, so viele VEVENTs wie Termine. Der
     * Rahmen darf dabei nicht mitwachsen — eine Datei mit drei BEGIN:VCALENDAR
     * ist kein Kalender mehr, sondern drei aneinandergeklebte.
     */
    public function testTheWholeSeriesIsOneCalendarWithOneEntryPerDate(): void
    {
        $ics = Ics::forEvents($this->series(3));

        $this->assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'));
        $this->assertSame(1, substr_count($ics, 'END:VCALENDAR'));
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(3, substr_count($ics, 'END:VEVENT'));
    }

    /**
     * Jeder Termin behält seine eigene Kennung. Das ist die Zusage, an der das
     * zweite Herunterladen hängt: Ohne sie überschriebe der Kalender die drei
     * Einträge gegenseitig und behielte einen.
     */
    public function testEveryDateInTheSeriesKeepsItsOwnIdentity(): void
    {
        preg_match_all('/^UID:(.+?)\r?$/m', Ics::forEvents($this->series(3)), $treffer);

        $this->assertCount(3, $treffer[1]);
        $this->assertCount(3, array_unique($treffer[1]), 'Zwei Termine der Serie teilen sich eine UID.');
    }

    /**
     * Kein RRULE, obwohl hier eine Serie beisammen liegt: ChurchTools liefert
     * kein Wiederholungsmuster, und aus den Abständen eines zu erschließen wäre
     * Raten. Ausgeschriebene Einzeltermine sind die ehrliche Fassung — dass
     * das so bleibt, steht hier.
     */
    public function testTheSeriesIsWrittenOutRatherThanGuessedAsARule(): void
    {
        $this->assertStringNotContainsString('RRULE', Ics::forEvents($this->series(3)));
    }

    /**
     * Der Dateiname der Serie trägt kein Datum. Er benennt nicht *einen*
     * Termin, und ein Datum darin wäre die Behauptung, er täte es.
     */
    public function testTheSeriesFilenameCarriesNoSingleDate(): void
    {
        $name = Ics::seriesFilename($this->event());

        $this->assertSame('gottesdienst-serie.ics', $name);
        $this->assertStringNotContainsString('06-09-2026', $name);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function series(int $anzahl): array
    {
        $termine = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $termin = $this->event();
            $tag = str_pad((string) (6 + $i * 7), 2, '0', STR_PAD_LEFT);
            $termin['start_date'] = "2026-09-{$tag} 10:30:00";
            $termin['end_date'] = "2026-09-{$tag} 12:00:00";
            $termine[] = $termin;
        }

        return $termine;
    }

    private function sequence(string $ics): int
    {
        // \r? vor dem Anker: Bei /m steht $ vor dem \n, das \r davor gehört
        // aber noch zur Zeile - ohne das findet die Suche nichts. Genau die
        // CRLF-Feinheit, um die es in dieser Datei geht, hier in der Testhilfe.
        preg_match('/^SEQUENCE:(\\d+)\\r?$/m', $ics, $treffer);

        return (int) ($treffer[1] ?? -1);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * „Saal 1" ist für einen Kalender auf dem Handy keine Adresse, aus der er
     * eine Route bauen kann. Steht der Raum im Haus der Gemeinde, kommt deren
     * Anschrift dahinter - und die Koordinaten als eigenes Feld, damit die
     * Karten-App nicht raten muss.
     */
    public function testARoomInTheChurchBuildingCarriesTheAddressAndCoordinates(): void
    {
        ctp_test_set_option('ctp_church_address', [
            'name' => 'GEMEINDEHAUS',
            'street' => 'Hauptstraße 1',
            'zip' => '75015',
            'city' => 'Bretten',
            'district' => 'Ruit',
            'country' => 'DE',
            'latitude' => '49.0368',
            'longitude' => '8.7057',
        ]);

        $ics = Ics::forEvent(array_replace($this->event(), [
            'location' => 'Saal 1',
            'location_at_church' => 1,
        ]));

        // Das Komma ist in einer iCal-Textangabe ein Trennzeichen und muss
        // maskiert sein, sonst liest der Kalender drei Werte statt einer
        // Adresse.
        $this->assertStringContainsString("LOCATION:Saal 1\\, Hauptstraße 1\\, 75015 Bretten-Ruit\r\n", $ics);
        $this->assertStringContainsString("GEO:49.0368;8.7057\r\n", $ics);
    }

    /**
     * Ohne den Merker bleibt die Zeile, wie sie ist: Eine Adresse vom Termin
     * sagt selbst, wo sie liegt, und die Anschrift der Gemeinde hätte dort
     * nichts zu suchen.
     */
    public function testAnAddressLineStaysUntouched(): void
    {
        ctp_test_set_option('ctp_church_address', ['name' => 'GEMEINDEHAUS', 'street' => 'Hauptstraße 1']);

        $ics = Ics::forEvent(array_replace($this->event(), ['location' => 'Freibad, Badstraße 1']));

        $this->assertStringContainsString("LOCATION:Freibad\\, Badstraße 1\r\n", $ics);
        $this->assertStringNotContainsString('GEO:', $ics);
    }

    /**
     * Beim auswärtigen Termin steht die Adresse schon in der Zeile - was
     * fehlt, sind die Koordinaten. Sie sind hier das Wertvollste: Eine
     * Karten-App findet damit auch ein Freibad ohne Hausnummer.
     */
    public function testAnEventWithItsOwnAddressCarriesItsCoordinates(): void
    {
        $ics = Ics::forEvent(array_replace($this->event(), [
            'location' => 'Freibad, Badstraße 1, 75015 Bretten',
            'location_data' => json_encode(['name' => 'Freibad', 'street' => 'Badstraße 1', 'latitude' => '49.1111', 'longitude' => '8.2222']),
        ]));

        $this->assertStringContainsString("LOCATION:Freibad\\, Badstraße 1\\, 75015 Bretten\r\n", $ics);
        $this->assertStringContainsString("GEO:49.1111;8.2222\r\n", $ics);
    }

    /**
     * Die Anschrift der Gemeinde kommt nur an eine Raumzeile - an einer
     * eigenen Adresse wäre sie schlicht falsch, und die Adresse steht dort
     * ohnehin schon.
     */
    public function testTheChurchAddressIsNotAppendedToAnOwnAddress(): void
    {
        ctp_test_set_option('ctp_church_address', ['name' => 'GEMEINDEHAUS', 'street' => 'Hauptstraße 1', 'zip' => '75015', 'city' => 'Bretten']);

        $ics = Ics::forEvent(array_replace($this->event(), [
            'location' => 'Freibad, Badstraße 1, 75015 Bretten',
            'location_data' => json_encode(['name' => 'Freibad', 'street' => 'Badstraße 1', 'latitude' => '49.1111', 'longitude' => '8.2222']),
        ]));

        $this->assertStringNotContainsString('Hauptstraße 1', $ics);
    }

    private function event(): array
    {
        return [
            'id' => 12,
            'ct_event_id' => 900,
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'subtitle' => 'mit Kinderprogramm',
            'description' => 'Herzliche Einladung.',
            'start_date' => '2026-09-06 10:30:00',
            'end_date' => '2026-09-06 12:00:00',
            'all_day' => 0,
            'location' => 'Gemeindehaus',
            'image_url' => 'https://example.test/flyer.jpg',
            'calendar_name' => 'Gottesdienste',
            'detail_url' => 'https://example.test/termine/gottesdienst-06-09-2026/',
            'created_at' => '2026-08-01 08:00:00',
            'updated_at' => '2026-09-01 08:00:00',
        ];
    }
}
