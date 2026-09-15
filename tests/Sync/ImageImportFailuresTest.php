<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Sync\ImageImportFailures;
use PHPUnit\Framework\TestCase;

/**
 * Am 2026-09-15 hat ein still gescheiterter Bild-Import einen 401 beim
 * Bilddownload zwei Wochen lang verdeckt. Diese Tests halten fest, dass der
 * Grund lesbar ankommt und die Warnung wieder verschwindet, sobald ein Lauf
 * ohne Fehlschlag durchgeht.
 */
final class ImageImportFailuresTest extends TestCase
{
    private const OPTION = 'ctp_image_import_warning';

    protected function setUp(): void
    {
        ctp_test_reset_options();
    }

    /**
     * download_url() nennt fuer jeden Status ausser 200 den Code `http_404`
     * und nur den Statustext als Meldung - die Zahl steht in den Daten.
     */
    public function testReasonTakesTheHttpStatusFromTheErrorData(): void
    {
        $this->assertSame(
            'HTTP 401 Unauthorized',
            ImageImportFailures::reasonFor('Unauthorized', ['code' => 401, 'body' => '{"message":"Die Berechtigung appointment_image ist notwendig"}'])
        );
    }

    public function testReasonWithoutStatusTextStillNamesTheStatus(): void
    {
        $this->assertSame('HTTP 502', ImageImportFailures::reasonFor('', ['code' => 502]));
    }

    /** Ein Netzfehler hat keine Daten - dann ist die Meldung der Grund. */
    public function testReasonWithoutStatusIsTheMessage(): void
    {
        $this->assertSame(
            'cURL error 28: Operation timed out',
            ImageImportFailures::reasonFor(' cURL error 28: Operation timed out ', '')
        );
    }

    public function testFailuresAreCountedPerReason(): void
    {
        $failures = new ImageImportFailures();
        $failures->record('HTTP 401 Unauthorized');
        $failures->record('HTTP 401 Unauthorized');
        $failures->record('Die Antwort ist kein Bild');

        $this->assertSame(3, $failures->count());
        $this->assertSame(['HTTP 401 Unauthorized' => 2, 'Die Antwort ist kein Bild' => 1], $failures->reasons());
    }

    public function testAnEmptyReasonIsNotSwallowed(): void
    {
        $failures = new ImageImportFailures();
        $failures->record('  ');

        $this->assertSame(['unbekannter Fehler' => 1], $failures->reasons());
    }

    /** Meldungen mit wechselnden Zahlen duerfen die Warnung nicht endlos verlaengern. */
    public function testDistinctReasonsAreCappedWithoutLosingTheCount(): void
    {
        $failures = new ImageImportFailures();

        for ($i = 1; $i <= 8; $i++) {
            $failures->record('cURL error 28: Operation timed out after ' . $i . ' milliseconds');
        }

        $this->assertCount(6, $failures->reasons());
        $this->assertSame(3, $failures->reasons()['weitere Gründe']);
        $this->assertSame(8, $failures->count());
    }

    public function testStoredWarningReadsBackWithTheMostFrequentReasonFirst(): void
    {
        $failures = new ImageImportFailures();
        $failures->record('Die Antwort ist kein Bild');
        $failures->record('HTTP 401 Unauthorized');
        $failures->record('HTTP 401 Unauthorized');
        $failures->store(self::OPTION, '2026-09-15 21:00:00');

        $this->assertSame([
            'time' => '2026-09-15 21:00:00',
            'count' => 3,
            'reasons' => 'HTTP 401 Unauthorized (2×) · Die Antwort ist kein Bild (1×)',
        ], ImageImportFailures::read(self::OPTION));
    }

    /** Der erste Lauf ohne Fehlschlag raeumt die Warnung eines frueheren ab. */
    public function testARunWithoutFailuresClearsTheWarning(): void
    {
        ctp_test_set_option(self::OPTION, ['time' => '2026-09-15 20:00:00', 'reasons' => ['HTTP 401 Unauthorized' => 3]]);

        (new ImageImportFailures())->store(self::OPTION, '2026-09-15 21:00:00');

        $this->assertNull(ImageImportFailures::read(self::OPTION));
        $this->assertFalse(get_option(self::OPTION));
    }

    /**
     * @dataProvider malformedWarnings
     */
    public function testMalformedStoredValuesAreNoWarning($stored): void
    {
        ctp_test_set_option(self::OPTION, $stored);

        $this->assertNull(ImageImportFailures::read(self::OPTION));
    }

    public static function malformedWarnings(): array
    {
        return [
            'kein Array' => ['HTTP 401'],
            'ohne Zeit' => [['reasons' => ['HTTP 401' => 1]]],
            'Gruende kein Array' => [['time' => '2026-09-15 21:00:00', 'reasons' => 'HTTP 401']],
            'keine gueltige Anzahl' => [['time' => '2026-09-15 21:00:00', 'reasons' => ['HTTP 401' => 0, 'x' => 'drei']]],
        ];
    }
}
