<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Turns the admin-configured element order/corner style into CSS custom
 * properties, shared by all three layout templates (list/grid/upcoming) the
 * same way EventFormatter shares date formatting between them.
 *
 * The six reorderable elements ("media" = image/date-badge/date-chip,
 * "calendar" = calendar name label, "title", "subtitle", "excerpt" =
 * description snippet, "meta" = date range + location) map onto two nesting
 * levels every layout already has: media is one flex/grid child, and the
 * other five are flex children *inside* a single sibling "content" child —
 * the content block can only move before/after media as a whole, not
 * interleave with it. See cssVariables()'s --ctp-order-content derivation for
 * how a single flat order still expresses that.
 *
 * On top of the six fixed elements (each appears exactly once, enforced by
 * isValidOrder()), $elementOrder may also contain any number of "separator"
 * entries — purely decorative spacers/dividers the admin inserts between
 * elements from the Design tab (see renderElementOrderField()). Unlike the
 * six fixed elements, these have no dedicated markup in the layout templates
 * to attach a CSS order var to (there can be any number of them, or none),
 * so renderSeparators() renders them directly as HTML with the order baked
 * into an inline style instead of a shared stylesheet rule.
 *
 * cssVariables()/styleAttribute() also fold in the three "Rest-Scope"
 * settings from the same Design tab: which of TOGGLEABLE_KEYS are hidden
 * entirely (consumed directly by the three layout templates as a plain
 * in_array() check, not a CSS var — omitting markup outright avoids an empty
 * flex slot a CSS `display:none` toggle would leave behind), the media
 * aspect-ratio override, and an optional global accent-color override.
 */
final class CardDesign
{
    public const ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'excerpt', 'meta'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;
    public const CORNER_STYLES = ['rounded', 'square'];
    public const SEPARATOR_TYPES = ['spacer', 'divider'];

    /**
     * Which of the six ELEMENT_KEYS can be hidden entirely from the card via
     * the "Sichtbare Felder" admin field — "title" is deliberately excluded,
     * since a card with no title at all isn't a supported/tested state.
     */
    public const TOGGLEABLE_KEYS = ['media', 'calendar', 'subtitle', 'excerpt', 'meta'];

    /** CSS aspect-ratio values for the "Bildgröße" admin select, keyed by option value. */
    public const MEDIA_ASPECT_RATIOS = [
        'wide' => '16 / 9',
        'square' => '1 / 1',
        'tall' => '4 / 5',
    ];

    /**
     * Separator keys always carry a unique instance suffix generated client-side
     * (e.g. "divider-m5f3k2"), since — unlike the six fixed keys — a design can
     * contain any number of spacers/dividers, so the type name alone can't
     * identify one array entry.
     */
    public static function isSeparator(string $key): bool
    {
        return (bool) preg_match('/^(' . implode('|', self::SEPARATOR_TYPES) . ')-/', $key);
    }

    public static function separatorType(string $key): string
    {
        return explode('-', $key, 2)[0];
    }

    /**
     * @param string[] $elementOrder The six ELEMENT_KEYS once each, plus any
     *                                number of separator entries, in any order.
     */
    public static function isValidOrder(array $elementOrder): bool
    {
        $fixedKeys = array_values(array_filter($elementOrder, static fn (string $key): bool => !self::isSeparator($key)));
        $isValidPermutation = count($fixedKeys) === count(self::ELEMENT_KEYS)
            && count($fixedKeys) === count(array_unique($fixedKeys))
            && array_diff(self::ELEMENT_KEYS, $fixedKeys) === [];

        $separatorKeys = array_values(array_filter($elementOrder, static fn (string $key): bool => self::isSeparator($key)));
        $separatorsAreUnique = count($separatorKeys) === count(array_unique($separatorKeys));

        return $isValidPermutation && $separatorsAreUnique;
    }

    /**
     * @param string[] $hidden Arbitrary strings from the admin checkbox group
     *                          (see SettingsPage::renderFieldVisibilityField()) —
     *                          filtered down to known TOGGLEABLE_KEYS only.
     *
     * @return string[]
     */
    public static function sanitizeHiddenElements(array $hidden): array
    {
        return array_values(array_unique(array_intersect($hidden, self::TOGGLEABLE_KEYS)));
    }

    /**
     * @param string[] $elementOrder See isValidOrder().
     * @param string $mediaAspectRatio One of MEDIA_ASPECT_RATIOS' keys.
     * @param string $accentColor A '#rrggbb' hex color, or '' to leave the
     *                             theme-derived default (see frontend.css's
     *                             --ctp-accent) untouched.
     *
     * @return array<string, int|string>
     */
    public static function cssVariables(
        array $elementOrder,
        string $cornerStyle,
        string $mediaAspectRatio = 'wide',
        string $accentColor = ''
    ): array {
        // Defensive fallback: sanitizeSettings() already guarantees a valid
        // order gets stored, but this method has no access to that guarantee
        // on its own (e.g. if called with a stale/foreign array).
        if (!self::isValidOrder($elementOrder)) {
            $elementOrder = self::DEFAULT_ORDER;
        }

        $position = array_flip($elementOrder);

        $contentPositions = [];
        foreach ($position as $key => $index) {
            if ($key !== 'media') {
                $contentPositions[] = $index;
            }
        }

        $variables = [
            '--ctp-order-media' => $position['media'],
            '--ctp-order-content' => min($contentPositions),
            '--ctp-order-calendar' => $position['calendar'],
            '--ctp-order-title' => $position['title'],
            '--ctp-order-subtitle' => $position['subtitle'],
            '--ctp-order-excerpt' => $position['excerpt'],
            '--ctp-order-meta' => $position['meta'],
        ];

        // Only "square" ever needs an inline override — "rounded" leaves
        // .ctp-events's own --ctp-radius (which already inherits the theme's
        // configured radius) untouched, so sites that never open the Design
        // tab render exactly as before this feature existed.
        if ($cornerStyle === 'square') {
            $variables['--ctp-radius'] = '0px';
        }

        // Only a non-default ratio needs an inline override — "wide" leaves each
        // layout's own hardcoded aspect-ratio (16/9 grid, 16/10 hero) untouched,
        // same "default emits nothing" rule as corner_style above.
        if (isset(self::MEDIA_ASPECT_RATIOS[$mediaAspectRatio]) && $mediaAspectRatio !== 'wide') {
            $variables['--ctp-media-aspect-ratio'] = self::MEDIA_ASPECT_RATIOS[$mediaAspectRatio];
        }

        // Deliberately a plain format check, not full CSS-color validation —
        // SettingsPage::sanitizeSettings() already runs sanitize_hex_color()
        // before this ever gets stored; this is just a defensive backstop
        // against a stale/foreign value, same role isValidOrder() plays above.
        if ((bool) preg_match('/^#[0-9a-f]{6}$/i', $accentColor)) {
            $variables['--ctp-accent'] = $accentColor;
        }

        return $variables;
    }

    public static function styleAttribute(
        array $elementOrder,
        string $cornerStyle,
        string $mediaAspectRatio = 'wide',
        string $accentColor = ''
    ): string {
        $declarations = '';

        foreach (self::cssVariables($elementOrder, $cornerStyle, $mediaAspectRatio, $accentColor) as $name => $value) {
            $declarations .= $name . ':' . $value . ';';
        }

        return $declarations;
    }

    /**
     * Renders every spacer/divider in $elementOrder as content-region HTML,
     * each with its configured position baked into an inline `order` (see the
     * class docblock for why this can't be a shared stylesheet rule). Position
     * within the returned markup's source order is irrelevant — like every
     * other content element, the inline `order` alone decides where it lands
     * — so templates just echo this once anywhere inside the content block.
     */
    public static function renderSeparators(array $elementOrder): string
    {
        if (!self::isValidOrder($elementOrder)) {
            $elementOrder = self::DEFAULT_ORDER;
        }

        $position = array_flip($elementOrder);
        $html = '';

        foreach ($elementOrder as $key) {
            if (!self::isSeparator($key)) {
                continue;
            }

            $order = (int) $position[$key];

            $html .= self::separatorType($key) === 'divider'
                ? '<hr class="ctp-events__divider" style="order:' . $order . ';" />'
                : '<span class="ctp-events__spacer" aria-hidden="true" style="order:' . $order . ';"></span>';
        }

        return $html;
    }
}
