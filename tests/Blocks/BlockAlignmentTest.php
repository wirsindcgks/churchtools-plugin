<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Blocks;

use ChurchToolsPlugin\Blocks\EventListBlock;
use PHPUnit\Framework\TestCase;

/**
 * „Weite Breite" im Editor muss auf der Seite ankommen (Nutzerbefund
 * 2026-09-14: `columns="3"` ergab in einem 645px breiten Inhaltsbereich zwei
 * Spalten). Dafuer braucht es beides: die Ausrichtung in block.json, sonst
 * bietet der Editor sie nicht an, und den Wrapper um die Ausgabe, sonst
 * landet die Klasse `alignwide` nirgends.
 */
final class BlockAlignmentTest extends TestCase
{
    protected function tearDown(): void
    {
        \WP_Block_Supports::$block_to_render = null;
    }

    public function testBothBlocksOfferWideAndFullAlignment(): void
    {
        foreach (['event-list', 'group-list'] as $block) {
            $json = json_decode((string) file_get_contents(CTP_PLUGIN_DIR . 'blocks/' . $block . '/block.json'), true);

            $this->assertSame(['wide', 'full'], $json['supports']['align'] ?? null, $block);
        }
    }

    public function testTheOutputIsWrappedWhileABlockIsRendered(): void
    {
        \WP_Block_Supports::$block_to_render = ['blockName' => 'churchtools-plugin/group-list'];

        $this->assertSame(
            '<div class="wp-block-churchtools-plugin-group-list alignwide"><div class="ctp-events"></div></div>',
            EventListBlock::wrap('<div class="ctp-events"></div>')
        );
    }

    /** Ohne Block kein Wrapper - get_block_wrapper_attributes() liefe sonst ins Leere. */
    public function testOutsideABlockRenderTheOutputStaysAsItIs(): void
    {
        $this->assertSame('<p>x</p>', EventListBlock::wrap('<p>x</p>'));
    }

    public function testBothRenderCallbacksUseTheWrapper(): void
    {
        foreach (['EventListBlock', 'GroupListBlock'] as $class) {
            $source = (string) file_get_contents(CTP_PLUGIN_DIR . 'includes/Blocks/' . $class . '.php');

            $this->assertMatchesRegularExpression('/return (?:self|EventListBlock)::wrap\(/', $source, $class);
        }
    }
}
