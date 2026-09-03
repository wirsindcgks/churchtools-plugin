<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DetailSeo;
use PHPUnit\Framework\TestCase;

/**
 * Der Kopf einer Terminseite. Was hier schiefgeht, sieht man auf der Seite
 * selbst nicht — es zeigt sich erst in einer Trefferliste oder in der Vorschau
 * einer weitergeleiteten Nachricht, also dort, wo der Betreiber es nicht
 * nachschaut.
 *
 * Der Grund für die ganze Klasse: Ein Termin hat keinen eigenen Beitrag. Alles,
 * was WordPress und die SEO-Plugins in den Kopf schreiben, holen sie aus dem
 * Beitrag — auf einer Terminseite ist das die Elternseite. Ohne diese Werte
 * trüge jeder Termin Titel, Beschreibung und Vorschaubild der Terminliste.
 */
final class DetailSeoTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
        ctp_test_reset_hooks();
        DetailSeo::reset();

        ctp_test_set_option('blogname', 'Musterkirche');
        ctp_test_set_option('date_format', 'j. F Y');
        ctp_test_set_option('time_format', 'H:i');
        ctp_test_set_option('permalink_structure', '/%postname%/');
    }

    protected function tearDown(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
        ctp_test_reset_hooks();
        DetailSeo::reset();
    }

    public function testTheTitleNamesTheEventAndTheSite(): void
    {
        DetailSeo::registerForEvent($this->event());

        $this->assertSame('Gottesdienst – Musterkirche', DetailSeo::title('Termine – Musterkirche'));
    }

    /**
     * Ohne registrierten Termin bleibt jeder Wert der, den der Aufrufer
     * mitgebracht hat. Die Filter dieser Klasse hängen einen ganzen Request
     * lang im System, auch auf Seiten, die gar kein Termin sind — dort dürfen
     * sie nichts anfassen.
     */
    public function testWithoutAnEventNothingIsChanged(): void
    {
        $this->assertSame('Termine – Musterkirche', DetailSeo::title('Termine – Musterkirche'));
        $this->assertSame('Alles zu unseren Terminen', DetailSeo::description('Alles zu unseren Terminen'));
        $this->assertSame('https://beispiel.test/termine/', DetailSeo::canonicalUrl('https://beispiel.test/termine/'));
        $this->assertSame('', DetailSeo::imageUrl());
    }

    /**
     * Wann und wo zuerst: Genau das schneidet eine Suchmaschine sonst als
     * Letztes ab, und genau danach sucht, wer den Treffer anklickt.
     */
    public function testTheDescriptionLeadsWithDateTimeAndPlace(): void
    {
        DetailSeo::registerForEvent($this->event());

        $this->assertSame(
            '6. September 2026, 19:30–21:00 Uhr, Gemeindehaus – Gemeinsam feiern, mit Musik und Predigt.',
            DetailSeo::description()
        );
    }

    public function testALongDescriptionIsCutAtAWordBoundary(): void
    {
        DetailSeo::registerForEvent($this->event([
            'description' => str_repeat('Lobpreis ', 100),
        ]));

        $description = DetailSeo::description();

        $this->assertLessThanOrEqual(161, mb_strlen($description), '160 Zeichen plus Auslassungszeichen');
        $this->assertStringEndsWith('…', $description);
        $this->assertStringNotContainsString('Lobprei…', $description, 'nicht mitten im Wort');
    }

    /**
     * Die Adresse des Termins, nicht die der Seite, unter der er liegt. Ein
     * Canonical auf die Terminliste hieße „diese Seite ist nur eine Kopie
     * jener" — die zuverlässigste Art, einen Termin aus dem Index zu halten.
     */
    public function testTheCanonicalPointsAtTheEventItself(): void
    {
        DetailSeo::registerForEvent($this->event());

        $this->assertSame(
            'https://beispiel.test/churchtools-termin/4021/',
            DetailSeo::canonicalUrl('https://beispiel.test/termine/')
        );
    }

    /**
     * Die Falle in der Datenhaltung: `image_url` in der Tabelle ist die
     * ChurchTools-Adresse, und die antwortet ohne Anmeldung mit HTTP 401. Als
     * Vorschaubild einer geteilten Nachricht wäre sie ein garantiert leeres
     * Bild — und obendrein ein Verweis auf eine Adresse, die Besucher dieser
     * Website nie zu sehen bekommen sollen.
     */
    public function testThePreviewImageComesFromTheMediaLibraryNotFromChurchTools(): void
    {
        ctp_test_set_attachment_url(88, 'https://beispiel.test/wp-content/uploads/flyer.webp');

        DetailSeo::registerForEvent($this->event(['attachment_id' => 88]));

        $this->assertSame('https://beispiel.test/wp-content/uploads/flyer.webp', DetailSeo::imageUrl());
    }

    /**
     * Ohne eigenes Bild springt das Standardbild des Kalenders ein — dieselbe
     * Reihenfolge wie in den Kacheln (EventListRenderer::resolveImage()).
     */
    public function testWithoutItsOwnImageTheCalendarsDefaultIsUsed(): void
    {
        ctp_test_set_attachment_url(12, 'https://beispiel.test/wp-content/uploads/kalenderbild.webp');
        ctp_test_set_option('ctp_settings', [
            'calendars' => [7 => ['name' => 'Gottesdienste', 'color' => '', 'default_image_id' => 12]],
        ]);

        DetailSeo::registerForEvent($this->event());

        $this->assertSame('https://beispiel.test/wp-content/uploads/kalenderbild.webp', DetailSeo::imageUrl());
    }

    /**
     * Die Zusage an Yoast und Rank Math: In deren Filtern hängt der Termin.
     * Ohne installiertes Fremd-Plugin prüft das sonst niemand — und ein
     * Tippfehler im Hook-Namen fällt genau dort nicht auf, sondern erst auf
     * einer Website, die das Plugin einsetzt.
     */
    public function testTheFiltersOfTheCommonSeoPluginsAreServed(): void
    {
        DetailSeo::registerForEvent($this->event());

        foreach ([
            'wpseo_title',
            'wpseo_metadesc',
            'wpseo_canonical',
            'wpseo_opengraph_title',
            'wpseo_opengraph_desc',
            'wpseo_opengraph_url',
            'wpseo_opengraph_image',
            'rank_math/frontend/title',
            'rank_math/frontend/description',
            'rank_math/frontend/canonical',
            'rank_math/opengraph/url',
            'rank_math/opengraph/facebook/og_title',
            'rank_math/opengraph/facebook/og_description',
            'pre_get_document_title',
        ] as $hook) {
            $this->assertNotEmpty(ctp_test_hook_callbacks($hook), "kein Wert für {$hook}");
        }
    }

    /**
     * Yoast reicht durch seinen Bild-Filter eine Zeichenkette, Rank Math kann
     * auch ein Feld mit mehreren Angaben schicken. Was keine Zeichenkette ist,
     * bleibt unangetastet — einem fremden Plugin etwas unterzuschieben, das es
     * so nicht erwartet, wäre ein Fehler auf einer Seite, die vorher
     * funktioniert hat.
     */
    public function testAnImageValueThatIsNotAnAddressIsLeftAlone(): void
    {
        ctp_test_set_attachment_url(88, 'https://beispiel.test/wp-content/uploads/flyer.webp');
        DetailSeo::registerForEvent($this->event(['attachment_id' => 88]));

        $this->assertSame(
            'https://beispiel.test/wp-content/uploads/flyer.webp',
            DetailSeo::imageUrlOrKeep('https://beispiel.test/wp-content/uploads/seite.webp')
        );
        $this->assertSame(
            ['url' => 'https://beispiel.test/seite.webp', 'width' => 1200],
            DetailSeo::imageUrlOrKeep(['url' => 'https://beispiel.test/seite.webp', 'width' => 1200])
        );
    }

    public function testTheHeadCarriesTheEventsOwnPreview(): void
    {
        ctp_test_set_attachment_url(88, 'https://beispiel.test/wp-content/uploads/flyer.webp');
        DetailSeo::registerForEvent($this->event(['attachment_id' => 88]));

        $head = $this->renderHead();

        $this->assertStringContainsString('<meta property="og:title" content="Gottesdienst – Musterkirche" />', $head);
        $this->assertStringContainsString('<meta property="og:url" content="https://beispiel.test/churchtools-termin/4021/" />', $head);
        $this->assertStringContainsString('<meta property="og:image" content="https://beispiel.test/wp-content/uploads/flyer.webp" />', $head);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image" />', $head);
        $this->assertStringContainsString('<meta name="description" content="6. September 2026, 19:30', $head);
        $this->assertStringContainsString('<meta property="og:site_name" content="Musterkirche" />', $head);
    }

    public function testWithoutAnImageTheCardStaysSmall(): void
    {
        DetailSeo::registerForEvent($this->event());

        $head = $this->renderHead();

        $this->assertStringContainsString('<meta name="twitter:card" content="summary" />', $head);
        $this->assertStringNotContainsString('og:image', $head);
    }

    /**
     * Das Canonical nur dort, wo es sonst keines gäbe: Auf der
     * Elternseiten-Route gibt es einen Beitrag, also schreibt WordPress selbst
     * eines (EventDetailPage::filterHostedCanonicalUrl() biegt es um) — ein
     * zweites daneben wäre eine widersprüchliche Angabe.
     */
    public function testTheOwnCanonicalOnlyAppearsOnTheRouteWithoutAPost(): void
    {
        DetailSeo::registerForEvent($this->event(), false);
        $this->assertStringNotContainsString('rel="canonical"', $this->renderHead());

        DetailSeo::registerForEvent($this->event(), true);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://beispiel.test/churchtools-termin/4021/" />',
            $this->renderHead()
        );
    }

    private function renderHead(): string
    {
        ob_start();
        DetailSeo::renderMetaTags();

        return (string) ob_get_clean();
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
            'location' => 'Gemeindehaus',
            'start_date' => '2026-09-06 19:30:00',
            'end_date' => '2026-09-06 21:00:00',
            'all_day' => 0,
            'attachment_id' => 0,
            // Die Spalte, wie sie in der Tabelle steht: die ChurchTools-Adresse.
            'image_url' => 'https://musterkirche.church.tools/files/4021/flyer.jpg',
        ], $overrides);
    }
}
