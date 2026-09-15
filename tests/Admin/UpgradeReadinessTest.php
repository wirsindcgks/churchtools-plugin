<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\UpgradeReadiness;
use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class UpgradeReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        putenv('CTP_API_KEY');
    }

    public function testASiteOnCurrentVersionsWithoutLeftoversIsReady(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => Crypto::encrypt('token'), 'rooms_mode' => 'single']);

        $checks = UpgradeReadiness::checks('8.3.33', '7.1', []);

        $this->assertSame(0, UpgradeReadiness::openActions($checks));
        $this->assertSame(['ok'], array_values(array_unique(array_column($checks, 'status'))));
    }

    /** Die Live-Seite laeuft auf PHP 8.3 - eine 8.2 darunter muss auffallen, 8.3.0 nicht. */
    public function testPhpAndWordPressBelowTheNewMinimumNeedAction(): void
    {
        $byKey = $this->byKey(UpgradeReadiness::checks('8.2.29', '6.5.5', []));

        $this->assertSame('action', $byKey['php']['status']);
        $this->assertStringContainsString('8.2.29', $byKey['php']['label']);
        $this->assertSame('action', $byKey['wordpress']['status']);

        $atMinimum = $this->byKey(UpgradeReadiness::checks('8.3.0', '6.6', []));
        $this->assertSame('ok', $atMinimum['php']['status']);
        $this->assertSame('ok', $atMinimum['wordpress']['status']);
    }

    /** 2.0 liest die alte Verschluesselung nicht mehr; was nach 1.27 noch so dasteht, ist neu einzutragen. */
    public function testAKeyInTheOldFormatNeedsAction(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => 'ctp1:' . ctp_test_legacy_encrypt('token')]);

        $this->assertSame('action', $this->byKey(UpgradeReadiness::checks('8.3.0', '6.6', []))['api_key']['status']);
    }

    /** Ein Key aus wp-config.php hat kein Speicherformat - dort gibt es nichts zu tun. */
    public function testAKeyFromTheConfigurationIsNeverAnAction(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => 'ctp1:' . ctp_test_legacy_encrypt('token')]);
        putenv('CTP_API_KEY=aus-der-umgebung');

        $this->assertSame('ok', $this->byKey(UpgradeReadiness::checks('8.3.0', '6.6', []))['api_key']['status']);

        putenv('CTP_API_KEY');
    }

    /** Die Bruecke aus 1.12: nur wer noch am alten Schluessel haengt. */
    public function testTheRoomsBridgeNeedsActionOnlyWithoutTheNewSetting(): void
    {
        ctp_test_set_option('ctp_settings', ['rooms_exclusive' => true]);
        $this->assertSame('action', $this->byKey(UpgradeReadiness::checks('8.3.0', '6.6', []))['rooms_mode']['status']);

        ctp_test_set_option('ctp_settings', ['rooms_exclusive' => true, 'rooms_mode' => 'exclusive']);
        $this->assertSame('ok', $this->byKey(UpgradeReadiness::checks('8.3.0', '6.6', []))['rooms_mode']['status']);
    }

    /** Eigene Vorlagen sind kein Hindernis, aber nach dem Update zu vergleichen. */
    public function testThemeTemplatesAreAHintNotAnAction(): void
    {
        $checks = UpgradeReadiness::checks('8.3.0', '6.6', ['group-grid.php', 'event-list.php']);
        $templates = $this->byKey($checks)['templates'];

        $this->assertSame('hint', $templates['status']);
        $this->assertStringContainsString('group-grid.php, event-list.php', $templates['detail']);
        $this->assertSame(0, UpgradeReadiness::openActions($checks));
    }

    /** @return array<string, array{status: string, label: string, detail: string}> */
    private function byKey(array $checks): array
    {
        return array_column($checks, null, 'key');
    }
}
