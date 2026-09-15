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
        // Einmal auf der Kachel, einmal im Popup-Template dahinter.
        $this->assertSame(2, substr_count($byGroups, 'class="ctp-events__cta ctp-button"'));
    }

    /** Im WPBakery-Baustein stehen die Namen der gewaehlten Gruppen, nicht ihre IDs. */
    public function testWpBakeryShowsGroupNamesInTheElement(): void
    {
        $param = ['type' => WpBakeryIntegration::GROUP_PICKER_TYPE, 'param_name' => 'groups', 'ctp_choices' => ['Chor (Kleingruppen, Mitarbeit)' => '514', 'Hauskreis (Kleingruppen)' => '269']];

        $label = (new WpBakeryIntegration())->adminLabelValue('269,514,999', $param, ['base' => 'ctp_groups']);

        $this->assertSame('Hauskreis (Kleingruppen), Chor (Kleingruppen, Mitarbeit), #999', $label);
    }

    /**
     * Das Feld „Einzelne Gruppen": Gespeichert wird allein das versteckte Feld
     * mit der Klasse wpb_vc_param_value (WPBakerys Vertrag fuer eigene
     * Feldtypen); die Liste „Ausgewaehlt" steht in der gespeicherten
     * Reihenfolge, gliedert nach Homepage und behaelt eine verschwundene ID.
     */
    public function testTheGroupPickerRendersTheSavedSelection(): void
    {
        $html = WpBakeryIntegration::renderGroupPicker(['param_name' => 'groups', 'type' => WpBakeryIntegration::GROUP_PICKER_TYPE], '514,999,269');

        $this->assertMatchesRegularExpression('/<input type="hidden" name="groups" class="wpb_vc_param_value groups ctp_group_picker_field" value="514,999,269" \/>/', $html);

        preg_match_all('/<li class="ctp-wpb-picker__item[^"]*" data-id="(\d+)"/', $html, $order);
        $this->assertSame(['514', '999', '269'], $order[1]);
        $this->assertStringContainsString('ctp-wpb-picker__item--missing" data-id="999"', $html);
        $this->assertStringContainsString('#999 (nicht mehr verfügbar)', $html);

        $this->assertStringContainsString('<legend>Kleingruppen</legend>', $html);
        $this->assertStringContainsString('<legend>Mitarbeit</legend>', $html);
        $this->assertSame(2, substr_count($html, 'value="514" data-name="Chor" checked="checked"'), 'Chor steht auf beiden Homepages, beide Haken sind gesetzt.');
        $this->assertStringContainsString('value="830" data-name="Hauskreis" />', $html, 'Nicht gewaehlte Gruppen ohne Haken.');
        $this->assertStringContainsString('class="ctp-wpb-picker__empty" hidden', $html);
    }

    public function testThePickerEscapesNamesAndStartsEmpty(): void
    {
        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['groups' => [$this->group(1, 'Wine <b>&</b> "Dine"')]]]);

        $html = WpBakeryIntegration::renderGroupPicker(['param_name' => 'groups'], '');

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('data-name="Wine &lt;b&gt;&amp;&lt;/b&gt; &quot;Dine&quot;"', $html);
        $this->assertStringContainsString('value="" />', $html);
        $this->assertStringNotContainsString('ctp-wpb-picker__empty" hidden', $html);
    }

    private function group(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'note' => '', 'image_url' => '', 'weekday' => '', 'meeting_time' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => 'https://musterkirche.church.tools/publicgroup/' . $id];
    }
}
