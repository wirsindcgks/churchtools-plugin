<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Turns the admin-configured element order/corner style into CSS custom
 * properties, shared by all three layout templates (list/grid/upcoming) the
 * same way EventFormatter shares date formatting between them.
 *
 * The four reorderable elements ("media" = image/date-badge/date-chip,
 * "title", "subtitle", "meta" = date range + location) map onto two nesting
 * levels every layout already has: media is one flex/grid child, and
 * title/subtitle/meta are three flex children *inside* a single sibling
 * "content" child — the content block can only move before/after media as a
 * whole, not interleave with it. See cssVariables()'s --ctp-order-content
 * derivation for how a single flat 4-element order still expresses that.
 */
final class CardDesign
{
    public const ELEMENT_KEYS = ['media', 'title', 'subtitle', 'meta'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;
    public const CORNER_STYLES = ['rounded', 'square'];

    /**
     * @param string[] $elementOrder A permutation of self::ELEMENT_KEYS.
     *
     * @return array<string, int|string>
     */
    public static function cssVariables(array $elementOrder, string $cornerStyle): array
    {
        // Defensive fallback: sanitizeSettings() already guarantees a valid
        // permutation gets stored, but this method has no access to that
        // guarantee on its own (e.g. if called with a stale/foreign array).
        $isValidPermutation = array_diff(self::ELEMENT_KEYS, $elementOrder) === []
            && array_diff($elementOrder, self::ELEMENT_KEYS) === [];

        if (!$isValidPermutation) {
            $elementOrder = self::DEFAULT_ORDER;
        }

        $position = array_flip($elementOrder);

        $variables = [
            '--ctp-order-media' => $position['media'],
            '--ctp-order-content' => min($position['title'], $position['subtitle'], $position['meta']),
            '--ctp-order-title' => $position['title'],
            '--ctp-order-subtitle' => $position['subtitle'],
            '--ctp-order-meta' => $position['meta'],
        ];

        // Only "square" ever needs an inline override — "rounded" leaves
        // .ctp-events's own --ctp-radius (which already inherits the theme's
        // configured radius) untouched, so sites that never open the Design
        // tab render exactly as before this feature existed.
        if ($cornerStyle === 'square') {
            $variables['--ctp-radius'] = '0px';
        }

        return $variables;
    }

    public static function styleAttribute(array $elementOrder, string $cornerStyle): string
    {
        $declarations = '';

        foreach (self::cssVariables($elementOrder, $cornerStyle) as $name => $value) {
            $declarations .= $name . ':' . $value . ';';
        }

        return $declarations;
    }
}
