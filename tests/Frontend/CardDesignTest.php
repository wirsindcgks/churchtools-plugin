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
        $this->assertSame(5, $variables['--ctp-order-meta']);
        $this->assertArrayNotHasKey('--ctp-radius', $variables);
    }

    /**
     * Media is one flex item; the other five elements are flex items inside a
     * single sibling "content" item, so the content block's own order can
     * only reflect the earliest of those five positions — this pins that
     * derivation down for a non-default order.
     */
    public function testContentOrderIsTheMinimumOfTheNonMediaElements(): void
    {
        $variables = CardDesign::cssVariables(
            ['title', 'media', 'calendar', 'subtitle', 'excerpt', 'meta'],
            'rounded'
        );

        $this->assertSame(1, $variables['--ctp-order-media']);
        $this->assertSame(0, $variables['--ctp-order-content']);
        $this->assertSame(0, $variables['--ctp-order-title']);
        $this->assertSame(2, $variables['--ctp-order-calendar']);
        $this->assertSame(3, $variables['--ctp-order-subtitle']);
        $this->assertSame(4, $variables['--ctp-order-excerpt']);
        $this->assertSame(5, $variables['--ctp-order-meta']);
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
        $this->assertStringContainsString('--ctp-order-meta:5;', $style);
        $this->assertStringContainsString('--ctp-radius:0px;', $style);
    }

    public function testIsValidOrderAcceptsAnyNumberOfSeparators(): void
    {
        $order = ['media', 'calendar', 'divider-a1', 'title', 'spacer-b2', 'subtitle', 'excerpt', 'meta'];

        $this->assertTrue(CardDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsDuplicateSeparatorKeys(): void
    {
        $order = [...CardDesign::DEFAULT_ORDER, 'divider-a1', 'divider-a1'];

        $this->assertFalse(CardDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsMissingFixedElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'meta'];

        $this->assertFalse(CardDesign::isValidOrder($order));
    }

    public function testRenderSeparatorsOutputsDividerAndSpacerWithPositionalOrder(): void
    {
        $order = ['media', 'calendar', 'divider-a1', 'title', 'subtitle', 'spacer-b2', 'excerpt', 'meta'];

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
            ['media', 'meta'],
            CardDesign::sanitizeHiddenElements(['media', 'title', 'meta', 'unknown'])
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
