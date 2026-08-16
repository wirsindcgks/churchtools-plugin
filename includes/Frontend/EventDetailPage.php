<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;

/**
 * Virtual "own page" route for a single event's detail view — no Custom Post
 * Type (see plan.md's "kein Custom Post Type" architecture decision), so the
 * URL is a plain rewrite rule handled on template_redirect instead of a real
 * WP_Post. get_header()/get_footer() still run, so the page uses the active
 * theme's chrome; only the content in between is this plugin's own markup.
 * Themes whose header/footer expect a real post in the loop (breadcrumbs,
 * "related posts", …) may show little there — an accepted limitation of the
 * no-CPT architecture, not something this class works around.
 */
final class EventDetailPage
{
    private const QUERY_VAR = 'ctp_event';
    private const SLUG = 'churchtools-termin';
    /**
     * Bump this when the rewrite rule itself changes, to force one more
     * flush_rewrite_rules() on the next request — same self-migrating idea as
     * Installer::DB_VERSION/maybeUpgrade(), no reactivation needed.
     */
    private const REWRITE_VERSION = '1';

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerRewriteRule']);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [self::class, 'maybeRenderDetail']);
    }

    public static function registerRewriteRule(): void
    {
        add_rewrite_rule('^' . self::SLUG . '/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');

        if (get_option('ctp_rewrite_version') !== self::REWRITE_VERSION) {
            flush_rewrite_rules();
            update_option('ctp_rewrite_version', self::REWRITE_VERSION);
        }
    }

    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    public static function isDetailRequest(): bool
    {
        return (int) get_query_var(self::QUERY_VAR) > 0;
    }

    /**
     * Falls back to a plain query-string URL when the site uses "Plain"
     * permalinks (get_option('permalink_structure') === '') — the rewrite rule
     * registered above only takes effect once pretty permalinks are enabled.
     */
    public static function url(int $id): string
    {
        if (get_option('permalink_structure') === '') {
            return add_query_arg(self::QUERY_VAR, $id, home_url('/'));
        }

        return home_url('/' . self::SLUG . '/' . $id . '/');
    }

    public static function maybeRenderDetail(): void
    {
        $id = (int) get_query_var(self::QUERY_VAR);
        if ($id <= 0) {
            return;
        }

        $event = (new EventRepository())->find($id);
        $enabledIds = SettingsPage::getEnabledCalendarIds();

        // Guards against leaking a disabled/removed calendar's events via a
        // guessable sequential ID — the same privacy boundary every other
        // frontend surface (shortcode, filter, popup) already respects by only
        // ever querying enabled calendars in the first place.
        if ($event === null || !in_array((int) $event['ct_calendar_id'], $enabledIds, true)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            include get_query_template('404');
            exit;
        }

        add_filter(
            'pre_get_document_title',
            static fn (): string => sprintf('%s – %s', $event['title'], get_bloginfo('name'))
        );

        status_header(200);
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderDetail() escapes every field itself while building the markup (see partials/event-detail-content.php), same trust boundary as EventListRenderer::render()'s return value being echoed by Shortcode::render().
        echo (new EventListRenderer())->renderDetail($event);
        get_footer();
        exit;
    }
}
