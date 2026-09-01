<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\CardImage;
use PHPUnit\Framework\TestCase;

/**
 * Die Bildbreiten der Kacheln und die `sizes`-Angaben dazu.
 *
 * Was hier geprüft wird, sind keine Formatierungsfragen, sondern die zwei
 * Stellen, an denen die Ersparnis lautlos verschwinden kann: ein Deckel, der
 * nicht deckelt, und eine Spaltenzahl, durch die geteilt wird.
 */
final class CardImageTest extends TestCase
{
    /**
     * Der Fehler, der diesen Test veranlasst hat, war beim Messen sichtbar und
     * im Code unsichtbar: wp_calculate_image_srcset() nimmt die Größe aus dem
     * `src` *immer* in die Kandidatenliste auf, auch über dem Deckel. Lag die
     * Bezugsgröße darüber, stand sie als Kandidat drin und wurde auf Geräten
     * mit hoher Pixeldichte auch gewählt – der Deckel war wirkungslos, ohne
     * dass irgendetwas fehlschlug (gemessen: 1247 statt 981 KB auf 19
     * Kacheln).
     *
     * Deshalb hier festgehalten: Wer die Bezugsgröße oder den Deckel ändert,
     * muss beide zusammen betrachten.
     */
    public function testTheCardReferenceSizeStaysBelowTheCap(): void
    {
        $referenceWidth = CardImage::SIZES[CardImage::CARD_REFERENCE_SIZE] ?? null;

        $this->assertNotNull(
            $referenceWidth,
            'CARD_REFERENCE_SIZE muss eine der selbst registrierten Größen sein – eine WordPress-Größe kann sich unter uns ändern.'
        );
        $this->assertLessThan(
            CardImage::CARD_MAX_SRCSET_WIDTH,
            $referenceWidth,
            'Liegt die Bezugsgröße über dem Deckel, hebt sie ihn auf: Sie steht dann als Kandidat in der Liste.'
        );
    }

    /**
     * Eine Zusatzgröße oberhalb des Deckels wäre für die Kachel-Liste umsonst
     * berechnet und belegte nur Platz – sie käme dort nie vor.
     */
    public function testEveryRegisteredSizeFitsUnderTheCardCap(): void
    {
        foreach (CardImage::SIZES as $name => $width) {
            $this->assertLessThanOrEqual(CardImage::CARD_MAX_SRCSET_WIDTH, $width, $name);
        }
    }

    /**
     * Die Spaltenzahl steht im Nenner. Sie kommt aus
     * EventListRenderer::prepareArgs(), das sie auf 2..6 begrenzt – aber
     * gridSizes() ist public und darf sich darauf nicht verlassen.
     */
    public function testGridSizesDividesByTheColumnCount(): void
    {
        $this->assertStringEndsWith('calc(92vw / 3)', CardImage::gridSizes(3));
        $this->assertStringEndsWith('calc(92vw / 4)', CardImage::gridSizes(4));
    }

    /** @dataProvider brokenColumnProvider */
    public function testGridSizesNeverDividesByZeroOrLess(int $columns): void
    {
        $this->assertStringEndsWith('calc(92vw / 1)', CardImage::gridSizes($columns));
    }

    /** @return array<string, array{0: int}> */
    public static function brokenColumnProvider(): array
    {
        return ['null Spalten' => [0], 'negative Spalten' => [-3]];
    }

    /**
     * Alle drei Angaben müssen mit einer Bedingung für schmale Fenster
     * beginnen: Dort steht das Bild allein und füllt die Breite, und ein aus
     * dem Desktop-Fall abgeleiteter Bruchteil wäre genau da zu klein – also
     * unscharf auf den Geräten, auf denen die Ersparnis am meisten zählt.
     */
    public function testEverySizesHintCoversNarrowViewportsFirst(): void
    {
        foreach ([CardImage::gridSizes(3), CardImage::heroSizes(), CardImage::detailSizes()] as $sizes) {
            $this->assertStringStartsWith('(max-width:', $sizes);
            $this->assertStringContainsString('92vw', $sizes);
        }
    }
}
