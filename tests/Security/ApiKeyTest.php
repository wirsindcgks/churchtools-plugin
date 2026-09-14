<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Security;

use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        putenv(ApiKey::CONSTANT);
    }

    protected function tearDown(): void
    {
        putenv(ApiKey::CONSTANT);
    }

    public function testTheStoredKeyIsReadDecrypted(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => Crypto::encrypt('gespeichert')]);

        $this->assertSame('gespeichert', ApiKey::current());
        $this->assertTrue(ApiKey::isConfigured());
        $this->assertFalse(ApiKey::isFromConfig());
    }

    /** Die Serverkonfiguration schlaegt die Datenbank - dafuer gibt es sie. */
    public function testTheEnvironmentWinsOverTheDatabase(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => Crypto::encrypt('gespeichert')]);
        putenv(ApiKey::CONSTANT . '=aus-der-umgebung');

        $this->assertSame('aus-der-umgebung', ApiKey::current());
        $this->assertTrue(ApiKey::isFromConfig());
    }

    /** Ein Key aus der Konfiguration kann nicht „nicht lesbar" sein. */
    public function testAConfiguredKeyHidesAnUnreadableStoredOne(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => 'ctp2:kaputt']);
        putenv(ApiKey::CONSTANT . '=aus-der-umgebung');

        $this->assertFalse(ApiKey::decryptionFailed());
    }

    public function testAnUnreadableStoredKeyIsReportedAsSuch(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => 'ctp2:' . base64_encode(random_bytes(64))]);

        $this->assertSame('', ApiKey::current());
        $this->assertTrue(ApiKey::decryptionFailed());
        $this->assertStringContainsString('AUTH_KEY', ApiKey::unusableMessage());
    }

    public function testNothingConfiguredIsNotAnError(): void
    {
        $this->assertFalse(ApiKey::isConfigured());
        $this->assertFalse(ApiKey::decryptionFailed());
        $this->assertStringContainsString('Kein API-Key', ApiKey::unusableMessage());
    }

    /** Die alte Verschluesselung wird einmal in die neue umgeschrieben. */
    public function testMigrateRewritesALegacyValue(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => 'ctp1:' . ctp_test_legacy_encrypt('alter-token')]);

        $this->assertTrue(ApiKey::migrate());

        $stored = get_option('ctp_settings');
        $this->assertStringStartsWith('ctp2:', $stored['api_key']);
        $this->assertSame('musterkirche', $stored['instance']);
        $this->assertSame('alter-token', ApiKey::current());
        $this->assertFalse(ApiKey::migrate(), 'Ein zweiter Durchlauf hat nichts mehr zu tun.');
    }

    /** Auch der doppelt verschluesselte Wert aus der Zeit vor 0.12.4. */
    public function testMigrateUnwrapsTheDoubleEncryptedValue(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => ctp_test_legacy_encrypt(ctp_test_legacy_encrypt('token-aus-der-kaputten-zeit'))]);

        $this->assertTrue(ApiKey::migrate());
        $this->assertSame('token-aus-der-kaputten-zeit', ApiKey::current());
    }

    /** Nicht lesbar heisst: stehen lassen, nicht loeschen. */
    public function testMigrateLeavesAnUnreadableValueAlone(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => 'ctp1:' . base64_encode(random_bytes(48))]);
        $before = get_option('ctp_settings');

        $this->assertFalse(ApiKey::migrate());
        $this->assertSame($before, get_option('ctp_settings'));
    }
}
