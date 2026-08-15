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
        $this->assertSame(1, $variables['--ctp-order-title']);
        $this->assertSame(2, $variables['--ctp-order-subtitle']);
        $this->assertSame(3, $variables['--ctp-order-meta']);
        $this->assertArrayNotHasKey('--ctp-radius', $variables);
    }

    /**
     * Media is one flex item; title/subtitle/meta are three flex items inside
     * a single sibling "content" item, so the content block's own order can
     * only reflect the earliest of the three text positions — this pins that
     * derivation down for a non-default order.
     */
    public function testContentOrderIsTheMinimumOfTitleSubtitleAndMeta(): void
    {
        $variables = CardDesign::cssVariables(['title', 'media', 'subtitle', 'meta'], 'rounded');

        $this->assertSame(1, $variables['--ctp-order-media']);
        $this->assertSame(0, $variables['--ctp-order-content']);
        $this->assertSame(0, $variables['--ctp-order-title']);
        $this->assertSame(2, $variables['--ctp-order-subtitle']);
        $this->assertSame(3, $variables['--ctp-order-meta']);
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
        $this->assertSame(1, $variables['--ctp-order-title']);
    }

    public function testStyleAttributeContainsAllOrderDeclarations(): void
    {
        $style = CardDesign::styleAttribute(CardDesign::DEFAULT_ORDER, 'square');

        $this->assertStringContainsString('--ctp-order-media:0;', $style);
        $this->assertStringContainsString('--ctp-order-content:1;', $style);
        $this->assertStringContainsString('--ctp-order-title:1;', $style);
        $this->assertStringContainsString('--ctp-order-subtitle:2;', $style);
        $this->assertStringContainsString('--ctp-order-meta:3;', $style);
        $this->assertStringContainsString('--ctp-radius:0px;', $style);
    }
}
