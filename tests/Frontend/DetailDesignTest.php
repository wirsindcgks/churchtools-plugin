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
        $order = ['description', 'media', 'title', 'calendar', 'meta', 'subtitle'];

        $this->assertTrue(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsMissingElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'meta'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsDuplicateElement(): void
    {
        $order = ['media', 'media', 'title', 'subtitle', 'meta', 'description'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }

    public function testIsValidOrderRejectsUnknownElement(): void
    {
        $order = ['media', 'calendar', 'title', 'subtitle', 'meta', 'unknown'];

        $this->assertFalse(DetailDesign::isValidOrder($order));
    }
}
