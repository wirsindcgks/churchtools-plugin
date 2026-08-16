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
                    'heading' => __('Ansicht', 'churchtools-plugin'),
                    'param_name' => 'layout',
                    'value' => [
                        __('Liste', 'churchtools-plugin') => 'list',
                        __('Grid', 'churchtools-plugin') => 'grid',
                        __('Nächster Termin', 'churchtools-plugin') => 'upcoming',
                    ],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Spalten (nur Grid)', 'churchtools-plugin'),
                    'param_name' => 'columns',
                    'value' => '3',
                    'dependency' => ['element' => 'layout', 'value' => 'grid'],
                ],
                [
                    'type' => 'textfield',
                    'heading' => __('Anzahl Events', 'churchtools-plugin'),
                    'param_name' => 'limit',
                    'value' => '10',
                ],
                [
                    'type' => 'dropdown',
                    'heading' => __('Klickverhalten', 'churchtools-plugin'),
                    'param_name' => 'click',
                    'value' => [
                        __('Standard (Design-Einstellung)', 'churchtools-plugin') => 'default',
                        __('Keine', 'churchtools-plugin') => 'none',
                        __('Popup', 'churchtools-plugin') => 'popup',
                        __('Eigene Seite', 'churchtools-plugin') => 'page',
                    ],
                ],
            ],
        ]);
    }
}
