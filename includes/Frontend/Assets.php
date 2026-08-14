<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

final class Assets
{
    private const HANDLE = 'ctp-frontend';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue']);
    }

    /**
     * Has to decide *before* wp_head() whether the current request needs the
     * stylesheet — a style enqueued later (e.g. from inside
     * EventListRenderer::render(), which runs during the_content filtering, i.e.
     * after wp_head already printed) would be registered but never actually
     * output, since WordPress has no built-in fallback that flushes late-enqueued
     * styles into wp_footer the way it does for scripts.
     */
    public function maybeEnqueue(): void
    {
        if (!$this->currentRequestMayUseEvents()) {
            return;
        }

        wp_enqueue_style(self::HANDLE, CTP_PLUGIN_URL . 'assets/css/frontend.css', [], CTP_VERSION);
    }

    /**
     * Covers the shortcode (incl. the WPBakery element, which maps onto the same
     * [ctp_events] shortcode string) and the Gutenberg block. A page that renders
     * the shortcode from outside post_content (e.g. a text widget, a template part
     * calling do_shortcode() directly) won't be detected here and simply renders
     * without the stylesheet — accepted gap rather than a fragile broader heuristic.
     */
    private function currentRequestMayUseEvents(): bool
    {
        $post = get_post();

        if (!$post instanceof \WP_Post) {
            return false;
        }

        return has_shortcode($post->post_content, 'ctp_events') || has_block('churchtools-plugin/event-list', $post);
    }
}
