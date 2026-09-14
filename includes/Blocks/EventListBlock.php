<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Frontend\EventListRenderer;
use ChurchToolsPlugin\Settings;

final class EventListBlock
{
    /**
     * WP derives this handle from block.json itself (register_block_type() ->
     * generate_block_asset_handle()): "churchtools-plugin/event-list" + the
     * "editorScript" field becomes "churchtools-plugin-event-list-editor-script".
     * Not configurable — has to match core's naming convention exactly for
     * wp_localize_script() to attach to the right <script>.
     */
    private const EDITOR_SCRIPT_HANDLE = 'churchtools-plugin-event-list-editor-script';

    public function register(): void
    {
        add_action('init', [$this, 'registerBlockType']);
        add_action('enqueue_block_editor_assets', [$this, 'localizeCalendars']);
    }

    public function registerBlockType(): void
    {
        register_block_type(CTP_PLUGIN_DIR . 'blocks/event-list', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Feeds the block editor's calendar checklist (see index.js) the calendars
     * already fetched into settings — the editor has no REST access of its own to
     * ChurchTools, and re-fetching from ChurchTools on every editor load would need
     * a whole separate authenticated endpoint for what's already sitting in
     * options. If nothing's been fetched yet, index.js shows a hint instead of an
     * empty list.
     */
    public function localizeCalendars(): void
    {
        $calendars = [];

        foreach (Settings::get()['calendars'] as $id => $calendar) {
            $calendars[] = [
                'id' => (int) $id,
                'name' => $calendar['name'] !== '' ? $calendar['name'] : sprintf('#%d', (int) $id),
            ];
        }

        // Siehe GroupListBlock::localizeHomepages() fuer wp_add_inline_script().
        wp_add_inline_script(self::EDITOR_SCRIPT_HANDLE, 'window.ctpBlockCalendars = ' . wp_json_encode($calendars) . ';', 'before');
    }

    /**
     * Die Ausgabe steckt im Wrapper, den WordPress fuer jeden Block baut
     * (get_block_wrapper_attributes()): Dort landen die Klassen aus
     * block.json-`supports` - vor allem `alignwide`/`alignfull`. Ohne ihn
     * waehlte man im Editor „Weite Breite", und auf der Seite geschah nichts,
     * weil ein Block mit render_callback seinen Wrapper selbst ausgeben muss.
     *
     * Anlass (Nutzerbefund 2026-09-14): `columns="3"` ergab zwei Spalten, weil
     * der Inhaltsbereich des Themes 645px breit ist und drei Kacheln
     * mindestens 788px brauchen. Die weite Breite des Themes (1340px) gibt
     * dem Raster den Platz.
     */
    public function render(array $attributes): string
    {
        return self::wrap((new EventListRenderer())->render([
            'calendar_ids' => Settings::resolveCalendarIds($attributes['calendarIds'] ?? []),
            'layout' => $attributes['layout'] ?? 'list',
            'limit' => (int) ($attributes['limit'] ?? 0),
            'columns' => (int) ($attributes['columns'] ?? 3),
            // "click" was previously missing here entirely, silently making the
            // block editor's "Klickverhalten" control a no-op — always fixed here
            // alongside the three new toggles below.
            'click' => $attributes['click'] ?? 'default',
            'filter' => (bool) ($attributes['filter'] ?? false),
            'search' => (bool) ($attributes['search'] ?? false),
            'month_dividers' => (bool) ($attributes['monthDividers'] ?? false),
            'eventfinder' => (bool) ($attributes['eventfinder'] ?? false),
            'months' => (int) ($attributes['months'] ?? 0),
            'paging' => (bool) ($attributes['paging'] ?? true),
        ]));
    }

    /**
     * Ausserhalb eines Block-Renderlaufs (etwa in einem Test) gibt es keinen
     * Block, dessen Wrapper sich bauen liesse - dann bleibt die Ausgabe, wie
     * sie ist.
     */
    public static function wrap(string $html): string
    {
        if (!function_exists('get_block_wrapper_attributes') || \WP_Block_Supports::$block_to_render === null) {
            return $html;
        }

        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }
}
