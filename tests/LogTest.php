<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests;

use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Log;
use PHPUnit\Framework\TestCase;

/**
 * Anlass (2026-09-15): ChurchTools beantwortete den Bilddownload zwei Wochen
 * lang mit 401, und SyncEngine::importImage() gab dabei still `null` zurueck
 * - der Ausfall blieb unbemerkt. Diese Tests decken die zwei Dinge ab, an
 * denen dieses Protokoll haengt: dass ein Aufruf wirklich ankommt (Stufe,
 * Hook, error_log), und dass der Datenschutz an dieser einen Stelle greift,
 * unabhaengig davon, was eine Aufrufstelle im Kontext mitgibt.
 */
final class LogTest extends TestCase
{
    private LogRepository $repository;

    protected function setUp(): void
    {
        ctp_test_set_current_time('2026-09-16 12:00:00');
        ctp_test_reset_hooks();

        ctp_test_install_wpdb();
        $this->repository = new LogRepository();
    }

    public function testErrorIsStoredWithItsLevel(): void
    {
        Log::error(Log::AREA_EVENTS, 'Synchronisation fehlgeschlagen');

        $rows = $this->repository->find();

        $this->assertSame('error', $rows[0]['level']);
        $this->assertSame('events', $rows[0]['area']);
        $this->assertSame('Synchronisation fehlgeschlagen', $rows[0]['message']);
    }

    public function testWarningIsStoredWithItsLevel(): void
    {
        Log::warning(Log::AREA_IMAGES, 'Terminbild konnte nicht importiert werden');

        $this->assertSame('warning', $this->repository->find()[0]['level']);
    }

    public function testInfoIsStoredWithItsLevel(): void
    {
        Log::info(Log::AREA_MIGRATION, 'Datenbank aktualisiert');

        $this->assertSame('info', $this->repository->find()[0]['level']);
    }

    /**
     * Der Hook `ctp_log` ist Teil der Kompatibilitaetszusage
     * (docs/COMPATIBILITY.md) - ein Betreiber mit eigenem Logger muss sich
     * darauf verlassen koennen, dass er bei jedem Schreibvorgang feuert, mit
     * denselben (bereits bereinigten) Angaben, die auch in der Tabelle stehen.
     */
    public function testCtpLogHookFiresWithLevelAreaMessageAndContext(): void
    {
        $seen = [];
        add_action('ctp_log', function (string $level, string $area, string $message, array $context) use (&$seen): void {
            $seen = [$level, $area, $message, $context];
        }, 10, 4);

        Log::warning(Log::AREA_GROUPS, 'Homepage konnte nicht abgeglichen werden', ['count' => 3]);

        $this->assertSame(['warning', 'groups', 'Homepage konnte nicht abgeglichen werden', ['count' => 3]], $seen);
    }

    /**
     * Ein Feldname wie "api_key" sagt schon selbst genug - der Wert wird
     * ausgelassen, nicht durch ein "[redacted]" ersetzt (das waere nur
     * Rauschen neben einem bereits sprechenden Schluessel).
     */
    public function testSensitiveKeysAreDroppedFromTheContext(): void
    {
        Log::info(Log::AREA_EVENTS, 'Test', [
            'api_key' => 'geheim',
            'Authorization' => 'Login geheim',
            'token' => 'geheim',
            'count' => 3,
        ]);

        $this->assertSame(['count' => 3], $this->repository->find()[0]['context']);
    }

    /**
     * Ein verschluesselter Key kann unter jedem Feldnamen im Kontext landen
     * (siehe Security\Crypto) - der Schutz greift deshalb am Wert, nicht nur
     * am Schluessel.
     */
    public function testEncryptedKeyValuesAreRedactedRegardlessOfTheirFieldName(): void
    {
        Log::info(Log::AREA_MIGRATION, 'Test', ['previous_value' => 'ctp2:irgendein-geheimtext']);

        $this->assertSame(['previous_value' => '[redacted]'], $this->repository->find()[0]['context']);
    }

    public function testLegacyEncryptedKeyValuesAreAlsoRedacted(): void
    {
        Log::info(Log::AREA_MIGRATION, 'Test', ['previous_value' => 'ctp1:irgendein-geheimtext']);

        $this->assertSame(['previous_value' => '[redacted]'], $this->repository->find()[0]['context']);
    }

    /**
     * str_starts_with() in sanitizeValue() findet einen Key nur am Anfang
     * eines Werts - eine zusammengesetzte Fehlermeldung braucht die eigene
     * Regel in sanitizeMessage().
     */
    public function testEncryptedKeyEmbeddedMidSentenceInTheMessageIsRedacted(): void
    {
        Log::error(Log::AREA_EVENTS, 'Migration fehlgeschlagen: ctp2:irgendein-geheimtext danach noch Text');

        $this->assertSame(
            'Migration fehlgeschlagen: [redacted] danach noch Text',
            $this->repository->find()[0]['message']
        );
    }

    public function testMessageWithoutAKeyIsLeftUnchanged(): void
    {
        Log::info(Log::AREA_EVENTS, 'Synchronisation abgeschlossen: 98 Termine, 0 Bilder gescheitert (2,2 s).');

        $this->assertSame(
            'Synchronisation abgeschlossen: 98 Termine, 0 Bilder gescheitert (2,2 s).',
            $this->repository->find()[0]['message']
        );
    }

    /**
     * fileUrl (`?q=public/filedownload&id=…`) kann ein Token tragen - der
     * Abfrageteil einer Adresse im Kontext wird deshalb immer entfernt, nicht
     * nur fuer bekannte Faelle.
     */
    public function testUrlValuesLoseTheirQueryString(): void
    {
        Log::warning(Log::AREA_IMAGES, 'Test', ['url' => 'https://example.church.tools/images/1/abc?w=1600&h=1600']);

        $this->assertSame(['url' => 'https://example.church.tools/images/1/abc'], $this->repository->find()[0]['context']);
    }

    public function testUrlValuesWithoutAQueryStringAreLeftUnchanged(): void
    {
        Log::info(Log::AREA_EVENTS, 'Test', ['url' => 'https://example.church.tools/images/1/abc']);

        $this->assertSame(['url' => 'https://example.church.tools/images/1/abc'], $this->repository->find()[0]['context']);
    }

    /**
     * Dieselbe Regel wie fuer die gespeicherte Rohantwort (raw_data, siehe
     * SyncEngine::withoutPersonReferences()) - ein Kontext ist frei geformt,
     * und ein Aufrufer, der versehentlich eine ganze ChurchTools-Huelle
     * mitgibt, soll trotzdem keine Personenverweise ins Protokoll tragen.
     */
    public function testPersonReferencesAreRemovedFromNestedContext(): void
    {
        Log::info(Log::AREA_EVENTS, 'Test', [
            'appointment' => [
                'id' => 42,
                'meta' => [
                    'createdPerson' => ['id' => 7, 'name' => 'Jemand'],
                ],
            ],
        ]);

        $this->assertSame(
            ['appointment' => ['id' => 42, 'meta' => []]],
            $this->repository->find()[0]['context']
        );
    }

    public function testEmptyContextIsStoredAsAnEmptyArray(): void
    {
        Log::info(Log::AREA_EVENTS, 'Test');

        $this->assertSame([], $this->repository->find()[0]['context']);
    }

    /**
     * PHPs error_log() laesst sich nur ueber sein eigenes Ziel abfangen, nicht
     * durch Ueberschreiben der eingebauten Funktion - also die INI-Direktive
     * kurz auf eine Datei umbiegen und danach zurueckstellen.
     *
     * WP_DEBUG_LOG bleibt als PHP-Konstante zwangslaeufig fuer den Rest des
     * Prozesses definiert (Konstanten lassen sich nicht wieder loeschen) -
     * @runInSeparateProcess haelt das von den uebrigen Tests fern, die sonst
     * unbeabsichtigt ebenfalls in error_log() schreiben wuerden.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWritesToPhpsErrorLogWhenDebugLoggingIsEnabled(): void
    {
        if (!defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', true);
        }

        $previousDestination = (string) ini_get('error_log');
        $tempFile = tempnam(sys_get_temp_dir(), 'ctp-log-test-');
        ini_set('error_log', $tempFile);

        try {
            Log::error(Log::AREA_EVENTS, 'Test mit WP_DEBUG_LOG');
        } finally {
            ini_set('error_log', $previousDestination);
        }

        $this->assertStringContainsString(
            '[churchtools-plugin] error/events: Test mit WP_DEBUG_LOG',
            (string) file_get_contents($tempFile)
        );

        unlink($tempFile);
    }
}
