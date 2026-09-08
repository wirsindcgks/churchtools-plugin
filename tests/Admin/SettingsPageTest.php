<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Frontend\DesignPreset;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;
use ChurchToolsPlugin\Sync\RoomLookup;
use ReflectionMethod;

final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        $GLOBALS['ctp_test_posts'] = [];
    }

    /**
     * Jeder Tab braucht sein Symbol: Die Navigation rendert
     * `dashicons-<icon>` aus tabIcons() zum Schlüssel aus tabs(), und ein
     * fehlender Eintrag ergibt kein fehlendes Symbol, sondern ein leeres
     * Kästchen an dessen Stelle. Beim Anlegen des Tabs „Einbinden" war genau
     * das die Stelle, die man vergisst.
     */
    public function testEveryTabHasAnIcon(): void
    {
        $tabs = array_keys($this->invokePrivate('tabs'));
        $icons = $this->invokePrivate('tabIcons');

        $this->assertContains('embed', $tabs, 'Der Tab „Einbinden" trägt die Shortcode-Referenz.');
        $this->assertSame([], array_diff($tabs, array_keys($icons)), 'Tabs ohne Symbol.');
        $this->assertSame([], array_diff(array_keys($icons), $tabs), 'Symbole ohne Tab.');
    }

    private function invokePrivate(string $method): array
    {
        return (new ReflectionMethod(SettingsPage::class, $method))->invoke(null);
    }

    /**
     * Jeder Bereich des Design-Tabs muss auch Felder haben: Die Navigation
     * baut sich aus designSections(), die Inhalte holt renderPage() dagegen
     * aus `PAGE_SLUG . '_design_' . $section`. Ein Bereich ohne passende
     * Settings-Seite ergibt keinen Fehler, sondern einen Reiter, der auf eine
     * leere Seite fuehrt — genau die Sorte Luecke, die beim Anlegen des
     * naechsten Bereichs entsteht.
     */
    public function testEveryDesignSectionHasFieldsOfItsOwn(): void
    {
        ctp_test_reset_settings();
        (new SettingsPage())->registerSettings();

        foreach (array_keys($this->invokePrivate('designSections')) as $section) {
            $this->assertNotSame(
                [],
                ctp_test_settings_fields('churchtools-plugin_design_' . $section),
                sprintf('Der Bereich „%s" hat keine Felder.', $section)
            );
        }
    }

    /**
     * Zwei Felder des Design-Tabs hiessen beide „Reihenfolge" — einmal fuer
     * die Kachel, einmal fuer die Detailansicht. Im Fliesstext einer langen
     * Seite war nicht zu sehen, welches welches ist, und der Nutzerbefund vom
     * 2026-09-08 („man findet nichts mehr") hatte darin seine konkreteste
     * Ursache. Die Bereiche trennen sie inzwischen, aber die Namen muessen
     * auch fuer sich stehen: Wer im Browser sucht, findet beide.
     */
    public function testNoTwoDesignFieldsShareALabel(): void
    {
        ctp_test_reset_settings();
        (new SettingsPage())->registerSettings();

        $labels = [];
        foreach (array_keys($this->invokePrivate('designSections')) as $section) {
            foreach (ctp_test_settings_fields('churchtools-plugin_design_' . $section) as $field) {
                $labels[] = $field['title'];
            }
        }

        $this->assertSame(
            [],
            array_keys(array_filter(array_count_values($labels), static fn (int $count): bool => $count > 1)),
            'Zwei Felder des Design-Tabs tragen denselben Namen.'
        );
    }

    /**
     * „Keine" blendet den Aufbau der Detailansicht aus (admin-design.js) —
     * und solange das Klickverhalten im selben Abschnitt stand, verschwanden
     * die Auswahlknoepfe mit ihm. Auf der eigenen Bereichsseite waere danach
     * nichts uebrig, mit dem man zurueckschaltet. Die beiden gehoeren deshalb
     * in getrennte Abschnitte; das Skript blendet nur den zweiten aus.
     */
    public function testTheClickBehaviourSitsApartFromWhatItHides(): void
    {
        ctp_test_reset_settings();
        (new SettingsPage())->registerSettings();

        $sections = [];
        foreach (ctp_test_settings_fields('churchtools-plugin_design_detail') as $field) {
            $sections[$field['id']] = $field['section'];
        }

        $this->assertArrayHasKey('click_behavior', $sections);
        $this->assertArrayHasKey('detail_element_order', $sections);
        $this->assertNotSame(
            $sections['detail_element_order'],
            $sections['click_behavior'],
            'Das Klickverhalten steht im selben Abschnitt wie das, was es ausblendet.'
        );
    }

    /**
     * `section` kommt wie `tab` aus der Adresse und wird genauso behandelt:
     * Was nicht in der Liste steht, faellt auf den ersten Bereich zurueck,
     * statt eine leere Seite zu rendern.
     */
    public function testAnUnknownDesignSectionFallsBackToTheStyleSection(): void
    {
        $_GET['section'] = 'gibt-es-nicht';
        $this->assertSame('style', $this->currentDesignSection());

        $_GET['section'] = 'detail';
        $this->assertSame('detail', $this->currentDesignSection());

        unset($_GET['section']);
        $this->assertSame('style', $this->currentDesignSection());
    }

    private function currentDesignSection(): string
    {
        return (new ReflectionMethod(SettingsPage::class, 'currentDesignSection'))->invoke(null);
    }

    /**
     * `hidden` muss im Backend auch auf Ueberschriften wirken. wp-admins
     * common.css setzt `h1, h2, h3, h4, h5, h6 { display: block }`, und eine
     * Autorenregel schlaegt die `[hidden]`-Regel des Browsers — die
     * ausgeblendete Ueberschrift „Aufbau der Detailansicht" blieb dadurch als
     * Titel ohne Inhalt stehen (im Browser nachgemessen, nicht vermutet).
     */
    public function testHiddenAlsoWorksOnHeadingsInTheAdmin(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.ctp-admin \[hidden\]\s*\{[^}]*display:\s*none/',
            $css,
            'Ohne diese Regel bleibt eine mit `hidden` versteckte Ueberschrift im Backend stehen.'
        );
    }

    /**
     * Schliessen-Kreuz und Zurueck-Knopf der Detailvorschau erben ihre Optik
     * aus frontend.css — und damit auch zwei Regeln, die dort richtig sind
     * und hier stoeren. Beide sind im Browser gemessen worden, nicht
     * vermutet:
     *
     *   - `.ctp-events .ctp-events__back { display: inline-flex }` ist gleich
     *     spezifisch wie die allgemeine [hidden]-Regel und wird spaeter
     *     geladen: Der Zurueck-Knopf blieb auch bei „Popup" stehen.
     *   - `.ctp-events__detail > * { flex: 0 0 100% }` gab ihm die volle
     *     Innenbreite als *Inhalts*breite; mit Polsterung und Rahmen wurden
     *     daraus 356px in 320px, also 36px Ueberstand.
     *
     * Beide Gegenregeln brauchen den `.ctp-admin`-Vorsatz als Gewicht — ohne
     * ihn verlieren sie gegen frontend.css.
     */
    public function testThePreviewChromeOutranksTheFrontendStylesheet(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.ctp-admin \.ctp-design-preview-chrome\[hidden\]\s*\{[^}]*display:\s*none/',
            $css,
            'Ohne diese Regel steht der Zurueck-Knopf auch im Popup-Modus in der Vorschau.'
        );
        $this->assertMatchesRegularExpression(
            '/\.ctp-admin \.ctp-design-preview-chrome\s*\{[^}]*flex:\s*0 0 auto/',
            $css,
            'Ohne diese Regel fuellt der Zurueck-Knopf die Zeile und laeuft um seine Polsterung ueber.'
        );
    }

    /**
     * Die vier Stil-Karten stehen seit 1.19.0 in einer halbbreiten Spalte des
     * Design-Rasters. `auto-fit` verkleinert die Spaltenzahl nur bei
     * bestimmter Breite — in der Zelle einer form-table ohne Breitenangabe
     * blieben es vier Spalten zu 190px: gemessen 796px Raster in einem 600px
     * breiten Panel, die vierte Karte lag unter der Vorschau. Beide Regeln
     * gehoeren zusammen, eine allein reicht nicht.
     */
    public function testThePresetCardsCannotOutgrowTheirColumn(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.ctp-design-layout \.ctp-preset-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,/',
            $css,
            'Ohne eine einzelne minmax(0, 1fr)-Spalte bleibt die min-content-Breite des Rasters zu gross.'
        );
        $this->assertMatchesRegularExpression(
            '/\.ctp-design-layout \.ctp-panel \.form-table\s*\{[^}]*width:\s*100%/',
            $css,
            'Ohne Breitenangabe waechst die Tabelle auf die max-content-Breite ihres Inhalts.'
        );
    }

    /**
     * Die Elternseite der Termin-Adressen muss eine veröffentlichte *Seite*
     * sein. Alles andere hätte entweder keine öffentliche Adresse (Entwurf)
     * oder eine, die WordPress selbst schon belegt (Beitrag) — und die
     * Einstellung würde still etwas anderes bedeuten als das, was sie anzeigt.
     */
    public function testDetailPageIdAcceptsAPublishedPage(): void
    {
        ctp_test_set_post(43, 'page', 'publish');

        $this->assertSame(43, SettingsPage::sanitizeSettings(['detail_page_id' => '43'])['detail_page_id']);
    }

    /**
     * @dataProvider unusablePageProvider
     */
    public function testDetailPageIdFallsBackToNoneForAnythingElse(string $type, string $status, string $why): void
    {
        ctp_test_set_post(43, $type, $status);

        $this->assertSame(0, SettingsPage::sanitizeSettings(['detail_page_id' => '43'])['detail_page_id'], $why);
    }

    public function unusablePageProvider(): array
    {
        return [
            'Entwurf' => ['page', 'draft', 'Ein Entwurf hat keine öffentliche Adresse.'],
            'Papierkorb' => ['page', 'trash', 'Eine gelöschte Seite erst recht nicht.'],
            'Beitrag' => ['post', 'publish', 'Ein Beitrag hat schon eine eigene Adressstruktur.'],
        ];
    }

    public function testDetailPageIdFallsBackToNoneForAnIdThatDoesNotExist(): void
    {
        $this->assertSame(0, SettingsPage::sanitizeSettings(['detail_page_id' => '999'])['detail_page_id']);
    }

    /**
     * Die Voreinstellung ist „keine Elternseite": Eine bestehende Installation
     * ändert ihre Termin-Adressen nicht von selbst, nur weil aktualisiert wurde.
     */
    public function testWithoutADetailPageTheSettingStaysAtNone(): void
    {
        $this->assertSame(0, SettingsPage::defaults()['detail_page_id']);
        $this->assertSame(0, SettingsPage::sanitizeSettings([])['detail_page_id']);
    }

    /**
     * sanitizeInstance() is private — reflection over widening its visibility just
     * for tests, since normalizing user-typed instance/URL input is exactly the
     * kind of small pure logic worth pinning down directly.
     */
    private function sanitizeInstance(string $raw): string
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeInstance');

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
        $this->assertSame('musterkirche', $this->sanitizeInstance('muster kirche!'));
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

    public function testSanitizeSettingsAcceptsValidDesignPreset(): void
    {
        $sanitized = SettingsPage::sanitizeSettings(['design_preset' => 'warm']);

        $this->assertSame('warm', $sanitized['design_preset']);
    }

    public function testSanitizeSettingsFallsBackToExistingDesignPresetWhenInvalid(): void
    {
        ctp_test_set_option('ctp_settings', ['design_preset' => 'ruhig']);

        $sanitized = SettingsPage::sanitizeSettings(['design_preset' => 'barock']);

        $this->assertSame('ruhig', $sanitized['design_preset']);
    }

    /**
     * Eine Bestandsseite hat den Schlüssel gar nicht gespeichert — sie muss
     * auf dem Standard landen, nicht auf einem leeren Wert, der später als
     * Klassenname im Markup stünde.
     */
    public function testDesignPresetDefaultsToStandardForSitesThatNeverSavedIt(): void
    {
        ctp_test_set_option('ctp_settings', ['corner_style' => 'square']);

        $this->assertSame(DesignPreset::DEFAULT_PRESET, SettingsPage::get()['design_preset']);
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
        // „share" kommt aus derselben Verbreiterung mit (DetailDesign::upgradeOrder()),
        // und aus demselben Grund: Ohne ihn wäre die gelesene Reihenfolge
        // unvollständig und fiele auf die Standardanordnung zurück.
        $this->assertSame(
            ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description', 'share', 'ics'],
            $settings['detail_element_order']
        );
        $this->assertSame(['date', 'time', 'location'], $settings['hidden_elements']);
    }

    /**
     * Der Teilen-Knopf ist ausdrücklich einzuschalten – eine Bestandsseite, die
     * nur aktualisiert, bekommt ihn nicht. Dass er in der Reihenfolge steht,
     * heißt also noch nicht, dass er erscheint.
     */
    public function testShareButtonIsOffUntilItIsSwitchedOn(): void
    {
        $this->assertFalse(SettingsPage::defaults()['detail_share_enabled']);
        $this->assertFalse(SettingsPage::sanitizeSettings([])['detail_share_enabled']);
        $this->assertTrue(SettingsPage::sanitizeSettings(['detail_share_enabled' => '1'])['detail_share_enabled']);
    }

    /**
     * Ein Formular, das vor der Erweiterung des Schlüsselsatzes gerendert
     * wurde – ein alter Browser-Tab, eine zwischengespeicherte Admin-Seite –,
     * schickt die Reihenfolge ohne „share" ab. Ohne die Verbreiterung im
     * Sanitizer schnappte das auf die Standardanordnung, und der Betreiber
     * verlöre seine eingestellte Anordnung beim Speichern einer ganz anderen
     * Einstellung.
     */
    public function testStaleOrderSubmitKeepsItsArrangementInsteadOfSnappingToTheDefault(): void
    {
        $sanitized = SettingsPage::sanitizeSettings([
            'detail_element_order' => 'description,media,title,calendar,location,time,date,subtitle',
        ]);

        $this->assertSame(
            ['description', 'media', 'title', 'calendar', 'location', 'time', 'date', 'subtitle', 'share', 'ics'],
            $sanitized['detail_element_order']
        );
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

    /**
     * `isPublic` ist ChurchTools' eigene Angabe zum Kalender und muss den Weg
     * in die Einstellungen finden - ohne sie kann der Hinweis im Tab
     * „Kalender" nicht entstehen.
     */
    public function testMergeCalendarsCarriesTheChurchToolsPublicFlag(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'mergeCalendars');

        $merged = $method->invoke(null, [], [
            ['id' => 7, 'name' => 'Intern', 'isPublic' => false],
            ['id' => 8, 'name' => 'Gottesdienste', 'isPublic' => true],
        ]);

        $this->assertFalse($merged[7]['is_public']);
        $this->assertTrue($merged[8]['is_public']);
    }

    /**
     * Fehlt das Feld ganz (aeltere Instanz, geaenderte Antwortform), gilt der
     * Kalender als oeffentlich. Andersherum stuende nach dem naechsten
     * „Kalender laden" auf jedem einzelnen Kalender eine Warnung - und eine
     * Warnung, die immer erscheint, liest bald niemand mehr.
     */
    public function testACalendarWithoutThePublicFlagCountsAsPublic(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'mergeCalendars');

        $merged = $method->invoke(null, [], [['id' => 9, 'name' => 'Alt']]);

        $this->assertTrue($merged[9]['is_public']);
    }

    /**
     * Das Speichern des Formulars darf die Angabe nicht verlieren: Es gibt
     * kein Feld dafuer, sie kommt also nur aus $existing.
     */
    public function testSanitizeCalendarsKeepsThePublicFlagAcrossASave(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeCalendars');

        $existing = [
            7 => ['name' => 'Intern', 'enabled' => true, 'color' => '#123456', 'default_color' => '#123456', 'is_public' => false],
        ];

        $saved = $method->invoke(null, [7 => ['enabled' => '1', 'color' => '#654321']], $existing);

        $this->assertFalse($saved[7]['is_public']);
    }

    /**
     * Gemeldet wird nur, was auch tatsaechlich veroeffentlicht wird: ein
     * angehakter Kalender. Ein nicht angehakter, nicht oeffentlicher Kalender
     * ist kein Widerspruch, sondern der Normalfall.
     */
    public function testOnlyEnabledNonPublicCalendarsAreReported(): void
    {
        ctp_test_set_option('ctp_settings', ['calendars' => [
            7 => ['name' => 'Intern aktiv', 'enabled' => true, 'is_public' => false],
            8 => ['name' => 'Intern inaktiv', 'enabled' => false, 'is_public' => false],
            9 => ['name' => 'Oeffentlich aktiv', 'enabled' => true, 'is_public' => true],
            10 => ['name' => 'Ohne Angabe', 'enabled' => true],
        ]]);

        $this->assertSame([7 => 'Intern aktiv'], SettingsPage::nonPublicEnabledCalendars());
    }

    /**
     * Gegenstaende sind nie eine Ortsangabe. Erkannt wird das am Typ und nicht
     * am Namen - die Liste soll kurz sein, ohne dass jemand Technik erst
     * wegsehen muss.
     */
    public function testMergeResourcesKeepsOnlyRooms(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'mergeResources');

        $merged = $method->invoke(null, [], [
            ['id' => 23, 'name' => 'Grosser Saal', 'resourceTypeId' => 2, 'sortKey' => 5],
            ['id' => 51, 'name' => 'Beamer', 'resourceTypeId' => 1, 'sortKey' => 5],
        ], [2]);

        $this->assertSame([23], array_keys($merged));
        $this->assertSame('Grosser Saal', $merged[23]['name']);
        $this->assertSame(5, $merged[23]['sort_key']);
    }

    /**
     * Eine leere Typenliste darf nicht jeden Raum aussortieren. Sie war der
     * zweite Weg in den Verlust der Raumauswahl: Fand refreshResources() keinen
     * Raumtyp, sollte „alle Typen" gelten - die Ersatzliste entstand aber aus
     * derselben leeren Antwort und erlaubte deshalb nichts.
     */
    public function testAnEmptyTypeListKeepsEveryRoomInsteadOfNone(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'mergeResources');

        $merged = $method->invoke(null, [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
        ], [
            ['id' => 23, 'name' => 'Grosser Saal', 'resourceTypeId' => 2, 'sortKey' => 5],
        ], []);

        $this->assertSame([23], array_keys($merged));
        $this->assertTrue($merged[23]['enabled']);
    }

    /**
     * Der Kern des Befunds vom 2026-09-08 („Bei dem Update ging wohl die
     * Raumauswahl verloren"): Eine einzige leere Antwort loeschte die ganze
     * Auswahl, und die naechste vollstaendige brachte die Raeume unangehakt
     * zurueck. Der Haken steht nirgends sonst - er ist nicht
     * wiederherstellbar, sondern nur von Hand neu zu setzen.
     */
    public function testAnEmptyAnswerLeavesTheTickedRoomsAlone(): void
    {
        ctp_test_reset_http();
        ctp_test_set_option('ctp_settings', ['resources' => [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
        ]]);

        ctp_test_queue_http([]);
        $result = SettingsPage::refreshResources(new Client('https://example.church.tools', 'token'));

        $this->assertSame('empty', $result['status']);
        $this->assertFalse($result['changed']);
        $this->assertSame(
            [23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5]],
            SettingsPage::get()['resources']
        );
    }

    /**
     * Und der Zeitstempel bleibt dabei stehen. Er ist die einzige Anzeige, an
     * der jemand die veraltete Liste erkennen kann (siehe den Tab „Raeume");
     * ruecke er trotz nicht uebernommener Antwort vor, meldete er das Gegenteil
     * dessen, was geschehen ist.
     */
    public function testAnEmptyAnswerDoesNotRefreshTheFetchedTimestamp(): void
    {
        ctp_test_reset_http();
        ctp_test_set_option('ctp_settings', ['resources' => [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
        ]]);
        ctp_test_set_option('ctp_resources_fetched', '2026-09-01 08:00:00');

        ctp_test_queue_http([]);
        SettingsPage::refreshResources(new Client('https://example.church.tools', 'token'));

        $this->assertSame('2026-09-01 08:00:00', get_option('ctp_resources_fetched'));
    }

    /**
     * Der Schutz gilt nur dem Alles-oder-nichts-Fall. Ist noch gar keine Liste
     * gespeichert, ist die leere Antwort der Normalzustand jeder Installation,
     * deren API-Key keine Freigabe fuer Ressourcen hat - daraus einen Fehler zu
     * machen, waere Laerm ueber eine Abwesenheit.
     */
    public function testAnEmptyAnswerIsNormalWhenNothingIsStoredYet(): void
    {
        ctp_test_reset_http();
        ctp_test_queue_http([]);

        $result = SettingsPage::refreshResources(new Client('https://example.church.tools', 'token'));

        $this->assertSame('updated', $result['status']);
        $this->assertSame(0, $result['count']);
    }

    /**
     * Und die vollstaendige Antwort kommt weiterhin an: Ein neuer Raum taucht
     * unangehakt auf, ein in ChurchTools geloeschter faellt samt Haken heraus.
     * Ohne diesen Test hiesse „schuetzt die Auswahl" auch „schreibt nie wieder".
     */
    public function testAFullAnswerStillReplacesTheList(): void
    {
        ctp_test_reset_http();
        ctp_test_set_option('ctp_settings', ['resources' => [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
            99 => ['name' => 'Alter Raum', 'enabled' => true, 'sort_key' => 9],
        ]]);

        ctp_test_queue_http([
            'resourceTypes' => [['id' => 2, 'name' => 'resource.type.room']],
            'resources' => [
                ['id' => 23, 'name' => 'Grosser Saal', 'resourceTypeId' => 2, 'sortKey' => 5],
                ['id' => 24, 'name' => 'Neuer Raum', 'resourceTypeId' => 2, 'sortKey' => 6],
                ['id' => 51, 'name' => 'Beamer', 'resourceTypeId' => 1, 'sortKey' => 1],
            ],
        ]);

        $result = SettingsPage::refreshResources(new Client('https://example.church.tools', 'token'));
        $gespeichert = SettingsPage::get()['resources'];

        $this->assertSame('updated', $result['status']);
        $this->assertTrue($result['changed']);
        $this->assertSame([23, 24], array_keys($gespeichert));
        $this->assertTrue($gespeichert[23]['enabled']);
        $this->assertFalse($gespeichert[24]['enabled']);
    }

    /**
     * Der Haken gehoert dem Betreiber, Name und Sortierschluessel gehoeren
     * ChurchTools: Ein dort umbenannter Raum heisst nach dem Abgleich auch hier
     * neu, ohne seinen Haken zu verlieren.
     */
    public function testMergeResourcesKeepsTheTickAndTakesTheNewName(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'mergeResources');

        $merged = $method->invoke(null, [
            23 => ['name' => 'Alter Name', 'enabled' => true, 'sort_key' => 99],
        ], [
            ['id' => 23, 'name' => 'Neuer Name', 'resourceTypeId' => 2, 'sortKey' => 5],
        ], [2]);

        $this->assertTrue($merged[23]['enabled']);
        $this->assertSame('Neuer Name', $merged[23]['name']);
        $this->assertSame(5, $merged[23]['sort_key']);
    }

    /**
     * Wie bei den Kalendern: Nur bekannte IDs kommen durch, und Name wie
     * Sortierschluessel stammen aus $existing statt aus dem Formular - sie sind
     * keine Eingabefelder.
     */
    public function testSanitizeResourcesOnlyAcceptsKnownIdsAndKeepsTheirNames(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeResources');

        $sanitized = $method->invoke(null, [
            23 => ['enabled' => '1', 'name' => 'Untergeschobener Name'],
            99 => ['enabled' => '1'],
        ], [
            23 => ['name' => 'Grosser Saal', 'enabled' => false, 'sort_key' => 5],
        ]);

        $this->assertSame([23], array_keys($sanitized));
        $this->assertTrue($sanitized[23]['enabled']);
        $this->assertSame('Grosser Saal', $sanitized[23]['name']);
        $this->assertSame(5, $sanitized[23]['sort_key']);
    }

    /**
     * Ein abgehakter Raum verschwindet aus der Auswahl - ohne diese Zeile
     * fragte der Sync weiterhin dessen Buchungen ab.
     */
    public function testOnlyTickedResourcesCount(): void
    {
        ctp_test_set_option('ctp_settings', ['resources' => [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
            24 => ['name' => 'Foyer', 'enabled' => false, 'sort_key' => 10],
        ]]);

        $this->assertSame([23], SettingsPage::enabledResourceIds());
    }

    /**
     * Der Normalzustand jeder Installation, die diese Funktion nicht benutzt.
     * Er entscheidet mehr als eine leere Liste: SyncEngine::lookUpRooms() fragt
     * dann gar nicht erst nach Buchungen.
     */
    public function testWithoutAnyResourcesTheSelectionIsEmpty(): void
    {
        ctp_test_set_option('ctp_settings', []);

        $this->assertSame([], SettingsPage::enabledResourceIds());
    }

    /**
     * Ein leeres Kaestchen sendet nichts. Der Reiter stellt deshalb jedem ein
     * verstecktes `enabled=0` voran - ohne das waere das Abwaehlen des letzten
     * Raums nicht speicherbar, weil `resources` dann ganz fehlte und
     * sanitizeSettings() die alten Haken unveraendert weitertruege.
     */
    public function testUntickingEveryRoomIsSaved(): void
    {
        $method = new ReflectionMethod(SettingsPage::class, 'sanitizeResources');

        $sanitized = $method->invoke(null, [
            23 => ['enabled' => '0'],
            24 => ['enabled' => '0'],
        ], [
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
            24 => ['name' => 'Foyer', 'enabled' => true, 'sort_key' => 10],
        ]);

        $this->assertFalse($sanitized[23]['enabled']);
        $this->assertFalse($sanitized[24]['enabled']);
    }

    /**
     * Die Auswahlreihenfolge ist im Modus „alle Raeume nennen" zugleich die
     * Anzeigereihenfolge, deshalb kommt sie in ChurchTools' eigener Ordnung.
     */
    public function testEnabledResourcesComeInChurchToolsOwnOrder(): void
    {
        ctp_test_set_option('ctp_settings', ['resources' => [
            25 => ['name' => 'Kleiner Raum', 'enabled' => true, 'sort_key' => 29],
            23 => ['name' => 'Grosser Saal', 'enabled' => true, 'sort_key' => 5],
            24 => ['name' => 'Foyer', 'enabled' => true, 'sort_key' => 10],
        ]]);

        $this->assertSame([23, 24, 25], SettingsPage::enabledResourceIds());
    }

    /**
     * Aus dem Kaestchen von 1.12.0 ist eine Auswahl aus drei Stellungen
     * geworden. Wer damals streng eingestellt war, bleibt es.
     */
    public function testTheOldExclusiveCheckboxStillDecidesWhenNoModeIsStored(): void
    {
        ctp_test_set_option('ctp_settings', ['rooms_exclusive' => true]);
        $this->assertSame(RoomLookup::MODE_EXCLUSIVE, SettingsPage::roomsMode());

        ctp_test_set_option('ctp_settings', ['rooms_exclusive' => false]);
        $this->assertSame(RoomLookup::MODE_SINGLE, SettingsPage::roomsMode());
    }

    public function testAStoredModeWinsAndNonsenseFallsBackToTheDefault(): void
    {
        ctp_test_set_option('ctp_settings', ['rooms_mode' => RoomLookup::MODE_ALL]);
        $this->assertSame(RoomLookup::MODE_ALL, SettingsPage::roomsMode());

        ctp_test_set_option('ctp_settings', ['rooms_mode' => 'ausgedacht']);
        $this->assertSame(RoomLookup::MODE_SINGLE, SettingsPage::roomsMode());
    }
}
