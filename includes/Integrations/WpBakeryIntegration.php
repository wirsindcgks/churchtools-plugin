<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Integrations;

final class WpBakeryIntegration
{
    public function register(): void
    {
        add_action('vc_before_init', [$this, 'mapShortcode']);
    }

    /**
     * Maps the same `ctp_events` shortcode (registered in Frontend\Shortcode)
     * into the WPBakery element panel, so both share one rendering path.
     */
    public function mapShortcode(): void
    {
        if (!function_exists('vc_map')) {
            return;
        }

        vc_map([
            'name' => __('ChurchTools Events', 'churchtools-plugin'),
            'base' => 'ctp_events',
            'category' => __('ChurchTools', 'churchtools-plugin'),
            'icon' => 'icon-wpb-calendar',
            'params' => [
                [
                    'type' => 'textfield',
                    'heading' => __('Kalender-IDs (kommagetrennt)', 'churchtools-plugin'),
                    'param_name' => 'calendar',
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Layout', 'churchtools-plugin'),
                    'param_name' => 'layout',
                    'value' => ['list' => 'list', 'grid' => 'grid'],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Anzahl Events', 'churchtools-plugin'),
                    'param_name' => 'limit',
                    'value' => '10',
                ],
            ],
        ]);
    }
}
