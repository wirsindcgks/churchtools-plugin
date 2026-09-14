<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * „Verbindung testen", „Kalender laden" und „Räume laden" nehmen, was in den
 * Feldern steht, und fallen fuer leere Felder auf das Gespeicherte zurueck -
 * mit einer Grenze: Der gespeicherte Key geht nur an die gespeicherte Instanz.
 */
final class EffectiveConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt('gespeicherter-token')]);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testEmptyFieldsUseTheStoredConnection(): void
    {
        $connection = $this->effectiveConnection();

        $this->assertSame('', $connection['error']);
        $this->assertSame('gespeicherter-token', $connection['api_key']);
        $this->assertSame('https://musterkirche.church.tools', $connection['base_url']);
    }

    /** Der Sicherheitsfall: andere Instanz eingetippt, Key-Feld leer. */
    public function testTheStoredKeyIsNeverSentToAnotherInstance(): void
    {
        $_POST['instance'] = 'fremde-gemeinde';

        $connection = $this->effectiveConnection();

        $this->assertSame('', $connection['api_key']);
        $this->assertStringContainsString('musterkirche', $connection['error']);
    }

    public function testAnotherInstanceWorksWithItsOwnTypedKey(): void
    {
        $_POST['instance'] = 'fremde-gemeinde';
        $_POST['api_key'] = 'eigener-token';

        $connection = $this->effectiveConnection();

        $this->assertSame('', $connection['error']);
        $this->assertSame('eigener-token', $connection['api_key']);
        $this->assertSame('https://fremde-gemeinde.church.tools', $connection['base_url']);
    }

    /** Dieselbe Instanz, nur anders geschrieben, ist keine andere. */
    public function testTheStoredInstanceTypedAsAFullAddressStillGetsTheStoredKey(): void
    {
        $_POST['instance'] = 'https://musterkirche.church.tools/';

        $this->assertSame('gespeicherter-token', $this->effectiveConnection()['api_key']);
    }

    private function effectiveConnection(): array
    {
        $method = new ReflectionMethod(SettingsPage::class, 'effectiveConnection');

        return $method->invoke(null);
    }
}
