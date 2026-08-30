<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventSlug;
use PHPUnit\Framework\TestCase;

/**
 * Die sprechende Adresse eines Termins wird gebaut *und* wieder gelesen — und
 * beide Richtungen müssen zueinander passen, sonst zeigt eine Adresse, die das
 * Plugin selbst in jede Kachel schreibt, auf eine 404. Genau darauf zielen die
 * Rundreise-Tests hier.
 *
 * Exakte Zeichenketten werden nur an ASCII-Titeln geprüft: Der
 * sanitize_title()-Ersatz der Testumgebung bildet Umlaute anders ab als
 * WordPress (und WordPress selbst je nach Sprache verschieden), siehe den
 * Kommentar am Stub in tests/bootstrap.php.
 */
final class EventSlugTest extends TestCase
{
    public function testBuildsTitleAndDate(): void
    {
        $this->assertSame(
            'gottesdienst-06-09-2026',
            EventSlug::forEvent(['title' => 'Gottesdienst', 'start_date' => '2026-09-06 10:30:00'])
        );
    }

    public function testDropsPunctuationTheWayWordPressDoes(): void
    {
        $this->assertSame(
            'kindergottesdienst-schatzinsel-06-09-2026',
            EventSlug::forEvent(['title' => 'Kindergottesdienst "Schatzinsel"', 'start_date' => '2026-09-06 10:30:00'])
        );
    }

    /**
     * Ein Titel, aus dem sanitize_title() nichts übrig lässt, ergäbe einen
     * Slug, der nur aus dem Datum besteht — und den könnte parse() nicht mehr
     * als Titel plus Datum lesen. Der Ersatzname hält die Form.
     */
    public function testATitleThatSanitizesToNothingStillYieldsAReadableSlug(): void
    {
        $slug = EventSlug::forEvent(['title' => '!!!', 'start_date' => '2026-09-06 10:30:00']);

        $this->assertSame('termin-06-09-2026', $slug);
        $this->assertNotNull(EventSlug::parse($slug));
    }

    public function testParseReadsTitleAndDateBack(): void
    {
        $this->assertSame(
            ['title' => 'gottesdienst', 'date' => '2026-09-06'],
            EventSlug::parse('gottesdienst-06-09-2026')
        );
    }

    /**
     * Der Titel darf selbst wie ein Datum aussehen — der gierige Teil des
     * Ausdrucks nimmt alles bis zu den *letzten* drei Zahlengruppen.
     */
    public function testTheDateIsReadFromTheEndNotTheStart(): void
    {
        $this->assertSame(
            ['title' => 'rueckblick-01-02-2025', 'date' => '2026-09-06'],
            EventSlug::parse('rueckblick-01-02-2025-06-09-2026')
        );
    }

    /**
     * @dataProvider notASlugProvider
     */
    public function testRejectsWhatIsNotOneOfOurSlugs(string $slug, string $why): void
    {
        $this->assertNull(EventSlug::parse($slug), $why);
    }

    public function notASlugProvider(): array
    {
        return [
            'ohne Datum' => ['gottesdienst', 'Ein Seitenslug ohne Datum gehört WordPress, nicht uns.'],
            'nur Datum' => ['06-09-2026', 'Ohne Titelteil ist es keiner unserer Slugs.'],
            'Datum unvollständig' => ['gottesdienst-6-9-2026', 'Zweistellig ist die Form, die forEvent() baut.'],
            // Ohne diese Prüfung liefe eine erfundene Adresse in eine
            // Datumsabfrage statt in eine 404.
            'Tag gibt es nicht' => ['sommerfest-31-02-2026', 'Den 31. Februar hat kein Jahr.'],
            'Monat gibt es nicht' => ['sommerfest-01-13-2026', 'Einen 13. Monat auch nicht.'],
            'leer' => ['', 'Leer ist nichts.'],
        ];
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testAnyTitleSurvivesTheRoundTrip(string $title): void
    {
        $event = ['title' => $title, 'start_date' => '2026-09-06 19:30:00'];
        $parsed = EventSlug::parse(EventSlug::forEvent($event));

        $this->assertNotNull($parsed, 'Jeder gebaute Slug muss wieder lesbar sein.');
        $this->assertSame('2026-09-06', $parsed['date']);
        $this->assertTrue(
            EventSlug::matchesTitle($event, $parsed['title']),
            'Der zerlegte Titelteil muss denselben Termin wiederfinden.'
        );
    }

    public function roundTripProvider(): array
    {
        return [
            ['Gottesdienst'],
            ['Kindergottesdienst "Schatzinsel"'],
            ['Treff am Mittwoch'],
            ['Kinderferienprogramm: Royal Rangers'],
            ['Gebet für Israel'],
            ['Männerfrühstück'],
            ['Ehe-Kurs 2026'],
            ['Rückblick 01-02-2025'],
            ['!!!'],
            ['   '],
        ];
    }

    /**
     * Zwei Termine desselben Tages mit verschiedenen Titeln dürfen sich nicht
     * gegenseitig treffen — sonst führte eine Adresse auf den falschen Termin.
     */
    public function testMatchesTitleTellsTwoEventsOfTheSameDayApart(): void
    {
        $service = ['title' => 'Gottesdienst'];
        $prayer = ['title' => 'Gebet'];

        $this->assertTrue(EventSlug::matchesTitle($service, 'gottesdienst'));
        $this->assertFalse(EventSlug::matchesTitle($prayer, 'gottesdienst'));
    }
}
