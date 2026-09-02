<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Die Raumangabe entsteht beim Sync und steht danach als Wert in der
 * Termintabelle. Ohne einen Lauf ausser der Reihe bliebe eine geaenderte
 * Auswahl bis zum naechsten planmaessigen Abgleich wirkungslos - gemeldet als
 * „der Wechsel zeigt nicht direkt zu greifen".
 */
final class InstallerRoomSettingsTest extends TestCase
{
    public function testATickedRoomCountsAsAChange(): void
    {
        $this->assertTrue(Installer::roomSettingsChanged(
            ['resources' => [23 => ['enabled' => false]]],
            ['resources' => [23 => ['enabled' => true]]]
        ));
    }

    public function testAnUntickedRoomCountsAsAChange(): void
    {
        $this->assertTrue(Installer::roomSettingsChanged(
            ['resources' => [23 => ['enabled' => true]]],
            ['resources' => [23 => ['enabled' => false]]]
        ));
    }

    public function testSwitchingTheModeCountsAsAChange(): void
    {
        $this->assertTrue(Installer::roomSettingsChanged(
            ['rooms_mode' => 'single'],
            ['rooms_mode' => 'all']
        ));
    }

    /**
     * Name und Sortierschluessel kommen bei jedem Abgleich frisch aus
     * ChurchTools. Ein dort umbenannter Raum ist kein Grund, ausser der Reihe zu
     * synchronisieren - sonst stiesse jeder stuendliche Listenabgleich einen
     * zweiten Lauf an.
     */
    public function testARenamedRoomIsNotAChange(): void
    {
        $this->assertFalse(Installer::roomSettingsChanged(
            ['resources' => [23 => ['name' => 'Alt', 'enabled' => true, 'sort_key' => 5]]],
            ['resources' => [23 => ['name' => 'Neu', 'enabled' => true, 'sort_key' => 9]]]
        ));
    }

    public function testSavingAnotherTabIsNotAChange(): void
    {
        $settings = ['resources' => [23 => ['enabled' => true]], 'rooms_mode' => 'all'];

        $this->assertFalse(Installer::roomSettingsChanged($settings, $settings));
    }

    /**
     * Beim allerersten Speichern ist der alte Wert kein Array. Das darf keinen
     * Fehler geben - und wo vorher nichts angehakt war, gibt es auch nichts
     * nachzuziehen.
     */
    public function testSurvivesAMissingPreviousValue(): void
    {
        $this->assertFalse(Installer::roomSettingsChanged(false, ['resources' => []]));
        $this->assertTrue(Installer::roomSettingsChanged(false, ['resources' => [23 => ['enabled' => true]]]));
    }
}
