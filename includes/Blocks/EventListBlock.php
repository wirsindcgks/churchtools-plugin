<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Frontend\EventListRenderer;

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

        foreach (SettingsPage::get()['calendars'] as $id => $calendar) {
            $calendars[] = [
                'id' => (int) $id,
                'name' => $calendar['name'] !== '' ? $calendar['name'] : sprintf('#%d', (int) $id),
            ];
        }

        wp_localize_script(self::EDITOR_SCRIPT_HANDLE, 'ctpBlockCalendars', $calendars);
    }

    public function render(array $attributes): string
    {
        return (new EventListRenderer())->render([
            'calendar_ids' => SettingsPage::resolveCalendarIds($attributes['calendarIds'] ?? []),
            'layout' => $attributes['layout'] ?? 'list',
            'limit' => (int) ($attributes['limit'] ?? 10),
            'columns' => (int) ($attributes['columns'] ?? 3),
            // "click" was previously missing here entirely, silently making the
            // block editor's "Klickverhalten" control a no-op — always fixed here
            // alongside the three new toggles below.
            'click' => $attributes['click'] ?? 'default',
            'filter' => (bool) ($attributes['filter'] ?? false),
            'search' => (bool) ($attributes['search'] ?? false),
            'month_dividers' => (bool) ($attributes['monthDividers'] ?? false),
            'eventfinder' => (bool) ($attributes['eventfinder'] ?? false),
        ]);
    }
}
