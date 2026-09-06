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
        $order = ['description', 'media', 'share', 'title', 'calendar', 'location', 'time', 'date', 'subtitle'];

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
            ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description', 'share'],
            $upgraded
        );
        $this->assertTrue(DetailDesign::isValidOrder($upgraded));
    }

    /**
     * Der Fall, der jede Bestandsseite betrifft: Eine vor 1.17.0 gespeicherte
     * Reihenfolge kennt „share" nicht. Ohne das Anhängen fiele sie durch
     * isValidOrder() und die Detailansicht schnappte auf DEFAULT_ORDER zurück —
     * der Betreiber verlöre also seine eingestellte Anordnung, ohne etwas
     * getan zu haben.
     */
    public function testUpgradeOrderAppendsTheShareKeyToAnOrderStoredBeforeItExisted(): void
    {
        $stored = ['description', 'media', 'title', 'calendar', 'location', 'time', 'date', 'subtitle'];

        $this->assertFalse(DetailDesign::isValidOrder($stored), 'Vorbedingung: ohne „share" ist die Reihenfolge unvollständig');

        $upgraded = DetailDesign::upgradeOrder($stored);

        $this->assertSame(array_merge($stored, ['share']), $upgraded);
        $this->assertTrue(DetailDesign::isValidOrder($upgraded));
    }

    /**
     * Läuft bei jedem Lesen (SettingsPage::get()), nicht als einmalige
     * Migration — ein zweiter Durchlauf darf deshalb keinen zweiten Knopf
     * erzeugen. Und eine bereits verschobene Position bleibt, wo der Betreiber
     * sie hingezogen hat: Angehängt wird nur, was fehlt.
     */
    public function testUpgradeOrderLeavesAnExistingShareKeyWhereItIs(): void
    {
        $order = ['media', 'calendar', 'title', 'share', 'subtitle', 'date', 'time', 'location', 'description'];

        $this->assertSame($order, DetailDesign::upgradeOrder($order));
        $this->assertSame($order, DetailDesign::upgradeOrder(DetailDesign::upgradeOrder($order)));
    }
}
