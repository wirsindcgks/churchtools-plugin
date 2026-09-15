<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;

final class GroupListBlock
{
    /** Von WordPress aus block.json abgeleitet, siehe EventListBlock::EDITOR_SCRIPT_HANDLE. */
    private const EDITOR_SCRIPT_HANDLE = 'churchtools-plugin-group-list-editor-script';

    public function register(): void
    {
        add_action('init', [$this, 'registerBlockType']);
        add_action('enqueue_block_editor_assets', [$this, 'localizeHomepages']);
    }

    public function registerBlockType(): void
    {
        register_block_type(CTP_PLUGIN_DIR . 'blocks/group-list', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Die Auswahl im Editor: nur angehakte Homepages, gespeichert wird die ID.
     * Die ID statt des Namens, weil ein in ChurchTools umbenannter Homepage-Name
     * den Block sonst still leeren wuerde - der Shortcode nimmt den Namen, weil
     * ihn dort ein Mensch liest und tippt.
     *
     * Dazu die einzeln waehlbaren Gruppen (GroupSync::selectableGroups()), je
     * mit den Homepages, auf denen sie stehen - Gruppennamen sind nicht
     * eindeutig, und „Hauskreis (Kleingruppen)" unterscheidet zwei gleich
     * benannte.
     */
    public function localizeHomepages(): void
    {
        // wp_add_inline_script() statt wp_localize_script(): Das ist fuer
        // Uebersetzungsobjekte gedacht und erwartet ein assoziatives Array; eine
        // Liste kam zwar an, aber an der Schnittstelle vorbei.
        wp_add_inline_script(
            self::EDITOR_SCRIPT_HANDLE,
            'window.ctpBlockGroupHomepages = ' . wp_json_encode(self::homepageChoices()) . ';'
                . 'window.ctpBlockGroups = ' . wp_json_encode(self::groupChoices()) . ';',
            'before'
        );
    }

    /** @return list<array{id: int, name: string}> */
    public static function homepageChoices(): array
    {
        $homepages = [];

        foreach (GroupSettings::enabledHomepages() as $id => $homepage) {
            $homepages[] = [
                'id' => (int) $id,
                'name' => $homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $id),
            ];
        }

        return $homepages;
    }

    /** @return list<array{id: int, name: string, homepages: string}> */
    public static function groupChoices(): array
    {
        $onHomepages = [];

        foreach (GroupSettings::enabledHomepages() as $homepageId => $homepage) {
            foreach (GroupSync::groupsFor((int) $homepageId) as $group) {
                $onHomepages[(int) ($group['id'] ?? 0)][] = $homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $homepageId);
            }
        }

        $choices = [];

        foreach (GroupSync::selectableGroups() as $id => $group) {
            $choices[] = [
                'id' => (int) $id,
                'name' => (string) ($group['name'] ?? ''),
                'homepages' => implode(', ', array_unique($onHomepages[(int) $id] ?? [])),
            ];
        }

        usort($choices, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $choices;
    }

    /** Im Block-Wrapper, siehe EventListBlock::render(). */
    public function render(array $attributes): string
    {
        return EventListBlock::wrap((new GroupListRenderer())->render([
            'source' => ($attributes['source'] ?? 'homepage') === 'groups' ? 'groups' : 'homepage',
            'homepage' => (string) ($attributes['homepage'] ?? ''),
            'groups' => (string) ($attributes['groups'] ?? ''),
            'layout' => (string) ($attributes['layout'] ?? 'grid'),
            'columns' => (int) ($attributes['columns'] ?? 3),
        ]));
    }
}
