<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use PHPUnit\Framework\TestCase;

/**
 * Gruppenfinder (1.31.0): Knoepfe fuer Kategorie, Wochentag und Zielgruppe
 * (`finder`) und Suchleiste (`search`) ueber dem Raster. Die Filterregel selbst steht zweimal - in
 * GroupListRenderer::finderMatches() fuer die Knopfauswahl und in
 * frontend.js fuer das Ausblenden; getestet ist hier die PHP-Seite.
 */
final class GroupFinderTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
    }

    /** Knoepfe in der Reihenfolge der Stammdaten, Montag bis Sonntag. */
    public function testRowsAreSortedByTheSortKeyFromChurchTools(): void
    {
        $rows = GroupListRenderer::finderRows($this->prepared([
            $this->group(1, ['weekday' => 'Sonntag', 'weekday_sort' => 6, 'category' => 'Musik', 'category_sort' => 5]),
            $this->group(2, ['weekday' => 'Montag', 'weekday_sort' => 0, 'category' => 'Hauskreis', 'category_sort' => 9]),
            $this->group(3, ['weekday' => 'Donnerstag', 'weekday_sort' => 3, 'category' => 'Kinder', 'category_sort' => 1]),
        ]), null);

        $this->assertSame([
            ['key' => 'category', 'options' => ['Kinder', 'Musik', 'Hauskreis']],
            ['key' => 'weekday', 'options' => ['Montag', 'Donnerstag', 'Sonntag']],
        ], $rows);
    }

    /**
     * Der Stand der Referenzinstanz am 2026-09-15: eine Gruppe fuer „Frauen",
     * der Rest „Jeder" oder ohne Zielgruppe. „Frauen" wuerde alle zeigen - die
     * Reihe faellt deshalb weg, statt kaputt auszusehen.
     */
    public function testATargetGroupThatNarrowsNothingGetsNoRow(): void
    {
        $rows = GroupListRenderer::finderRows($this->prepared([
            $this->group(1, ['target_group' => 'Frauen', 'target_group_key' => 'women']),
            $this->group(2, ['target_group' => 'Jeder', 'target_group_key' => 'everyone']),
            $this->group(3, ['target_group' => '', 'target_group_key' => '']),
        ]), null);

        $this->assertSame([], $rows);
    }

    /** „Jeder" ist kein Knopf und passt zu jeder Auswahl; zwei echte Zielgruppen grenzen ein. */
    public function testEveryoneIsNoButtonButMatchesEveryChoice(): void
    {
        $groups = $this->prepared([
            $this->group(1, ['target_group' => 'Frauen', 'target_group_key' => 'women', 'target_group_sort' => 3]),
            $this->group(2, ['target_group' => 'Männer', 'target_group_key' => 'men', 'target_group_sort' => 2]),
            $this->group(3, ['target_group' => 'Jeder', 'target_group_key' => 'everyone', 'target_group_sort' => 1]),
        ]);

        $this->assertSame([['key' => 'target', 'options' => ['Männer', 'Frauen']]], GroupListRenderer::finderRows($groups, null));
        $this->assertSame('', $groups[2]['finder_target']);
    }

    /** Kategorie und Wochentag muessen passen: Eine Gruppe ohne Tag grenzt „Donnerstag" ein. */
    public function testAGroupWithoutWeekdayIsNarrowedOut(): void
    {
        $rows = GroupListRenderer::finderRows($this->prepared([
            $this->group(1, ['weekday' => 'Donnerstag']),
            $this->group(2, ['weekday' => '']),
        ]), null);

        $this->assertSame([['key' => 'weekday', 'options' => ['Donnerstag']]], $rows);
    }

    /** ChurchTools entscheidet: Ist ein Filter an der Homepage aus, gibt es keine Reihe. */
    public function testOnlyFiltersTheHomepageShows(): void
    {
        $groups = $this->prepared([
            $this->group(1, ['weekday' => 'Montag', 'category' => 'Musik']),
            $this->group(2, ['weekday' => 'Freitag', 'category' => 'Kinder']),
        ]);

        $this->assertSame(['weekday'], array_column(GroupListRenderer::finderRows($groups, ['weekday', 'targetgroups']), 'key'));
        $this->assertSame([], GroupListRenderer::finderRows($groups, []));
    }

    public function testTheGridShowsTheFinderWithFilterValuesOnEachCell(): void
    {
        $this->homepageWith([
            $this->group(1, ['weekday' => 'Montag', 'category' => 'Musik "laut"', 'note' => "<p>Wir <strong>SINGEN</strong></p>\nZweite Zeile"]),
            $this->group(2, ['weekday' => 'Freitag', 'category' => 'Kinder']),
        ], ['weekday', 'groupcategory', 'targetgroups']);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'finder' => true, 'search' => true]);

        $this->assertStringContainsString('ctp-groups__finder', $html);
        $this->assertStringContainsString('Welche Gruppe passt zu dir?', $html);
        $this->assertStringContainsString('data-ctp-group-filter="category" data-ctp-group-value="Musik &quot;laut&quot;"', (string) preg_replace('/\s+/', ' ', $html));
        $this->assertStringContainsString('data-ctp-group-weekday="Freitag"', $html);
        $this->assertStringContainsString('data-ctp-group-category="Musik &quot;laut&quot;"', $html);
        $this->assertStringContainsString('data-ctp-group-search="gruppe 1 musik &quot;laut&quot; montag jeder wir singen zweite zeile"', $html);
        $this->assertStringContainsString('Gruppen durchsuchen', $html);
    }

    /**
     * Zwei Schalter wie beim Eventfinder: `finder` fuer die Knoepfe, `search`
     * fuer das Suchfeld - jeder fuer sich (Nutzerwunsch 2026-09-15:
     * einheitliche Bedienung von Terminen und Gruppen).
     */
    public function testFinderAndSearchAreSeparateSwitchesLikeForEvents(): void
    {
        $this->homepageWith([$this->group(1, ['weekday' => 'Montag']), $this->group(2, ['weekday' => 'Freitag'])], ['weekday']);

        $finderOnly = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'finder' => true]);
        $searchOnly = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'search' => true]);

        $this->assertStringContainsString('data-ctp-group-value="Freitag"', $finderOnly);
        $this->assertStringNotContainsString('ctp-events__search-input', $finderOnly);

        $this->assertStringContainsString('ctp-events__search-input', $searchOnly);
        $this->assertStringNotContainsString('data-ctp-group-filter', $searchOnly);
        $this->assertStringNotContainsString('Welche Gruppe passt zu dir?', $searchOnly);
        $this->assertStringContainsString('data-ctp-group-search="gruppe 2 freitag jeder"', $searchOnly, 'Die Suche braucht die Suchtexte auch ohne Finder.');
    }

    /** Ohne Finder und Suche keine Leiste und keine Suchtexte - der ganze Text stuende sonst doppelt in der Seite. */
    public function testWithoutFinderNothingOfItIsRendered(): void
    {
        $this->homepageWith([$this->group(1, ['weekday' => 'Montag']), $this->group(2, ['weekday' => 'Freitag'])], ['weekday']);

        $plain = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);
        $featured = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'layout' => 'featured', 'finder' => true]);

        foreach ([$plain, $featured] as $html) {
            $this->assertStringNotContainsString('ctp-groups__finder', $html);
            $this->assertStringNotContainsString('data-ctp-group-', $html);
        }
    }

    /**
     * Daten aus einem Abgleich vor 1.31.0 kennen die Filter der Homepage noch
     * nicht - bis zum naechsten Lauf schraenkt der Finder dann nicht ein.
     */
    public function testUnknownHomepageFiltersAllowEveryRow(): void
    {
        $this->homepageWith([$this->group(1, ['weekday' => 'Montag']), $this->group(2, ['weekday' => 'Freitag'])], null);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen', 'finder' => '1']);

        $this->assertStringContainsString('data-ctp-group-value="Freitag"', $html);
    }

    /** Einzelne Gruppen: Es gelten die Filter der Homepages, auf denen sie stehen. */
    public function testSelectedGroupsUseTheFiltersOfTheirHomepages(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => true],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [
            9 => ['fetched' => '', 'empty_runs' => 0, 'filters' => [], 'groups' => [$this->group(1, ['weekday' => 'Montag'])]],
            6 => ['fetched' => '', 'empty_runs' => 0, 'filters' => ['weekday'], 'groups' => [$this->group(2, ['weekday' => 'Freitag'])]],
        ]);

        $html = (new GroupListRenderer())->render(['groups' => '1,2', 'finder' => true]);

        $this->assertStringContainsString('data-ctp-group-value="Montag"', $html);
    }

    /** @param list<array> $groups */
    private function prepared(array $groups): array
    {
        return GroupListRenderer::prepareGroups($groups, []);
    }

    /** @param list<string>|null $filters */
    private function homepageWith(array $groups, ?array $filters): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
        ]]);

        $data = ['fetched' => '', 'empty_runs' => 0, 'groups' => $groups];
        if ($filters !== null) {
            $data['filters'] = $filters;
        }

        ctp_test_set_option(GroupSync::DATA_OPTION, [9 => $data]);
    }

    private function group(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Gruppe ' . $id,
            'note' => '',
            'image_url' => '',
            'weekday' => 'Donnerstag',
            'weekday_sort' => 3,
            'meeting_time' => '19:30 Uhr',
            'target_group' => 'Jeder',
            'target_group_key' => 'everyone',
            'target_group_sort' => 1,
            'category' => '',
            'category_sort' => null,
            'max_members' => null,
            'free_places' => null,
            'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/' . $id,
        ], $overrides);
    }
}
