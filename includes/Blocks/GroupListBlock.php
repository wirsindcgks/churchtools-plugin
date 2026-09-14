<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Frontend\GroupListRenderer;
use ChurchToolsPlugin\Groups\GroupSettings;

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
     */
    public function localizeHomepages(): void
    {
        $homepages = [];

        foreach (GroupSettings::enabledHomepages() as $id => $homepage) {
            $homepages[] = [
                'id' => (int) $id,
                'name' => $homepage['name'] !== '' ? $homepage['name'] : sprintf('#%d', (int) $id),
            ];
        }

        wp_localize_script(self::EDITOR_SCRIPT_HANDLE, 'ctpBlockGroupHomepages', $homepages);
    }

    public function render(array $attributes): string
    {
        return (new GroupListRenderer())->render([
            'homepage' => (string) ($attributes['homepage'] ?? ''),
            'columns' => (int) ($attributes['columns'] ?? 3),
        ]);
    }
}
