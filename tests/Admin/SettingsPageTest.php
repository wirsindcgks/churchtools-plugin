<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
    }

    /**
     * sanitizeInstance() is private — reflection over widening its visibility just
     * for tests, since normalizing user-typed instance/URL input is exactly the
     * kind of small pure logic worth pinning down directly.
     */
    private function sanitizeInstance(string $raw): string
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeInstance');
        $method->setAccessible(true);

        return $method->invoke(null, $raw);
    }

    public function testSanitizeInstanceAcceptsBareInstanceName(): void
    {
        $this->assertSame('cg-ks', $this->sanitizeInstance('cg-ks'));
    }

    /**
     * "Admins paste a full URL out of habit" is the documented reason this
     * normalization exists (see SettingsPage::sanitizeInstance() docblock).
     */
    public function testSanitizeInstanceStripsSchemeAndDomain(): void
    {
        $this->assertSame('cg-ks', $this->sanitizeInstance('https://cg-ks.church.tools/'));
        $this->assertSame('cg-ks', $this->sanitizeInstance('http://cg-ks.church.tools'));
    }

    public function testSanitizeInstanceLowercasesAndTrims(): void
    {
        $this->assertSame('cg-ks', $this->sanitizeInstance('  CG-KS  '));
    }

    public function testSanitizeInstanceStripsDisallowedCharacters(): void
    {
        $this->assertSame('cgks', $this->sanitizeInstance('cg ks!'));
    }

    public function testResolveCalendarIdsPassesThroughNumericIds(): void
    {
        ctp_test_set_option('ctp_settings', ['calendars' => []]);

        $this->assertSame([1, 2, 3], SettingsPage::resolveCalendarIds(['1', '2', '3']));
    }

    public function testResolveCalendarIdsResolvesNamesCaseInsensitively(): void
    {
        ctp_test_set_option('ctp_settings', [
            'calendars' => [
                32 => ['name' => 'Gottesdienst', 'enabled' => true, 'color' => '', 'default_image_id' => 0],
                29 => ['name' => 'Royal Rangers', 'enabled' => true, 'color' => '', 'default_image_id' => 0],
            ],
        ]);

        $this->assertSame([32, 29], SettingsPage::resolveCalendarIds(['gottesdienst', 'ROYAL RANGERS']));
    }

    public function testResolveCalendarIdsMixesIdsAndNames(): void
    {
        ctp_test_set_option('ctp_settings', [
            'calendars' => [
                32 => ['name' => 'Gottesdienst', 'enabled' => true, 'color' => '', 'default_image_id' => 0],
            ],
        ]);

        $this->assertSame([99, 32], SettingsPage::resolveCalendarIds(['99', 'Gottesdienst']));
    }

    public function testResolveCalendarIdsIgnoresUnknownNamesAndEmptyRefs(): void
    {
        ctp_test_set_option('ctp_settings', ['calendars' => []]);

        $this->assertSame([], SettingsPage::resolveCalendarIds(['', '  ', 'Nicht Vorhanden']));
    }

    public function testResolveCalendarIdsDeduplicates(): void
    {
        ctp_test_set_option('ctp_settings', [
            'calendars' => [
                32 => ['name' => 'Gottesdienst', 'enabled' => true, 'color' => '', 'default_image_id' => 0],
            ],
        ]);

        $this->assertSame([32], SettingsPage::resolveCalendarIds(['32', 'Gottesdienst']));
    }
}
