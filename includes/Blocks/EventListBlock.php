<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Frontend\EventListRenderer;

final class EventListBlock
{
    public function register(): void
    {
        add_action('init', [$this, 'registerBlockType']);
    }

    public function registerBlockType(): void
    {
        register_block_type(CTP_PLUGIN_DIR . 'blocks/event-list', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    public function render(array $attributes): string
    {
        return (new EventListRenderer())->render([
            'calendar_ids' => SettingsPage::resolveCalendarIds($attributes['calendarIds'] ?? []),
            'layout' => $attributes['layout'] ?? 'list',
            'limit' => (int) ($attributes['limit'] ?? 10),
        ]);
    }
}
