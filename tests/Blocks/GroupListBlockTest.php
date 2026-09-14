<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Blocks;

use ChurchToolsPlugin\Blocks\GroupListBlock;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Integrations\WpBakeryIntegration;
use PHPUnit\Framework\TestCase;

final class GroupListBlockTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => true],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [
            9 => ['groups' => [$this->group(269, 'Hauskreis'), $this->group(514, 'Chor')]],
            6 => ['groups' => [$this->group(830, 'Hauskreis'), $this->group(514, 'Chor')]],
        ]);
    }

    /** Gleich benannte Gruppen bleiben unterscheidbar - an der Homepage dahinter. */
    public function testGroupChoicesNameTheirHomepages(): void
    {
        $choices = GroupListBlock::groupChoices();

        $this->assertSame(['Chor', 'Hauskreis', 'Hauskreis'], array_column($choices, 'name'));
        $this->assertSame('Kleingruppen, Mitarbeit', $choices[0]['homepages']);
        $this->assertContains('Mitarbeit', array_column($choices, 'homepages'));
    }

    /** Der Umschalter im Block entscheidet, welche Angabe gilt - nicht die, die zufaellig noch gespeichert ist. */
    public function testTheSourceSwitchDecidesWhichSelectionApplies(): void
    {
        $block = new GroupListBlock();

        $byHomepage = $block->render(['source' => 'homepage', 'homepage' => '9', 'groups' => '830']);
        $byGroups = $block->render(['source' => 'groups', 'homepage' => '9', 'groups' => '830']);

        $this->assertStringContainsString('Chor', $byHomepage);
        $this->assertStringNotContainsString('Chor', $byGroups);
        $this->assertSame(1, substr_count($byGroups, 'class="ctp-events__cta"'));
    }

    /** Im WPBakery-Baustein stehen die Namen der gewaehlten Gruppen, nicht ihre IDs. */
    public function testWpBakeryShowsGroupNamesInTheElement(): void
    {
        $param = ['type' => 'checkbox', 'param_name' => 'groups', 'value' => ['Chor (Kleingruppen, Mitarbeit)' => '514', 'Hauskreis (Kleingruppen)' => '269']];

        $label = (new WpBakeryIntegration())->adminLabelValue('269,514,999', $param, ['base' => 'ctp_groups']);

        $this->assertSame('Hauskreis (Kleingruppen), Chor (Kleingruppen, Mitarbeit), #999', $label);
    }

    private function group(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'note' => '', 'image_url' => '', 'weekday' => '', 'meeting_time' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => 'https://musterkirche.church.tools/publicgroup/' . $id];
    }
}
