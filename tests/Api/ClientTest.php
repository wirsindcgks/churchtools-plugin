<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Api;

use ChurchToolsPlugin\Api\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * request() selbst braucht wp_remote_request() und damit ein laufendes
 * WordPress. Getestet wird hier die Aufbereitung des Fehlerkörpers: Sie
 * entscheidet, was in ctp_last_sync_error landet — und das steht seit
 * SyncHealthNotice auf jeder Admin-Seite.
 */
final class ClientTest extends TestCase
{
    /**
     * Der Normalfall: ChurchTools antwortet mit JSON, die Meldung wird
     * unverändert übernommen.
     */
    public function testJsonErrorMessageIsUsedAsIs(): void
    {
        $this->assertSame(
            'Session expired!',
            $this->extractErrorMessage('{}', ['errors' => [['message' => 'Session expired!']]])
        );
    }

    public function testTopLevelJsonMessageIsUsedAsIs(): void
    {
        $this->assertSame('Kalender nicht gefunden', $this->extractErrorMessage('{}', ['message' => 'Kalender nicht gefunden']));
    }

    /**
     * Der Ernstfall: Statt der API antwortet ein Proxy mit einer HTML-Seite.
     * Ungekürzt stünden hier zehntausende Zeichen Markup — in der Option, im
     * Tab „Übersicht" und in jedem Admin-Hinweis.
     */
    public function testHtmlErrorPageIsStrippedAndTruncated(): void
    {
        $body = "<html><head><style>body{color:red}</style></head><body>\n  <h1>502 Bad Gateway</h1>\n  <p>"
            . str_repeat('Fehler bei der Verarbeitung. ', 200)
            . "</p>\n</body></html>";

        $message = $this->extractErrorMessage($body, null);

        $this->assertStringStartsWith('502 Bad Gateway Fehler bei der Verarbeitung.', $message);
        $this->assertStringNotContainsString('<', $message);
        $this->assertStringNotContainsString('color:red', $message);
        $this->assertLessThanOrEqual(301, mb_strlen($message));
        $this->assertStringEndsWith('…', $message);
    }

    /**
     * Ein leerer Körper ist keine Meldung, aber die Meldung darf auch nicht
     * leer bleiben - sonst stünde im Backend "Die letzte Synchronisation ist
     * fehlgeschlagen:" ohne alles dahinter.
     */
    public function testEmptyBodyFallsBackToUnknown(): void
    {
        $this->assertSame('unknown', $this->extractErrorMessage("   \n  ", null));
    }

    private function extractErrorMessage(string $rawBody, mixed $decoded): string
    {
        $method = new ReflectionMethod(Client::class, 'extractErrorMessage');

        return $method->invoke(new Client('https://example.church.tools', 'token'), $rawBody, $decoded);
    }
}
