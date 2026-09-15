<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use PHPUnit\Framework\TestCase;

final class GroupListRendererTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
    }

    public function testPlacesLabel(): void
    {
        $this->assertSame('', GroupListRenderer::placesLabel(['max_members' => null, 'free_places' => null]));
        $this->assertSame('Noch 3 Plätze frei', GroupListRenderer::placesLabel(['max_members' => 12, 'free_places' => 3]));
        $this->assertSame('Noch 1 Platz frei', GroupListRenderer::placesLabel(['max_members' => 12, 'free_places' => 1]));
        $this->assertSame('Ausgebucht', GroupListRenderer::placesLabel(['max_members' => 12, 'free_places' => 0]));
        $this->assertSame(
            'Ausgebucht – Warteliste',
            GroupListRenderer::placesLabel(['max_members' => 12, 'free_places' => 0, 'waitinglist' => true])
        );
    }

    public function testScheduleJoinsWhatIsThere(): void
    {
        $this->assertSame('Donnerstag, 19:30 Uhr', GroupListRenderer::schedule(['weekday' => 'Donnerstag', 'meeting_time' => '19:30 Uhr']));
        $this->assertSame('Samstag', GroupListRenderer::schedule(['weekday' => 'Samstag', 'meeting_time' => '']));
        $this->assertSame('', GroupListRenderer::schedule(['weekday' => '', 'meeting_time' => '']));
    }

    /**
     * Das Bild erscheint nur, wenn die Homepage eins liefert *und* es importiert
     * ist. Ohne Import kein Rueckfall auf die ChurchTools-Adresse.
     */
    public function testImageNeedsBothTheHomepagesUrlAndAnImport(): void
    {
        ctp_test_set_attachment_url(301, 'https://example.org/wp-content/uploads/g44.webp');

        $groups = GroupListRenderer::prepareGroups([
            $this->group(44, 'https://ct/44'),
            $this->group(45, 'https://ct/45'),
            $this->group(269, ''),
        ], [44 => 301, 269 => 301]);

        $this->assertSame('https://example.org/wp-content/uploads/g44.webp', $groups[0]['image_src']);
        $this->assertSame('', $groups[1]['image_src'], 'Nicht importiert: kein Bild, auch nicht das Original.');
        $this->assertSame('', $groups[2]['image_src'], 'Die Homepage zeigt keine Bilder, der Import einer anderen zaehlt hier nicht.');
    }

    /** Hat eine Gruppe ein Bild, bekommen alle die Bildflaeche - die Reihen fluchten. */
    public function testMediaAreaIsSharedByTheWholeListOrByNone(): void
    {
        ctp_test_set_attachment_url(301, 'https://example.org/g44.webp');

        $mixed = GroupListRenderer::prepareGroups([$this->group(44, 'https://ct/44'), $this->group(514, '')], [44 => 301]);
        $none = GroupListRenderer::prepareGroups([$this->group(514, ''), $this->group(253, '')], []);
        $hidden = GroupListRenderer::prepareGroups([$this->group(44, 'https://ct/44')], [44 => 301], ['media']);

        $this->assertSame([true, true], array_column($mixed, 'show_media'));
        $this->assertSame([false, false], array_column($none, 'show_media'));
        $this->assertSame([false], array_column($hidden, 'show_media'));
        $this->assertSame('', $hidden[0]['image_src']);
    }

    public function testHiddenElementsOfTheDesignTabApply(): void
    {
        $group = GroupListRenderer::prepareGroups([$this->group(44, '')], [], ['time', 'excerpt'])[0];

        $this->assertSame('', $group['schedule']);
        $this->assertSame('', $group['excerpt']);
    }

    public function testRenderLinksEachCardToThePublicGroupAndEscapes(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['fetched' => '', 'empty_runs' => 0, 'groups' => [
            array_merge($this->group(269, ''), [
                'name' => 'Wine <b>&</b> Dine',
                'max_members' => 12,
                'free_places' => 3,
            ]),
        ]]]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'columns' => 9]);

        $this->assertStringContainsString('href="https://musterkirche.church.tools/publicgroup/269"', $html);
        $this->assertStringContainsString('Wine &lt;b&gt;&amp;&lt;/b&gt; Dine', $html);
        $this->assertStringContainsString('Noch 3 Plätze frei', $html);
        $this->assertStringContainsString('Donnerstag, 19:30 Uhr', $html);
        $this->assertStringContainsString('--ctp-columns:6;', $html, 'Spalten wie bei den Terminen auf 2 bis 6 begrenzt.');
        $this->assertStringNotContainsString('ctp-events__media', $html);
    }

    public function testRenderShowsTheEmptyStateForAnUnknownHomepage(): void
    {
        $html = (new GroupListRenderer())->render(['homepage' => 'Gibt es nicht']);

        $this->assertStringContainsString('ctp-events__empty', $html);
        $this->assertStringNotContainsString('role="listitem"', $html);
    }

    /**
     * G4: Die Kachel ist nicht mehr klickbar - kein Stretched Link, keine
     * Hover-Klasse. Nach ChurchTools fuehrt allein der Button, und der sagt
     * das, samt Gruppenname fuer Screenreader.
     */
    public function testTheCardIsNotClickableAndTheButtonLeadsToChurchTools(): void
    {
        $this->homepageWith([$this->group(269, ''), $this->group(514, '')]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);

        $this->assertStringNotContainsString('ctp-events__card-trigger', $html);
        $this->assertStringNotContainsString('ctp-events__card--clickable', $html);
        $this->assertSame(2, substr_count($html, 'class="ctp-events__cta"'));
        $this->assertStringContainsString('In ChurchTools ansehen', $html);

        preg_match_all('/aria-describedby="([^"]+)"/', $html, $described);
        foreach ($described[1] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, 'Der Button verweist auf den Titel seiner eigenen Kachel.');
        }
        $this->assertCount(2, array_unique($described[1]));
    }

    /** G1: einzelne Gruppen nach ID, in der angegebenen Reihenfolge, statt der Homepage. */
    public function testSelectedGroupsAppearInTheGivenOrderInsteadOfTheHomepage(): void
    {
        $this->homepageWith([$this->group(269, ''), $this->group(514, ''), $this->group(83, '')]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'groups' => '514, 269']);

        $this->assertStringNotContainsString('Gruppe 83', $html);
        $this->assertLessThan(strpos($html, 'Gruppe 269'), strpos($html, 'Gruppe 514'));
    }

    /**
     * `source` entscheidet, welche Angabe gilt - im WPBakery-Element bleibt die
     * jeweils andere nach dem Umschalten gespeichert und darf nicht mitspielen.
     */
    public function testSourceDecidesWhichStoredSelectionApplies(): void
    {
        $this->homepageWith([$this->group(269, ''), $this->group(514, '')]);
        $renderer = new GroupListRenderer();

        $homepage = $renderer->render(['source' => 'homepage', 'homepage' => 'Kleingruppen', 'groups' => '514']);
        $groups = $renderer->render(['source' => 'groups', 'homepage' => 'Kleingruppen', 'groups' => '514']);

        $this->assertStringContainsString('Gruppe 269', $homepage, 'Homepage gewaehlt: alle ihre Gruppen, gespeicherte Einzelauswahl ignoriert.');
        $this->assertStringNotContainsString('Gruppe 269', $groups);
        $this->assertStringContainsString('Gruppe 514', $groups);
    }

    /** Eine Angabe ohne einzige gueltige ID ist eine misslungene Auswahl, nicht „alle der Homepage". */
    public function testAnUnusableSelectionShowsNothingRatherThanTheWholeHomepage(): void
    {
        $this->homepageWith([$this->group(269, '')]);

        $this->assertSame([], GroupListRenderer::selectGroups('Kleingruppen', 'abc'));
        $this->assertCount(1, GroupListRenderer::selectGroups('Kleingruppen', ''));
    }

    /** G2: grosse Kachel je Gruppe, eigene Vorlage, ohne Spaltenraster. */
    public function testTheFeaturedLayoutUsesItsOwnTemplate(): void
    {
        $this->homepageWith([array_merge($this->group(269, ''), ['note' => ''])]);

        $html = (new GroupListRenderer())->render(['groups' => '269', 'layout' => 'featured']);

        $this->assertStringContainsString('ctp-groups--featured', $html);
        $this->assertStringContainsString('ctp-groups__feature ', $html);
        $this->assertStringNotContainsString('--ctp-columns', $html);
        $this->assertStringContainsString('class="ctp-events__cta"', $html);
    }

    public function testAnUnknownLayoutFallsBackToTheGrid(): void
    {
        $this->homepageWith([$this->group(269, '')]);

        $this->assertStringContainsString('ctp-events--grid', (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'layout' => 'carousel']));
    }

    private function homepageWith(array $groups): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['fetched' => '', 'empty_runs' => 0, 'groups' => $groups]]);
    }

    private function group(int $id, string $imageUrl): array
    {
        return [
            'id' => $id,
            'name' => 'Gruppe ' . $id,
            'note' => 'Treffpunkt: Foyer',
            'image_url' => $imageUrl,
            'weekday' => 'Donnerstag',
            'meeting_time' => '19:30 Uhr',
            'max_members' => null,
            'free_places' => null,
            'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/' . $id,
        ];
    }
}
