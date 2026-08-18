<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DetailDesign;
use PHPUnit\Framework\TestCase;

final class DetailDesignTest extends TestCase
{
    public function testDefaultOrderIsValid(): void
    {
        $this->assertTrue(DetailDesign::isValidOrder(DetailDesign::DEFAULT_ORDER));
    }

    public function testIsValidOrderAcceptsAnyPermutation(): void
    {
        $order = ['description', 'media', 'title', 'calendar', 'location', 'time', 'date', 'subtitle'];

        $this->assertTrue(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsMissingElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsDuplicateElement(): void
    {
        $order = ['media', 'media', 'title', 'subtitle', 'date', 'time', 'location', 'description'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsUnknownElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'unknown'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }

    /**
     * The detail order is stored separately from the card order, so it can
     * still arrive on the pre-split key set independently of it.
     */
    public function testUpgradeOrderExpandsLegacyMetaKeyInPlace(): void
    {
        $upgraded = DetailDesign::upgradeOrder(['media', 'calendar', 'title', 'subtitle', 'meta', 'description']);

        $this->assertSame(
            ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description'],
            $upgraded
        );
        $this->assertTrue(DetailDesign::isValidOrder($upgraded));
    }
}
