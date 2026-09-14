<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Api;

use ChurchToolsPlugin\Api\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

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

    /**
     * /api/info antwortet flach, ohne die `data`-Huelle jedes anderen
     * Endpunkts (am 2026-09-12 an der echten Instanz nachgesehen). Ginge der
     * Aufruf durch request(), verwuerfe der Schutz gegen Proxy-Fehlerseiten
     * jede gueltige Antwort.
     */
    public function testGetInfoReadsTheUnwrappedAnswer(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_raw_http('{"build":"32882","version":"3.136.2","siteName":"Musterkirche","address":{"name":"Gemeindehaus","street":"Hauptstraße 1"}}');

        $info = (new Client('https://example.church.tools', 'token'))->getInfo();

        $this->assertSame('Musterkirche', $info['siteName']);
        $this->assertSame('Hauptstraße 1', $info['address']['street']);
    }

    /**
     * Seit dem Sicherheits-Review vom 2026-09-14 geht jeder Aufruf mit Key,
     * /api/info eingeschlossen - bis 1.26.0 ging dieser eine bewusst ohne.
     */
    public function testGetInfoAsksWithTheAuthorizationHeader(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_raw_http('{"version":"3.136.2"}');

        (new Client('https://example.church.tools', 'token'))->getInfo();

        $calls = ctp_test_http_calls();

        $this->assertCount(1, $calls);
        $this->assertSame('https://example.church.tools/api/info', $calls[0]['url']);
        $this->assertSame('Login token', $calls[0]['args']['headers']['Authorization']);
    }

    /**
     * WordPress folgt Weiterleitungen und schickt den Header an den neuen Host
     * mit, auch an einen fremden. Der Client schaltet das ab - fuer jeden
     * Endpunkt, deshalb hier an allen oeffentlichen Methoden geprueft.
     */
    public function testNoCallFollowsRedirects(): void
    {
        ctp_test_reset_http();
        $client = new Client('https://example.church.tools', 'token');

        ctp_test_queue_raw_http('{"version":"3.136.2"}');
        $client->getInfo();
        ctp_test_queue_http([]);
        $client->getCalendars();
        ctp_test_queue_http([]);
        $client->getGroupHomepages();
        ctp_test_queue_http(['groups' => []]);
        $client->getGroupHomepage('AbC123');

        foreach (ctp_test_http_calls() as $call) {
            $this->assertSame(0, $call['args']['redirection'], $call['url']);
            $this->assertSame('Login token', $call['args']['headers']['Authorization'], $call['url']);
        }
    }

    /**
     * Kaeme eine Weiterleitung doch an (redirection => 0 reicht sie als
     * Antwort durch), ist sie ein Fehler - nicht der Koerper einer
     * Weiterleitungsseite, der dann an der `data`-Pruefung scheitert und eine
     * irrefuehrende Meldung ergaebe.
     */
    public function testARedirectIsReportedAsSuch(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_raw_http('', 302);

        try {
            (new Client('https://example.church.tools', 'token'))->getCalendars();
            $this->fail('Eine Weiterleitung muss eine Ausnahme ergeben.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 302', $exception->getMessage());
        }
    }

    /** Ohne Key kein Netzaufruf - der anonyme Weg ist abgeschafft. */
    public function testWithoutAKeyNothingIsSent(): void
    {
        ctp_test_reset_http();

        try {
            (new Client('https://example.church.tools', ''))->getGroupHomepages();
            $this->fail('Ohne Key muss der Client abbrechen.');
        } catch (RuntimeException $exception) {
            $this->assertSame([], ctp_test_http_calls());
        }
    }

    /**
     * Die Gegenprobe zum Schutz: Eine Fehlerseite mit HTTP 200 darf nicht als
     * Anschrift durchgehen. `address` taugt als Pflichtfeld nicht - eine
     * Gemeinde ohne gepflegte Anschrift ist ein gueltiger Fall -, deshalb
     * haelt `version` die Antwort.
     */
    public function testGetInfoRejectsAnAnswerWithoutVersion(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_raw_http('<html><body>502 Bad Gateway</body></html>');

        $this->expectException(RuntimeException::class);

        (new Client('https://example.church.tools', 'token'))->getInfo();
    }

    /**
     * Eine Gemeinde ohne gepflegte Anschrift ist kein Fehler: Die Antwort
     * kommt durch, das Adressfeld fehlt schlicht.
     */
    public function testGetInfoAcceptsAnInstanceWithoutAnAddress(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_raw_http('{"version":"3.136.2","siteName":"Musterkirche","address":null}');

        $info = (new Client('https://example.church.tools', 'token'))->getInfo();

        $this->assertNull($info['address']);
    }

    private function extractErrorMessage(string $rawBody, mixed $decoded): string
    {
        $method = new ReflectionMethod(Client::class, 'extractErrorMessage');

        return $method->invoke(new Client('https://example.church.tools', 'token'), $rawBody, $decoded);
    }
}
