<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\GroupsTab;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use PHPUnit\Framework\TestCase;

/**
 * Der Reiter „Gruppenliste" zeigt, was gespeichert ist - also genau das, was
 * ein Shortcode ausgeben kann. Geprueft wird das gerenderte Markup.
 */
final class GroupsTabListTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
    }

    public function testWithoutEnabledHomepageTheListPointsToTheHomepages(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Es werden keine Gruppen übernommen', $html);
        $this->assertStringContainsString('tab=groups', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /** Ein Panel je Homepage, der Name fuehrt nach ChurchTools - geaendert wird dort. */
    public function testEachEnabledHomepageListsItsGroups(): void
    {
        $this->configure([
            'fetched' => '2026-09-14 12:00:00',
            'empty_runs' => 0,
            'groups' => [
                $this->group(269, 'Wine <b>&</b> Dine', 12, 3),
                $this->group(514, 'Offener Hauskreis', null, null),
            ],
        ]);

        $html = $this->render();

        $this->assertStringContainsString('<h2>Kleingruppen</h2>', $html);
        $this->assertStringContainsString('Wine &lt;b&gt;&amp;&lt;/b&gt; Dine', $html);
        $this->assertStringContainsString('href="https://musterkirche.church.tools/publicgroup/269" target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('Noch 3 Plätze frei', $html);
        $this->assertStringContainsString('Gruppen: 2', $html);
        $this->assertStringNotContainsString('Abgewaehlt', $html, 'Nicht angehakte Homepages erscheinen nicht.');
    }

    /**
     * Haelt der Leer-Antwort-Schutz gerade den Bestand, sagt die Liste das -
     * sonst saehe sie aktuell aus, obwohl ChurchTools laengst nichts mehr liefert.
     */
    public function testTheListSaysWhenStoredGroupsAreHeldAgainstAnEmptyAnswer(): void
    {
        $this->configure([
            'fetched' => '2026-09-10 12:00:00',
            'empty_runs' => 1,
            'groups' => [$this->group(269, 'Wine & Dine', null, null)],
        ]);

        $this->assertStringContainsString('zurzeit keine Gruppen', $this->render());
    }

    private function configure(array $entry): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Abgewaehlt', 'hash' => 'XyZ789', 'enabled' => false],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => $entry]);
    }

    private function group(int $id, string $name, ?int $max, ?int $free): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'note' => '',
            'image_url' => '',
            'weekday' => 'Freitag',
            'meeting_time' => '',
            'max_members' => $max,
            'free_places' => $free,
            'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/' . $id,
        ];
    }

    private function render(): string
    {
        ob_start();
        GroupsTab::renderList();

        return (string) ob_get_clean();
    }
}
