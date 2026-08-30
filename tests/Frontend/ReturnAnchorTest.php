<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\ReturnAnchor;
use PHPUnit\Framework\TestCase;

/**
 * Das Sprungziel des „Zurück"-Knopfes. Die eine Regel, die hier nicht
 * offensichtlich ist: Jede id wird pro Seite nur einmal vergeben. Steht
 * derselbe Termin zweimal auf einer Seite — ein Teaser oben, die vollständige
 * Liste darunter —, bekäme er sonst zweimal dieselbe id. Auf der Testseite
 * waren das 33 doppelte von 48 Sprungzielen, und doppelte IDs sind ungültiges
 * HTML.
 */
final class ReturnAnchorTest extends TestCase
{
    protected function setUp(): void
    {
        ReturnAnchor::reset();
    }

    public function testTheFirstOccurrenceGetsTheAnchor(): void
    {
        $this->assertSame('ctp-event-4021', ReturnAnchor::idFor(['id' => 4021]));
    }

    public function testASecondOccurrenceOfTheSameEventGetsNone(): void
    {
        ReturnAnchor::idFor(['id' => 4021]);

        $this->assertSame('', ReturnAnchor::idFor(['id' => 4021]), 'Zweimal dieselbe id wäre ungültiges HTML.');
        // Der Browser springt ohnehin zum ersten Vorkommen — genau das bleibt
        // übrig, wenn nur das erste eine id trägt.
        $this->assertSame('ctp-event-4022', ReturnAnchor::idFor(['id' => 4022]), 'Andere Termine bleiben unberührt.');
    }

    /**
     * Eine Zeile ohne `id` kommt aus dieser Datenbank nicht, wohl aber aus
     * einem Theme-Override. `id="ctp-event-0"` an jeder Kachel wäre dort
     * schlechter als gar kein Sprungziel.
     *
     * @dataProvider withoutAnIdProvider
     */
    public function testAnEventWithoutAnIdGetsNoAnchor(array $event): void
    {
        $this->assertSame('', ReturnAnchor::idFor($event));
        $this->assertSame('', ReturnAnchor::fragmentFor($event));
    }

    public function withoutAnIdProvider(): array
    {
        return [
            'kein Schlüssel' => [['title' => 'Gottesdienst']],
            'null' => [['id' => null]],
            'leerer String' => [['id' => '']],
            'Null' => [['id' => 0]],
        ];
    }

    /**
     * Die Rücksprung-Adresse merkt sich nichts: Die Detailseite zeigt genau
     * einen Termin, und ob dessen Kachel auf der Zielseite steht, weiß sie
     * nicht.
     */
    public function testTheFragmentIsIndependentOfWhatWasAlreadyRendered(): void
    {
        ReturnAnchor::idFor(['id' => 4021]);

        $this->assertSame('#ctp-event-4021', ReturnAnchor::fragmentFor(['id' => 4021]));
        $this->assertSame('#ctp-event-4021', ReturnAnchor::fragmentFor(['id' => 4021]));
    }
}
