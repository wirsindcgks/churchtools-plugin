<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\EventFormatter;
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
        $this->assertSame('Noch 10 Plätze frei', GroupListRenderer::placesLabel(['max_members' => 60, 'free_places' => 10]));
        $this->assertSame('10+ Plätze frei', GroupListRenderer::placesLabel(['max_members' => 60, 'free_places' => 11]));
        $this->assertSame('10+ Plätze frei', GroupListRenderer::placesLabel(['max_members' => 60, 'free_places' => 52]));
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
        $group = GroupListRenderer::prepareGroups([$this->group(44, '')], [], ['time', 'excerpt', 'calendar'])[0];

        $this->assertSame('', $group['schedule']);
        $this->assertSame('', $group['excerpt']);
        $this->assertSame('', $group['target_group_label']);
    }

    /**
     * Die Zielgruppe steht als Angabezeile unter der Treffzeit - auch
     * „Jeder". Gruppen ohne Zielgruppe in ChurchTools und
     * Gruppen aus einem aelteren Abgleich bekommen keine Zeile.
     */
    public function testTheTargetGroupIsShownWhereChurchToolsHasOne(): void
    {
        $older = $this->group(514, '');
        unset($older['target_group']);

        $prepared = GroupListRenderer::prepareGroups([$this->group(44, ''), $older], []);

        $this->assertSame(['Jeder', ''], array_column($prepared, 'target_group_label'));
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
        $this->assertMatchesRegularExpression('#meta-item--time">.*?Donnerstag, 19:30 Uhr\s*</span>\s*<span class="ctp-events__meta-item ctp-events__meta-item--target-group">\s*<svg [^>]*>.*?</svg>\s*Jeder\s*</span>#s', $html);
        $this->assertStringNotContainsString('ctp-events__eyebrow', $html);
        $this->assertStringContainsString('--ctp-columns:6;', $html, 'Spalten wie bei den Terminen auf 2 bis 6 begrenzt.');
        $this->assertStringNotContainsString('ctp-events__media', $html);
    }

    /**
     * Nutzerwunsch 2026-09-15: laengerer Auszug in der Rasterkachel, mit den
     * Absaetzen und Zeilen aus ChurchTools statt einer zusammengezogenen Zeile.
     */
    public function testTheGridExcerptKeepsParagraphsAndLineBreaks(): void
    {
        $words = implode(' ', array_fill(0, 60, 'Wort'));
        $this->homepageWith([array_merge($this->group(269, ''), ['note' => "Wir lesen gemeinsam.\n\nKontakt:\nPfarrbüro\n\n" . $words])]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);

        $this->assertStringContainsString('<div class="ctp-events__excerpt ctp-groups__excerpt">', $html);
        $this->assertStringContainsString('<p>Wir lesen gemeinsam.</p>', $html);
        $this->assertStringContainsString("<p>Kontakt:<br />\nPfarrbüro</p>", $html);
        $this->assertStringNotContainsString('<p class="ctp-events__excerpt">', $html);

        preg_match('#<div class="ctp-events__excerpt ctp-groups__excerpt">(.*?)</div>#s', $html, $excerpt);
        $this->assertSame(
            GroupListRenderer::GRID_EXCERPT_WORDS,
            str_word_count(strip_tags(str_replace('…', '', $excerpt[1])), 0, 'äöüÄÖÜß'),
            'Gekuerzt wird auf die Wortzahl des Rasters.'
        );
        $this->assertStringContainsString('Wort…</p>', $excerpt[1]);
    }

    /** Liefert eine Instanz HTML, bleibt es bei einer Zeile - kein halbes Element. */
    public function testAnHtmlNoteFallsBackToOneLine(): void
    {
        $html = GroupListRenderer::excerptHtml('<p>Erster Absatz</p><p>Zweiter <strong>Absatz</strong></p>');

        $this->assertSame('<p>Erster Absatz Zweiter Absatz</p>', trim($html));
    }

    /** Ausgeblendeter Auszug und hervorgehobene Ansicht brauchen keinen. */
    public function testNoExcerptWhereItIsHiddenOrTheFullTextIsShown(): void
    {
        $hidden = GroupListRenderer::prepareGroups([$this->group(44, '')], [], ['excerpt'])[0];
        $featured = GroupListRenderer::prepareGroups([$this->group(44, '')], [], [], true)[0];

        $this->assertSame('', $hidden['excerpt_html']);
        $this->assertSame('', $featured['excerpt_html']);
        $this->assertNotSame('', $featured['description_html']);
    }

    public function testRenderShowsTheEmptyStateForAnUnknownHomepage(): void
    {
        $html = (new GroupListRenderer())->render(['homepage' => 'Gibt es nicht']);

        $this->assertStringContainsString('ctp-events__empty', $html);
        $this->assertStringNotContainsString('role="listitem"', $html);
    }

    /**
     * Gruppen-Popup (2026-09-15, loest G4 ab): Die Kachel oeffnet den ganzen
     * Text in einem Dialog auf dieser Website. Der Auslöser ist ein Verweis
     * nach ChurchTools - ohne JavaScript fuehrt er dorthin, wie der Button, und
     * der sagt das weiterhin samt Gruppenname fuer Screenreader.
     */
    public function testTheCardOpensThePopupAndTheButtonLeadsToChurchTools(): void
    {
        $this->homepageWith([$this->group(269, ''), $this->group(514, '')]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);
        $cards = (string) preg_replace('#<template class="ctp-events__detail-template">.*?</template>#s', '', $html);

        $this->assertSame(2, substr_count($cards, '<a class="ctp-events__card-trigger" data-ctp-modal="1" href="https://musterkirche.church.tools/publicgroup/'));
        $this->assertSame(2, substr_count($cards, 'ctp-events__card--clickable'));
        $this->assertSame(2, substr_count($cards, 'class="ctp-events__cta ctp-button"'));
        $this->assertSame(1, substr_count($html, '<dialog class="ctp-events__modal">'), 'Ein Dialog je Liste, wie bei den Terminen.');
        $this->assertSame(2, substr_count($html, '<template class="ctp-events__detail-template">'));

        preg_match_all('/aria-describedby="([^"]+)"/', $html, $described);
        foreach ($described[1] as $id) {
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), 'Jeder Button verweist auf genau einen Titel - Kachel und Popup haben eigene Kennungen.');
        }
        $this->assertCount(4, array_unique($described[1]));
    }

    /** Das Popup zeigt den ganzen Text samt Treffzeit, Zielgruppe und Button. */
    public function testThePopupCarriesTheWholeGroup(): void
    {
        $words = implode(' ', array_fill(0, 60, 'Wort'));
        $this->homepageWith([array_merge($this->group(269, ''), ['note' => $words . ' Ende', 'max_members' => 12, 'free_places' => 3])]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);
        preg_match('#<template class="ctp-events__detail-template">(.*?)</template>#s', $html, $popup);

        $this->assertStringContainsString('<h2 class="ctp-events__detail-title"', $popup[1]);
        $this->assertStringContainsString('Noch 3 Plätze frei', $popup[1]);
        $this->assertStringContainsString('Donnerstag, 19:30 Uhr', $popup[1]);
        $this->assertStringContainsString('meta-item--target-group', $popup[1]);
        $this->assertStringContainsString('Wort Ende', $popup[1], 'Im Popup steht der ganze Text.');
        $this->assertStringContainsString('In ChurchTools ansehen', $popup[1]);
    }

    /**
     * Kein eigener „Weiterlesen"-Verweis (Nutzerwunsch 2026-09-15): Das Popup
     * oeffnet die ganze Kachel, ein zweites Ziel darin waere doppelt.
     */
    public function testTheCardHasNoSeparateReadMoreLink(): void
    {
        $words = implode(' ', array_fill(0, 60, 'Wort'));
        $this->homepageWith([array_merge($this->group(269, ''), ['note' => $words])]);

        $html = (new GroupListRenderer())->render(['homepage' => 'Kleingruppen']);
        $cards = (string) preg_replace('#<template class="ctp-events__detail-template">.*?</template>#s', '', $html);

        $this->assertStringNotContainsString('Weiterlesen', $html);
        $this->assertSame(1, substr_count($cards, 'data-ctp-modal="1"'), 'Nur der Kachel-Auslöser oeffnet das Popup.');
        $this->assertStringContainsString('Wort…</p>', $cards, 'Der gekuerzte Text endet mit Auslassungszeichen.');
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
        $this->assertStringContainsString('class="ctp-events__cta ctp-button"', $html);
        $this->assertMatchesRegularExpression('#meta-item--target-group">\s*<svg [^>]*>.*?</svg>\s*Jeder\s*</span>#s', $html);
    }

    /**
     * Die hervorgehobene Kachel oeffnet das Popup wie die Hero-Kachel der
     * Termine (Nutzerwunsch 2026-09-18); der Button fuehrt weiter nach ChurchTools.
     */
    public function testTheFeaturedCardOpensThePopupLikeTheEventHero(): void
    {
        $this->homepageWith([$this->group(269, ''), $this->group(514, '')]);

        $html = (new GroupListRenderer())->render(['groups' => '269,514', 'layout' => 'featured']);
        $cards = (string) preg_replace('#<template class="ctp-events__detail-template">.*?</template>#s', '', $html);

        $this->assertSame(2, substr_count($cards, '<a class="ctp-events__card-trigger" data-ctp-modal="1" href="https://musterkirche.church.tools/publicgroup/'));
        $this->assertSame(2, substr_count($cards, 'ctp-events__hero--clickable'));
        $this->assertSame(2, substr_count($cards, '<div class="ctp-events__cell" role="listitem">'), 'In der Zelle sucht frontend.js das Template.');
        $this->assertSame(2, substr_count($cards, 'class="ctp-events__cta ctp-button"'));
        $this->assertSame(1, substr_count($html, '<dialog class="ctp-events__modal">'));
        $this->assertSame(2, substr_count($html, '<template class="ctp-events__detail-template">'));

        preg_match_all('/aria-describedby="([^"]+)"/', $html, $described);
        foreach ($described[1] as $id) {
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'));
        }
    }

    /**
     * Hervorgehoben steht der Auszug der Hero-Kachel (20 Woerter, drei Zeilen
     * per CSS), nicht der ganze Text - so bestimmt das Bild die Kachelhoehe
     * wie bei den Terminen (Nutzerwunsch 2026-09-18). Der ganze Text steht im
     * Popup.
     */
    public function testTheFeaturedCardShowsTheHeroExcerptAndThePopupTheWholeText(): void
    {
        $words = implode(' ', array_map(static fn (int $i): string => 'Wort' . $i, range(1, 40)));
        $this->homepageWith([array_merge($this->group(269, ''), ['note' => $words])]);

        $html = (new GroupListRenderer())->render(['groups' => '269', 'layout' => 'featured']);
        $card = (string) preg_replace('#<template class="ctp-events__detail-template">.*?</template>#s', '', $html);
        preg_match('#<template class="ctp-events__detail-template">(.*?)</template>#s', $html, $popup);

        $this->assertStringContainsString('<p class="ctp-events__excerpt">' . esc_html(EventFormatter::excerpt($words)) . '</p>', $card);
        $this->assertStringContainsString('Wort20', $card);
        $this->assertStringNotContainsString('Wort21', $card);
        $this->assertStringContainsString('Wort40', $popup[1]);
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
            'target_group' => 'Jeder',
            'max_members' => null,
            'free_places' => null,
            'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/' . $id,
        ];
    }
}
