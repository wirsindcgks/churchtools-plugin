<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Security\Crypto;
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

    /**
     * A full year, not half of one: the parish calendar runs on an annual cycle,
     * and a 180-day horizon silently cut off its second half - the frontend list
     * simply ended, with nothing to indicate more was coming. Pinned here so
     * changing it stays a deliberate decision rather than a drive-by edit.
     */
    public function testSanitizeSettingsDefaultsSyncDaysAheadToOneYear(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([]);

        $this->assertSame(365, $sanitized['sync_days_ahead']);
        $this->assertSame(SettingsPage::defaults()['sync_days_ahead'], $sanitized['sync_days_ahead']);
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
            ['date', 'time', 'location', 'media', 'title', 'calendar', 'subtitle', 'excerpt'],
            $this->sanitizeElementOrder('date,time,location,media,title,calendar,subtitle,excerpt')
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
            $this->sanitizeElementOrder('media,media,title,subtitle,excerpt,date,time,location')
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
     * Any number of admin-inserted spacer- or divider-prefixed entries (see
     * CardDesign::SEPARATOR_TYPES) may sit anywhere alongside the fixed keys —
     * this is what lets the Design tab offer "+ Trennlinie"/"+ Abstand".
     */
    public function testSanitizeElementOrderAcceptsInterspersedSeparators(): void
    {
        $raw = 'media,calendar,divider-a1b2,title,subtitle,spacer-c3d4,excerpt,date,time,location';

        $this->assertSame(
            [
                'media', 'calendar', 'divider-a1b2', 'title', 'subtitle',
                'spacer-c3d4', 'excerpt', 'date', 'time', 'location',
            ],
            $this->sanitizeElementOrder($raw)
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
            ['date', 'time', 'location', 'calendar', 'title', 'subtitle', 'excerpt', 'media'],
            $this->sanitizeElementOrder('date,time,location,calendar,title,subtitle,excerpt,media,<script>')
        );
    }

    public function testSanitizeSettingsDefaultsToNoHiddenElements(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([]);

        $this->assertSame([], $sanitized['hidden_elements']);
    }

    public function testSanitizeSettingsAcceptsHiddenElements(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['hidden_elements' => ['subtitle', 'excerpt']]);

        $this->assertSame(['subtitle', 'excerpt'], $sanitized['hidden_elements']);
    }

    /**
     * renderFieldVisibilityField() prints a hidden "[]" marker before the
     * checkboxes precisely so an all-unchecked submit still posts this as an
     * empty array (present, not absent) — this pins down that the empty-array
     * case actually clears a previously hidden field, rather than being
     * mistaken for "tab not submitted" and falling back to $existing.
     */
    public function testSanitizeSettingsClearsHiddenElementsOnEmptySubmit(): void
    {
        ctp_test_set_option('ctp_settings', ['hidden_elements' => ['time']]);

        $sanitized = SettingsPage::sanitizeSettings(['hidden_elements' => []]);

        $this->assertSame([], $sanitized['hidden_elements']);
    }

    public function testSanitizeSettingsKeepsExistingHiddenElementsWhenFieldAbsent(): void
    {
        ctp_test_set_option('ctp_settings', ['hidden_elements' => ['subtitle']]);

        $sanitized = SettingsPage::sanitizeSettings(['instance' => 'musterkirche']);

        $this->assertSame(['subtitle'], $sanitized['hidden_elements']);
    }

    /**
     * The pre-split key set has to survive an update without the admin
     * re-saving the Design tab: get() widens it on read, so a site that had
     * "meta" stored keeps its layout instead of snapping to the default.
     */
    public function testGetWidensStoredOrdersFromThePreSplitKeySet(): void
    {
        ctp_test_set_option('ctp_settings', [
            'element_order' => ['meta', 'media', 'calendar', 'title', 'subtitle', 'excerpt'],
            'detail_element_order' => ['media', 'calendar', 'title', 'subtitle', 'meta', 'description'],
            'hidden_elements' => ['meta'],
        ]);

        $settings = SettingsPage::get();

        $this->assertSame(
            ['date', 'time', 'location', 'media', 'calendar', 'title', 'subtitle', 'excerpt'],
            $settings['element_order']
        );
        $this->assertSame(
            ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description'],
            $settings['detail_element_order']
        );
        $this->assertSame(['date', 'time', 'location'], $settings['hidden_elements']);
    }

    public function testSanitizeSettingsDefaultsButtonColorToDisabled(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([]);

        $this->assertFalse($sanitized['button_color_enabled']);
        $this->assertSame('#111827', $sanitized['button_color']);
    }

    public function testSanitizeSettingsAcceptsAButtonColor(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([
            'button_color_enabled' => '1',
            'button_color' => '#FF8800',
        ]);

        $this->assertTrue($sanitized['button_color_enabled']);
        $this->assertSame('#FF8800', $sanitized['button_color']);
    }

    public function testSanitizeSettingsRejectsAMalformedButtonColor(): void
    {
        ctp_test_set_option('ctp_settings', ['button_color' => '#123456']);

        $sanitized = SettingsPage::sanitizeSettings(['button_color' => 'rgb(1,2,3)']);

        $this->assertSame('#123456', $sanitized['button_color']);
    }

    public function testSanitizeSettingsDefaultsMediaAspectRatioToWide(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([]);

        $this->assertSame('wide', $sanitized['media_aspect_ratio']);
    }

    public function testSanitizeSettingsAcceptsValidMediaAspectRatio(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['media_aspect_ratio' => 'square']);

        $this->assertSame('square', $sanitized['media_aspect_ratio']);
    }

    public function testSanitizeSettingsFallsBackToExistingMediaAspectRatioWhenInvalid(): void
    {
        ctp_test_set_option('ctp_settings', ['media_aspect_ratio' => 'square']);

        $sanitized = SettingsPage::sanitizeSettings(['media_aspect_ratio' => 'panoramic']);

        $this->assertSame('square', $sanitized['media_aspect_ratio']);
    }

    public function testSanitizeSettingsAcceptsAccentColorEnabled(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['accent_color_enabled' => '1']);

        $this->assertTrue($sanitized['accent_color_enabled']);
    }

    /**
     * Same hidden-input trick as keep_data_on_uninstall — the checkbox posts
     * "0" via a preceding hidden field when unchecked, so this must actually
     * turn the setting off rather than being mistaken for "tab not submitted".
     */
    public function testSanitizeSettingsDisablesAccentColorWhenUnchecked(): void
    {
        ctp_test_set_option('ctp_settings', ['accent_color_enabled' => true]);

        $sanitized = SettingsPage::sanitizeSettings(['accent_color_enabled' => '0']);

        $this->assertFalse($sanitized['accent_color_enabled']);
    }

    public function testSanitizeSettingsAcceptsValidAccentColor(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['accent_color' => '#ff8800']);

        $this->assertSame('#ff8800', $sanitized['accent_color']);
    }

    public function testSanitizeSettingsFallsBackToExistingAccentColorWhenInvalid(): void
    {
        ctp_test_set_option('ctp_settings', ['accent_color' => '#ff8800']);

        $sanitized = SettingsPage::sanitizeSettings(['accent_color' => 'not-a-color']);

        $this->assertSame('#ff8800', $sanitized['accent_color']);
    }

    /**
     * WordPress ruft den Sanitizer beim allerersten Schreiben einer Option
     * zweimal auf: update_option() sanitisiert, stellt fest, dass es die
     * Option noch nicht gibt, und reicht an add_option() weiter - das
     * sanitisiert erneut (wp-includes/option.php). Der zweite Durchlauf
     * bekommt damit die Ausgabe des ersten zu sehen, hier also einen bereits
     * verschluesselten API-Key.
     *
     * Ohne die Praefix-Abfrage in apiKeyToStore() lag der Token danach doppelt
     * verschluesselt in der Datenbank und jede Anfrage an ChurchTools
     * scheiterte mit „401: No valid token“ - einmal pro Installation, bei der
     * ersten Einrichtung, waehrend „Verbindung testen“ gruen blieb, weil der
     * Test den getippten Wert nimmt und nicht den gespeicherten.
     */
    public function testFirstSaveDoesNotEncryptTheApiKeyTwice(): void
    {
        $first = SettingsPage::sanitizeSettings(['api_key' => 'ein-frisch-eingetragener-token']);
        $second = SettingsPage::sanitizeSettings($first);

        $this->assertTrue(Crypto::isCiphertext($second['api_key']));
        $this->assertSame('ein-frisch-eingetragener-token', Crypto::decrypt($second['api_key']));
    }

    /**
     * Derselbe doppelte Aufruf traf auch die beiden Reihenfolge-Felder: Sie
     * kommen als kommagetrennter String herein und gehen als Liste heraus,
     * die der zweite Durchlauf per (string) zu "Array" machte - eine
     * PHP-Warnung, und die gerade eingestellte Anordnung schnappte auf die
     * Standardanordnung zurueck (siehe orderInput()).
     */
    public function testFirstSaveKeepsTheElementOrder(): void
    {
        $order = 'date,time,location,media,title,calendar,subtitle,excerpt';

        $first = SettingsPage::sanitizeSettings(['element_order' => $order]);
        $second = SettingsPage::sanitizeSettings($first);

        $this->assertSame(explode(',', $order), $second['element_order']);
        $this->assertNotSame(CardDesign::DEFAULT_ORDER, $second['element_order']);
    }

    /**
     * Die Gegenprobe: Das Feld wird nie mit dem gespeicherten Token
     * vorbefuellt (siehe renderApiKeyField()), ein leeres Feld heisst also
     * „unveraendert lassen“ und darf ihn nicht loeschen.
     */
    public function testEmptyApiKeyFieldKeepsTheStoredKey(): void
    {
        $stored = Crypto::encrypt('bereits-gespeicherter-token');
        ctp_test_set_option('ctp_settings', ['api_key' => $stored]);

        $sanitized = SettingsPage::sanitizeSettings(['instance' => 'musterkirche']);

        $this->assertSame($stored, $sanitized['api_key']);
    }

    /**
     * Der Bestand aus der Zeit vor dem Praefix: ein doppelt verschluesselter
     * Key, wie ihn das erste Speichern hinterlassen hat. Er wird beim Lesen
     * ausgepackt, damit niemand deswegen seinen Token neu eintragen muss -
     * und darf dabei nicht als „laesst sich nicht entschluesseln“ gelten
     * (diese Meldung gehoert der AUTH_KEY-Rotation).
     */
    public function testDoubleEncryptedKeyFromBeforeTheFixIsUnwrappedOnRead(): void
    {
        ctp_test_set_option('ctp_settings', [
            'api_key' => ctp_test_legacy_encrypt(ctp_test_legacy_encrypt('token-aus-der-kaputten-zeit')),
        ]);

        $this->assertSame('token-aus-der-kaputten-zeit', SettingsPage::getDecryptedApiKey());
        $this->assertFalse(SettingsPage::apiKeyDecryptionFailed());
    }

    public function testSinglyEncryptedKeyIsReadUnchanged(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => Crypto::encrypt('ganz-normaler-token')]);

        $this->assertSame('ganz-normaler-token', SettingsPage::getDecryptedApiKey());
        $this->assertFalse(SettingsPage::apiKeyDecryptionFailed());
    }

    /**
     * Ein Wert, der sich mit dem aktuellen AUTH_KEY nicht mehr entschluesseln
     * laesst, muss weiterhin als solcher gemeldet werden - das Auspacken oben
     * darf diesen Fall nicht verschlucken.
     */
    public function testUndecryptableKeyIsStillReportedAsBroken(): void
    {
        ctp_test_set_option('ctp_settings', ['api_key' => base64_encode(random_bytes(48))]);

        $this->assertSame('', SettingsPage::getDecryptedApiKey());
        $this->assertTrue(SettingsPage::apiKeyDecryptionFailed());
    }
}
