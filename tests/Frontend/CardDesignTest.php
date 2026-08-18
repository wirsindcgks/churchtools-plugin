<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\CardDesign;
use PHPUnit\Framework\TestCase;

final class CardDesignTest extends TestCase
{
    public function testCssVariablesForDefaultOrder(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded');

        $this->assertSame(0, $variables['--ctp-order-media']);
        $this->assertSame(1, $variables['--ctp-order-content']);
        $this->assertSame(1, $variables['--ctp-order-calendar']);
        $this->assertSame(2, $variables['--ctp-order-title']);
        $this->assertSame(3, $variables['--ctp-order-subtitle']);
        $this->assertSame(4, $variables['--ctp-order-excerpt']);
        $this->assertSame(5, $variables['--ctp-order-date']);
        $this->assertSame(6, $variables['--ctp-order-time']);
        $this->assertSame(7, $variables['--ctp-order-location']);
        $this->assertArrayNotHasKey('--ctp-radius', $variables);
    }

    /**
     * Media is one flex item; the other elements are flex items inside a
     * single sibling "content" item, so the content block's own order can
     * only reflect the earliest of those positions — this pins that
     * derivation down for a non-default order.
     */
    public function testContentOrderIsTheMinimumOfTheNonMediaElements(): void
    {
        $variables = CardDesign::cssVariables(
            ['title', 'media', 'calendar', 'subtitle', 'excerpt', 'date', 'time', 'location'],
            'rounded'
        );

        $this->assertSame(1, $variables['--ctp-order-media']);
        $this->assertSame(0, $variables['--ctp-order-content']);
        $this->assertSame(0, $variables['--ctp-order-title']);
        $this->assertSame(2, $variables['--ctp-order-calendar']);
        $this->assertSame(3, $variables['--ctp-order-subtitle']);
        $this->assertSame(4, $variables['--ctp-order-excerpt']);
        $this->assertSame(5, $variables['--ctp-order-date']);
        $this->assertSame(6, $variables['--ctp-order-time']);
        $this->assertSame(7, $variables['--ctp-order-location']);
    }

    /**
     * Date, time and location replaced a single "meta" element. An order
     * stored before that split has to keep meaning what it meant — the three
     * land where "meta" stood, and nothing else moves.
     */
    public function testUpgradeOrderExpandsLegacyMetaKeyInPlace(): void
    {
        $upgraded = CardDesign::upgradeOrder(['meta', 'media', 'calendar', 'title', 'subtitle', 'excerpt']);

        $this->assertSame(
            ['date', 'time', 'location', 'media', 'calendar', 'title', 'subtitle', 'excerpt'],
            $upgraded
        );
        $this->assertTrue(CardDesign::isValidOrder($upgraded));
    }

    public function testUpgradeOrderLeavesCurrentOrdersUntouched(): void
    {
        $this->assertSame(CardDesign::DEFAULT_ORDER, CardDesign::upgradeOrder(CardDesign::DEFAULT_ORDER));
    }

    public function testUpgradeOrderKeepsSeparators(): void
    {
        $upgraded = CardDesign::upgradeOrder(['media', 'divider-a1', 'title', 'subtitle', 'excerpt', 'calendar', 'meta']);

        $this->assertSame(
            ['media', 'divider-a1', 'title', 'subtitle', 'excerpt', 'calendar', 'date', 'time', 'location'],
            $upgraded
        );
    }

    /**
     * A site that had the whole meta line switched off must not suddenly see
     * three fields it deliberately hid.
     */
    public function testUpgradeHiddenElementsExpandsLegacyMetaKey(): void
    {
        $upgraded = CardDesign::upgradeHiddenElements(['media', 'meta']);

        $this->assertSame(['media', 'date', 'time', 'location'], $upgraded);
    }

    public function testUpgradeHiddenElementsLeavesOtherListsAlone(): void
    {
        $this->assertSame(['media', 'excerpt'], CardDesign::upgradeHiddenElements(['media', 'excerpt']));
    }

    /**
     * The button color's label is derived, not configured — a dark brand color
     * has to get white text and a pale one black, or the admin picks a color
     * and gets an unreadable button.
     */
    public function testReadableTextOnPicksTheHigherContrastLabel(): void
    {
        $this->assertSame('#ffffff', CardDesign::readableTextOn('#111827'));
        $this->assertSame('#ffffff', CardDesign::readableTextOn('#2563eb'));
        $this->assertSame('#111827', CardDesign::readableTextOn('#ffffff'));
        $this->assertSame('#111827', CardDesign::readableTextOn('#fbbf24'));
    }

    public function testButtonColorEmitsBothStrongVariables(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'wide', '', '#2563eb');

        $this->assertSame('#2563eb', $variables['--ctp-color-button-strong']);
        $this->assertSame('#ffffff', $variables['--ctp-color-button-strong-text']);
    }

    public function testNoButtonColorLeavesTheNeutralDefaultInPlace(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded');

        $this->assertArrayNotHasKey('--ctp-color-button-strong', $variables);
        $this->assertArrayNotHasKey('--ctp-color-button-strong-text', $variables);
    }

    public function testSquareCornerStyleOverridesRadius(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'square');

        $this->assertSame('0px', $variables['--ctp-radius']);
    }

    /**
     * "rounded" must not set --ctp-radius at all (not just a falsy/empty
     * value) — CardDesign::styleAttribute()'s consumers rely on the wrapper's
     * own stylesheet-defined --ctp-radius (theme-derived) staying in effect
     * when the admin never touches the Design tab or explicitly picks "rounded".
     */
    public function testRoundedCornerStyleDoesNotSetRadius(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded');

        $this->assertArrayNotHasKey('--ctp-radius', $variables);
    }

    public function testInvalidElementOrderFallsBackToDefault(): void
    {
        $variables = CardDesign::cssVariables(['title', 'media'], 'rounded');

        $this->assertSame(0, $variables['--ctp-order-media']);
        $this->assertSame(2, $variables['--ctp-order-title']);
    }

    public function testStyleAttributeContainsAllOrderDeclarations(): void
    {
        $style = CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'square');

        $this->assertStringContainsString('--ctp-order-media:0;', $style);
        $this->assertStringContainsString('--ctp-order-content:1;', $style);
        $this->assertStringContainsString('--ctp-order-calendar:1;', $style);
        $this->assertStringContainsString('--ctp-order-title:2;', $style);
        $this->assertStringContainsString('--ctp-order-subtitle:3;', $style);
        $this->assertStringContainsString('--ctp-order-excerpt:4;', $style);
        $this->assertStringContainsString('--ctp-order-date:5;', $style);
        $this->assertStringContainsString('--ctp-order-time:6;', $style);
        $this->assertStringContainsString('--ctp-order-location:7;', $style);
        $this->assertStringContainsString('--ctp-radius:0px;', $style);
    }

    public function testIsValidOrderAcceptsAnyNumberOfSeparators(): void
    {
        $order = [
            'media', 'calendar', 'divider-a1', 'title', 'spacer-b2',
            'subtitle', 'excerpt', 'date', 'time', 'location',
        ];

        $this->assertTrue(CardDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsDuplicateSeparatorKeys(): void
    {
        $order = [...CardDesign::DEFAULT_ORDER, 'divider-a1', 'divider-a1'];

        $this->assertFalse(CardDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsMissingFixedElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location'];

        $this->assertFalse(CardDesign::isValidOrder($order));
    }

    public function testRenderSeparatorsOutputsDividerAndSpacerWithPositionalOrder(): void
    {
        $order = [
            'media', 'calendar', 'divider-a1', 'title', 'subtitle',
            'spacer-b2', 'excerpt', 'date', 'time', 'location',
        ];

        $html = CardDesign::renderSeparators($order);

        $this->assertStringContainsString('<hr class="ctp-events__divider" style="order:2;" />', $html);
        $this->assertStringContainsString(
            '<span class="ctp-events__spacer" aria-hidden="true" style="order:5;"></span>',
            $html
        );
    }

    public function testRenderSeparatorsReturnsEmptyStringWithoutAnySeparators(): void
    {
        $this->assertSame('', CardDesign::renderSeparators(CardDesign::DEFAULT_ORDER));
    }

    /**
     * "wide" is the implicit pre-feature default (matches each layout's own
     * hardcoded 16/9 or 16/10 aspect-ratio in frontend.css) — like "rounded"
     * corner style, it must emit nothing so untouched installs render exactly
     * as before this feature existed.
     */
    public function testWideMediaAspectRatioEmitsNoOverride(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'wide');

        $this->assertArrayNotHasKey('--ctp-media-aspect-ratio', $variables);
    }

    public function testNonDefaultMediaAspectRatioIsEmitted(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'square');

        $this->assertSame('1 / 1', $variables['--ctp-media-aspect-ratio']);
    }

    public function testUnknownMediaAspectRatioEmitsNoOverride(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'panoramic');

        $this->assertArrayNotHasKey('--ctp-media-aspect-ratio', $variables);
    }

    public function testEmptyAccentColorEmitsNoOverride(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'wide', '');

        $this->assertArrayNotHasKey('--ctp-accent', $variables);
    }

    public function testValidAccentColorIsEmitted(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'wide', '#ff8800');

        $this->assertSame('#ff8800', $variables['--ctp-accent']);
    }

    /**
     * A defensive backstop (see cssVariables()' docblock) — sanitizeSettings()
     * already validates accent_color with sanitize_hex_color() before it's ever
     * stored, this just guards a stale/foreign value reaching styleAttribute().
     */
    public function testMalformedAccentColorEmitsNoOverride(): void
    {
        $variables = CardDesign::cssVariables(CardDesign::DEFAULT_ORDER, 'rounded', 'wide', 'not-a-color');

        $this->assertArrayNotHasKey('--ctp-accent', $variables);
    }

    public function testStyleAttributeIncludesMediaRatioAndAccentColor(): void
    {
        $style = CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'rounded', 'tall', '#123456');

        $this->assertStringContainsString('--ctp-media-aspect-ratio:4 / 5;', $style);
        $this->assertStringContainsString('--ctp-accent:#123456;', $style);
    }

    public function testSanitizeHiddenElementsKeepsOnlyToggleableKeys(): void
    {
        $this->assertSame(
            ['media', 'time'],
            CardDesign::sanitizeHiddenElements(['media', 'title', 'time', 'unknown'])
        );
    }

    /**
     * "title" is deliberately not a TOGGLEABLE_KEYS member (see its own
     * docblock) — a card with no title at all isn't a supported state, so it
     * must be dropped even if a tampered POST tries to hide it.
     */
    public function testSanitizeHiddenElementsRejectsTitle(): void
    {
        $this->assertSame([], CardDesign::sanitizeHiddenElements(['title']));
    }

    public function testSanitizeHiddenElementsDeduplicates(): void
    {
        $this->assertSame(['subtitle'], CardDesign::sanitizeHiddenElements(['subtitle', 'subtitle']));
    }
}
