<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Turns the admin-configured element order/corner style into CSS custom
 * properties, shared by all three layout templates (list/grid/upcoming) the
 * same way EventFormatter shares date formatting between them.
 *
 * The eight reorderable elements ("media" = image/date-badge/date-chip,
 * "calendar" = calendar name label, "title", "subtitle", "excerpt" =
 * description snippet, and "date"/"time"/"location" as three independently
 * placeable facts) map onto two nesting
 * levels every layout already has: media is one flex/grid child, and the
 * other seven are flex children *inside* a single sibling "content" child —
 * the content block can only move before/after media as a whole, not
 * interleave with it. See cssVariables()'s --ctp-order-content derivation for
 * how a single flat order still expresses that.
 *
 * On top of the eight fixed elements (each appears exactly once, enforced by
 * isValidOrder()), $elementOrder may also contain any number of "separator"
 * entries — purely decorative spacers/dividers the admin inserts between
 * elements from the Design tab (see renderElementOrderField()). Unlike the
 * fixed elements, these have no dedicated markup in the layout templates
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
    public const ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'excerpt', 'date', 'time', 'location'];
    public const DEFAULT_ORDER = self::ELEMENT_KEYS;
    public const CORNER_STYLES = ['rounded', 'square'];
    public const SEPARATOR_TYPES = ['spacer', 'divider'];

    /**
     * What the single "meta" element was replaced by, in the position it used
     * to hold. Date, time and location are three independently placeable
     * elements now (they used to share one line and one drag handle), so every
     * order stored before that split has to be widened on read — see
     * upgradeOrder().
     */
    public const LEGACY_META_KEY = 'meta';
    public const META_KEYS = ['date', 'time', 'location'];

    /**
     * Which of the ELEMENT_KEYS can be hidden entirely from the card via
     * the "Ausgeblendete Felder" admin field — "title" is deliberately excluded,
     * since a card with no title at all isn't a supported/tested state.
     */
    public const TOGGLEABLE_KEYS = ['media', 'calendar', 'subtitle', 'excerpt', 'date', 'time', 'location'];

    /** CSS aspect-ratio values for the "Bildgröße" admin select, keyed by option value. */
    public const MEDIA_ASPECT_RATIOS = [
        'wide' => '16 / 9',
        'square' => '1 / 1',
        'tall' => '4 / 5',
    ];

    /**
     * Separator keys always carry a unique instance suffix generated client-side
     * (e.g. "divider-m5f3k2"), since — unlike the fixed keys — a design can
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
     * Widens an order stored before date/time/location became three separate
     * elements: the old single "meta" entry is replaced, in place, by the
     * three keys it used to stand for. Anything already on the new key set
     * passes through untouched, so this is safe to run on every read.
     *
     * Done here rather than in a one-shot upgrade routine because the option
     * is written by admins, not by the plugin — a site can still be sitting on
     * an old value long after the update, and a half-migrated order would
     * otherwise fail isValidOrder() and snap the whole layout back to the
     * default (see SettingsPage::sanitizeElementOrder()).
     *
     * @param string[] $order
     *
     * @return string[]
     */
    public static function upgradeOrder(array $order): array
    {
        $upgraded = [];

        foreach ($order as $key) {
            if ($key === self::LEGACY_META_KEY) {
                array_push($upgraded, ...self::META_KEYS);

                continue;
            }

            $upgraded[] = $key;
        }

        return $upgraded;
    }

    /**
     * The hidden-fields counterpart to upgradeOrder(): a site that had the
     * whole meta line switched off keeps all three of its parts switched off.
     *
     * @param string[] $hidden
     *
     * @return string[]
     */
    public static function upgradeHiddenElements(array $hidden): array
    {
        if (!in_array(self::LEGACY_META_KEY, $hidden, true)) {
            return $hidden;
        }

        $hidden = array_values(array_diff($hidden, [self::LEGACY_META_KEY]));

        return array_values(array_unique(array_merge($hidden, self::META_KEYS)));
    }

    /**
     * @param string[] $elementOrder Every ELEMENT_KEYS entry once each, plus any
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
     * @param string $buttonColor A '#rrggbb' hex color for the filled state of
     *                             the interactive controls, or '' to leave
     *                             frontend.css's neutral default in place.
     *
     * @return array<string, int|string>
     */
    public static function cssVariables(
        array $elementOrder,
        string $cornerStyle,
        string $mediaAspectRatio = 'wide',
        string $accentColor = '',
        string $buttonColor = ''
    ): array {
        // Defensive fallback: sanitizeSettings() already guarantees a valid
        // order gets stored, but this method has no access to that guarantee
        // on its own (e.g. if called with a stale/foreign array).
        $elementOrder = self::upgradeOrder($elementOrder);
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
            '--ctp-order-date' => $position['date'],
            '--ctp-order-time' => $position['time'],
            '--ctp-order-location' => $position['location'],
        ];

        // Only "square" ever needs an inline override — "rounded" leaves
        // .ctp-events's own --ctp-radius (which already inherits the theme's
        // configured radius) untouched, so sites that never open the Design
        // tab render exactly as before this feature existed.
        //
        // --ctp-radius-pill goes flat alongside it: the fully rounded elements
        // (calendar/all-day badges, eventfinder buttons, the popup's close
        // button) can't derive from --ctp-radius — "pill" isn't a multiple of
        // the card radius — so without this second override they stayed round
        // on a site that had picked "Eckig" everywhere else.
        if ($cornerStyle === 'square') {
            $variables['--ctp-radius'] = '0px';
            $variables['--ctp-radius-pill'] = '0px';
        }

        // Only a non-default ratio needs an inline override — "wide" leaves each
        // layout's own hardcoded aspect-ratio (16/9 in grid and hero) untouched,
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

        // The button color only drives the *filled* state (hover, and the
        // eventfinder's selected chip) — the idle state stays on the neutral
        // surface/hairline pair, so a brand color reads as an accent on
        // interaction rather than painting a dozen filter chips at rest. Its
        // label color is derived rather than configured: an admin picking a
        // pale brand color would otherwise get white-on-pale.
        if ((bool) preg_match('/^#[0-9a-f]{6}$/i', $buttonColor)) {
            $variables['--ctp-color-button-strong'] = $buttonColor;
            $variables['--ctp-color-button-strong-text'] = self::readableTextOn($buttonColor);
        }

        return $variables;
    }

    /**
     * Black or white, whichever has more contrast against $hexColor.
     *
     * Compares the color's WCAG relative luminance against 0.179, the point
     * where contrast with black and contrast with white are equal — the plain
     * "> 0.5" shortcut picks the wrong one for mid-range colors, which is
     * exactly the range brand colors sit in. Mirrored in
     * assets/js/admin-design.js for the live preview.
     */
    public static function readableTextOn(string $hexColor): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hexColor, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.179 ? '#111827' : '#ffffff';
    }

    public static function styleAttribute(
        array $elementOrder,
        string $cornerStyle,
        string $mediaAspectRatio = 'wide',
        string $accentColor = '',
        string $buttonColor = ''
    ): string {
        $declarations = '';

        $variables = self::cssVariables($elementOrder, $cornerStyle, $mediaAspectRatio, $accentColor, $buttonColor);

        foreach ($variables as $name => $value) {
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
        $elementOrder = self::upgradeOrder($elementOrder);
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
