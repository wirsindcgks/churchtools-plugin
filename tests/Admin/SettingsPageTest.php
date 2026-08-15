<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Frontend\CardDesign;
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
        $this->assertSame('musterkirche', $this->sanitizeInstance('musterkirche'));
    }

    /**
     * "Admins paste a full URL out of habit" is the documented reason this
     * normalization exists (see SettingsPage::sanitizeInstance() docblock).
     */
    public function testSanitizeInstanceStripsSchemeAndDomain(): void
    {
        $this->assertSame('musterkirche', $this->sanitizeInstance('https://musterkirche.church.tools/'));
        $this->assertSame('musterkirche', $this->sanitizeInstance('http://musterkirche.church.tools'));
    }

    public function testSanitizeInstanceLowercasesAndTrims(): void
    {
        $this->assertSame('musterkirche', $this->sanitizeInstance('  MUSTERKIRCHE  '));
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

    public function testSanitizeSettingsDefaultsSyncDaysAheadTo180(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([]);

        $this->assertSame(180, $sanitized['sync_days_ahead']);
    }

    public function testSanitizeSettingsAcceptsCustomSyncDaysAhead(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['sync_days_ahead' => '30']);

        $this->assertSame(30, $sanitized['sync_days_ahead']);
    }

    /**
     * A sync window of zero (or negative) days would make SyncEngine::run() fetch
     * an inverted/empty date range — floor it at 1, same enforcement pattern as
     * retention_days' max(0, ...) just above it in sanitizeSettings().
     */
    public function testSanitizeSettingsEnforcesMinimumSyncDaysAheadOfOne(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['sync_days_ahead' => '0']);
        $this->assertSame(1, $sanitized['sync_days_ahead']);

        $sanitized = SettingsPage::sanitizeSettings(['sync_days_ahead' => '-5']);
        $this->assertSame(1, $sanitized['sync_days_ahead']);
    }

    public function testSanitizeSettingsKeepsExistingSyncDaysAheadWhenFieldAbsent(): void
    {
        ctp_test_set_option('ctp_settings', ['sync_days_ahead' => 90]);

        $sanitized = SettingsPage::sanitizeSettings(['instance' => 'musterkirche']);

        $this->assertSame(90, $sanitized['sync_days_ahead']);
    }

    public function testSanitizeSettingsAcceptsValidCornerStyle(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['corner_style' => 'square']);

        $this->assertSame('square', $sanitized['corner_style']);
    }

    public function testSanitizeSettingsFallsBackToExistingCornerStyleWhenInvalid(): void
    {
        ctp_test_set_option('ctp_settings', ['corner_style' => 'square']);

        $sanitized = SettingsPage::sanitizeSettings(['corner_style' => 'triangular']);

        $this->assertSame('square', $sanitized['corner_style']);
    }

    /**
     * sanitizeElementOrder() is private — reflection over widening visibility
     * just for tests, same rationale as sanitizeInstance() above: it's the
     * one piece of logic in sanitizeSettings() worth pinning down directly,
     * here because it deliberately breaks the file's usual "fall back to the
     * existing stored value" convention (see its docblock in SettingsPage.php).
     */
    private function sanitizeElementOrder(string $raw): array
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeElementOrder');
        $method->setAccessible(true);

        return $method->invoke(null, $raw);
    }

    public function testSanitizeElementOrderAcceptsAValidNonDefaultPermutation(): void
    {
        $this->assertSame(
            ['meta', 'media', 'title', 'calendar', 'subtitle', 'excerpt'],
            $this->sanitizeElementOrder('meta,media,title,calendar,subtitle,excerpt')
        );
    }

    /**
     * A garbled element_order must snap to the hardcoded default, not to
     * whatever was previously stored — this is the one field in
     * sanitizeSettings() that intentionally doesn't fall back to $existing.
     */
    public function testSanitizeElementOrderFallsBackToDefaultOnDuplicateKey(): void
    {
        $this->assertSame(
            CardDesign::DEFAULT_ORDER,
            $this->sanitizeElementOrder('media,media,title,subtitle,excerpt,meta')
        );
    }

    public function testSanitizeElementOrderFallsBackToDefaultOnMissingKey(): void
    {
        $this->assertSame(
            CardDesign::DEFAULT_ORDER,
            $this->sanitizeElementOrder('media,title,subtitle')
        );
    }

    public function testSanitizeElementOrderFallsBackToDefaultOnUnknownKey(): void
    {
        $this->assertSame(
            CardDesign::DEFAULT_ORDER,
            $this->sanitizeElementOrder('media,title,subtitle,excerpt,meta,color')
        );
    }

    public function testSanitizeElementOrderFallsBackToDefaultOnEmptyString(): void
    {
        $this->assertSame(CardDesign::DEFAULT_ORDER, $this->sanitizeElementOrder(''));
    }

    /**
     * Any number of admin-inserted spacer-*/divider-* entries (see
     * CardDesign::SEPARATOR_TYPES) may sit anywhere alongside the six fixed
     * keys — this is what lets the Design tab offer "+ Trennlinie"/"+ Abstand".
     */
    public function testSanitizeElementOrderAcceptsInterspersedSeparators(): void
    {
        $this->assertSame(
            ['media', 'calendar', 'divider-a1b2', 'title', 'subtitle', 'spacer-c3d4', 'excerpt', 'meta'],
            $this->sanitizeElementOrder('media,calendar,divider-a1b2,title,subtitle,spacer-c3d4,excerpt,meta')
        );
    }

    /**
     * Characters outside CardDesign's expected key shape (lowercase letters,
     * digits, hyphens — see the regex in sanitizeElementOrder()) are stripped
     * before the permutation check, so one garbage entry from a tampered POST
     * doesn't invalidate an otherwise-valid, non-default order — it's simply
     * dropped, the surrounding valid order is kept as-is.
     */
    public function testSanitizeElementOrderStripsEntriesWithUnexpectedCharacters(): void
    {
        $this->assertSame(
            ['meta', 'calendar', 'title', 'subtitle', 'excerpt', 'media'],
            $this->sanitizeElementOrder('meta,calendar,title,subtitle,excerpt,media,<script>')
        );
    }
}
