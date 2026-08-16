<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

final class Assets
{
    private const STYLE_HANDLE = 'ctp-frontend';
    private const SCRIPT_HANDLE = 'ctp-frontend-filter';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue']);
    }

    /**
     * Only needed for the shortcode/WPBakery path — the Gutenberg block gets the
     * same frontend.css/frontend.js automatically, in both the block editor
     * (powering the ServerSideRender live preview, see index.js) and the front end,
     * via block.json's own "style"/"script" fields. Those are registered at block
     * registration time, which sidesteps a timing problem this class used to hit
     * when it tried to enqueue the stylesheet for the editor manually: styles
     * enqueued from an enqueue_block_editor_assets callback can land after
     * admin_print_styles() already ran, in which case the <link> silently never
     * gets printed — WordPress has no "flush late styles" fallback for wp-admin the
     * way it does for footer scripts. block.json-declared assets don't have that
     * problem since WordPress enqueues them itself at the correct point.
     *
     * Has to decide *before* wp_head() whether the current request needs the
     * stylesheet — a style enqueued later (e.g. from inside
     * EventListRenderer::render(), which runs during the_content filtering, i.e.
     * after wp_head already printed) would be registered but never actually
     * output, for the same reason.
     */
    public function maybeEnqueue(): void
    {
        if (!$this->currentRequestUsesShortcode()) {
            return;
        }

        wp_enqueue_style(self::STYLE_HANDLE, CTP_PLUGIN_URL . 'assets/css/frontend.css', [], CTP_VERSION);
        wp_enqueue_script(self::SCRIPT_HANDLE, CTP_PLUGIN_URL . 'assets/js/frontend.js', [], CTP_VERSION, true);
    }

    /**
     * Covers the shortcode and the WPBakery element, which maps onto the same
     * [ctp_events] shortcode string — the Gutenberg block is excluded here since
     * block.json's "style"/"script" fields already cover it (see maybeEnqueue()).
     * A page that renders the shortcode from outside post_content (e.g. a text
     * widget, a template part calling do_shortcode() directly) won't be detected
     * here and simply renders without the stylesheet — accepted gap rather than a
     * fragile broader heuristic.
     *
     * Also covers EventDetailPage's virtual "own page" route — it has no
     * WP_Post/post_content to scan for the shortcode string, but needs the same
     * stylesheet/script (the popup JS in particular, since the detail page can
     * itself be reached from a page whose own [ctp_events] uses the popup).
     */
    private function currentRequestUsesShortcode(): bool
    {
        if (EventDetailPage::isDetailRequest()) {
            return true;
        }

        $post = get_post();

        return $post instanceof \WP_Post && has_shortcode($post->post_content, 'ctp_events');
    }
}
