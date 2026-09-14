<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Groups;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Groups\GroupSettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GroupSettingsTest extends TestCase
{
    private const HOMEPAGES = [
        9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => false],
        6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => true],
    ];

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_http();
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => self::HOMEPAGES, 'sync_interval' => 'daily']);
    }

    /** Aus dem Formular kommt nur der Haken - Name und Hash nie. */
    public function testSanitizeTakesOnlyTheTickFromTheForm(): void
    {
        $saved = GroupSettings::sanitize(['homepages' => [
            9 => ['enabled' => '1', 'hash' => '../../whoami', 'name' => 'Umbenannt'],
            6 => ['enabled' => '0'],
            77 => ['enabled' => '1'],
        ]]);

        $this->assertSame([
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => false],
        ], $saved['homepages']);
    }

    /** Ein fehlender Schluessel heisst „nicht abgeschickt", nicht „leeren". */
    public function testSanitizeKeepsWhatWasNotSubmitted(): void
    {
        $this->assertSame(
            ['homepages' => self::HOMEPAGES, 'sync_interval' => 'daily'],
            GroupSettings::sanitize(null)
        );
    }

    public function testSanitizeAcceptsWeeklyAndRejectsUnknownIntervals(): void
    {
        $this->assertSame('weekly', GroupSettings::sanitize(['sync_interval' => 'weekly'])['sync_interval']);
        $this->assertSame('daily', GroupSettings::sanitize(['sync_interval' => 'monthly'])['sync_interval']);
    }

    /**
     * Beim ersten Speichern laeuft der Sanitizer zweimal, der zweite Durchlauf
     * bekommt die Ausgabe des ersten.
     */
    public function testSanitizeIsStableWhenRunOnItsOwnOutput(): void
    {
        $first = GroupSettings::sanitize(['homepages' => [9 => ['enabled' => '1']], 'sync_interval' => 'weekly']);
        ctp_test_set_option(GroupSettings::OPTION_KEY, $first);

        $this->assertSame($first, GroupSettings::sanitize($first));
    }

    /** Fremder Inhalt in der Option erreicht den Reiter nicht als halbe Eintraege. */
    public function testGetDropsMalformedHomepageEntries(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['enabled' => '1'],
            'x' => ['name' => 'Ohne ID', 'hash' => 'a'],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => '1'],
        ], 'sync_interval' => 'minutely']);

        $settings = GroupSettings::get();

        $this->assertSame([6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => true]], $settings['homepages']);
        $this->assertSame('daily', $settings['sync_interval']);
    }

    public function testResolveHomepageByIdOrNameAmongEnabledOnly(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => true],
            1 => ['name' => 'Hauskreise', 'hash' => 'Hk1', 'enabled' => false],
        ]]);

        $this->assertSame(9, GroupSettings::resolveHomepageId('9'));
        $this->assertSame(6, GroupSettings::resolveHomepageId(' mitarbeit '));
        $this->assertNull(GroupSettings::resolveHomepageId('Hauskreise'), 'Eine abgewaehlte Homepage ist keine Hintertuer.');
        $this->assertNull(GroupSettings::resolveHomepageId('1'));
        $this->assertNull(GroupSettings::resolveHomepageId(''), 'Bei zwei angehakten waere jede Wahl geraten.');
    }

    public function testAHomepageNamedLikeANumberIsFoundByName(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => '2026', 'hash' => 'AbC123', 'enabled' => true],
        ]]);

        $this->assertSame(9, GroupSettings::resolveHomepageId('2026'));
    }

    public function testWithoutAReferenceTheOnlyEnabledHomepageIsUsed(): void
    {
        $this->assertSame(6, GroupSettings::resolveHomepageId(''));
    }

    public function testTickingTheFirstHomepageReschedulesAndSyncsNow(): void
    {
        $this->assertSame(
            ['reschedule' => true, 'sync_now' => true],
            Installer::groupSettingsChange(null, ['homepages' => [9 => ['enabled' => true]], 'sync_interval' => 'daily'])
        );
    }

    /** Eine zweite Homepage aendert nichts am Zeitplan, braucht aber einen Lauf. */
    public function testTickingAnotherHomepageOnlySyncsNow(): void
    {
        $old = ['homepages' => [9 => ['enabled' => true], 6 => ['enabled' => false]], 'sync_interval' => 'daily'];
        $new = ['homepages' => [9 => ['enabled' => true], 6 => ['enabled' => true]], 'sync_interval' => 'daily'];

        $this->assertSame(['reschedule' => false, 'sync_now' => true], Installer::groupSettingsChange($old, $new));
    }

    public function testChangingTheIntervalOnlyReschedules(): void
    {
        $old = ['homepages' => [9 => ['enabled' => true]], 'sync_interval' => 'daily'];
        $new = ['homepages' => [9 => ['enabled' => true]], 'sync_interval' => 'weekly'];

        $this->assertSame(['reschedule' => true, 'sync_now' => false], Installer::groupSettingsChange($old, $new));
    }

    /** Die letzte abgewaehlt: kein Zeitplan mehr, aber ein Lauf, der aufraeumt. */
    public function testUntickingTheLastHomepageReschedulesAndSyncsNow(): void
    {
        $old = ['homepages' => [9 => ['enabled' => true]], 'sync_interval' => 'daily'];
        $new = ['homepages' => [9 => ['enabled' => false]], 'sync_interval' => 'daily'];

        $this->assertSame(['reschedule' => true, 'sync_now' => true], Installer::groupSettingsChange($old, $new));
    }

    /** Der Hash landet im Pfad; geprueft wird vor jedem Aufruf. */
    public function testClientRefusesAHashThatWouldLeaveThePath(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            (new Client('https://musterkirche.church.tools', ''))->getGroupHomepage('../whoami');
        } finally {
            $this->assertSame([], ctp_test_http_calls());
        }
    }
}
