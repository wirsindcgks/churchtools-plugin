<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Db\EventRepository;

/**
 * Die Einzelansicht eines Termins als eigene Adresse. Es gibt sie in zwei
 * Ausführungen, und welche gilt, entscheidet die Einstellung „Terminseite" im
 * Design-Tab:
 *
 *   ohne Elternseite (Voreinstellung, Verhalten bis 1.4.1)
 *       /churchtools-termin/<id>/ — eine virtuelle Rewrite-Route ohne echten
 *       WP_Post (siehe „kein Custom Post Type" in docs/ARCHITECTURE.md). Der
 *       Termin wird auf template_redirect zwischen get_header() und
 *       get_footer() ausgegeben, der Request endet dort.
 *
 *   mit Elternseite
 *       /<pfad-der-seite>/gottesdienst-06-09-2026/ — der Termin wird zum
 *       *Inhalt* dieser Seite. WordPress rendert eine ganz normale Seite, wir
 *       tauschen nur ihren Inhalt und ihre Überschrift aus.
 *
 * Der Unterschied zwischen beiden ist größer, als die Adresse vermuten lässt,
 * und er ist der eigentliche Grund für die zweite Ausführung. Die erste endet
 * den Request auf `template_redirect` — also *vor* dem Template-Loader. In dem
 * sitzt aber `locate_block_template()`, und die Funktion hängt nebenbei das
 * Viewport-Tag an wp_head und lädt die Block-Vorlage des Themes. Auf einem
 * Block-Theme (Twenty Twenty-Two aufwärts, seit 2022 der Standard) bekommt die
 * erste Ausführung deshalb weder die Vorlage des Themes noch dessen Kopf- und
 * Fußbereich: `get_header()` findet dort keine header.php und lädt die
 * Notfassung aus wp-includes/theme-compat/ — eine Datei, die WordPress seit
 * 3.0 als veraltet führt. Das Viewport-Tag reicht maybeRenderViewportMetaTag()
 * unten von Hand nach; alles andere kann sie nicht nachholen.
 *
 * Die zweite Ausführung hat dieses Problem nicht, weil sie es gar nicht erst
 * hat: Es gibt einen echten Beitrag, der Template-Loader läuft, das Theme
 * rendert seine Seite. Deshalb ist die Elternseite nicht nur eine hübschere
 * Adresse, sondern die Antwort auf „fügt sich nicht ins Theme ein".
 */
final class EventDetailPage
{
    private const QUERY_VAR = 'ctp_event';
    private const SLUG_QUERY_VAR = 'ctp_event_slug';
    private const SLUG = 'churchtools-termin';
    /**
     * Bump this when the rewrite rule itself changes, to force one more
     * flush_rewrite_rules() on the next request — same self-migrating idea as
     * Installer::DB_VERSION/maybeUpgrade(), no reactivation needed.
     */
    private const REWRITE_VERSION = '2';

    /**
     * Der Termin, auf den die aufgerufene Adresse zeigt, sobald
     * maybeHostOnPage() ihn aufgelöst hat — die Filter darunter (Inhalt,
     * Überschrift, Dokumenttitel, Canonical) brauchen ihn alle, und ihn dafür
     * viermal nachzuschlagen wäre viermal dieselbe Abfrage.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $hostedEvent = null;

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerRewriteRule']);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_filter('request', [self::class, 'resolveHostedRequest']);
        add_action('wp', [self::class, 'maybeHostOnPage']);
        add_action('template_redirect', [self::class, 'maybeRenderDetail']);
    }

    public static function registerRewriteRule(): void
    {
        add_rewrite_rule('^' . self::SLUG . '/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');

        $pageId = self::hostPageId();
        $pageUri = $pageId > 0 ? (string) get_page_uri($pageId) : '';

        if ($pageUri !== '') {
            // `[^/]+` statt `.+`: Der Slug ist genau ein Pfadsegment. Mit `.+`
            // würde die Regel auch echte Unterseiten der Elternseite
            // schlucken, und die gehören WordPress.
            add_rewrite_rule(
                '^' . preg_quote($pageUri, '/') . '/([^/]+)/?$',
                'index.php?page_id=' . $pageId . '&' . self::SLUG_QUERY_VAR . '=$matches[1]',
                'top'
            );
        }

        // Der Elternseiten-Teil des Regelsatzes hängt an einer Einstellung und
        // am Slug der Seite — beides kann sich jederzeit ändern, ohne dass
        // dieses Plugin aktualisiert wird. Deshalb steht beides mit im
        // Versionsstempel: Wird die Seite gewechselt oder umbenannt, sind die
        // Rewrite-Regeln beim nächsten Request von selbst wieder richtig.
        $version = self::REWRITE_VERSION . '|' . $pageId . '|' . $pageUri;
        if (get_option('ctp_rewrite_version') !== $version) {
            flush_rewrite_rules();
            update_option('ctp_rewrite_version', $version);
        }
    }

    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::SLUG_QUERY_VAR;

        return $vars;
    }

    /**
     * Ob dieser Request die Einzelansicht eines Termins ist — beide
     * Ausführungen. Assets::enqueue() hängt daran, ob Stylesheet und Skript des
     * Plugins geladen werden; ohne den zweiten Zweig stünde der Termin auf der
     * Elternseite ohne jede Formatierung da.
     */
    public static function isDetailRequest(): bool
    {
        return (int) get_query_var(self::QUERY_VAR) > 0 || self::$hostedEvent !== null;
    }

    /**
     * Die Adresse eines Termins. Mit Elternseite die sprechende, ohne sie die
     * bisherige ID-Adresse — und bei „Plain"-Permalinks in beiden Fällen die
     * Query-String-Form, denn Rewrite-Regeln greifen dort gar nicht.
     */
    public static function urlForEvent(array $event): string
    {
        $id = (int) ($event['id'] ?? 0);
        $pageId = self::hostPageId();

        if ($pageId <= 0) {
            return self::url($id);
        }

        if (get_option('permalink_structure') === '') {
            return add_query_arg(
                self::SLUG_QUERY_VAR,
                EventSlug::forEvent($event),
                (string) get_permalink($pageId)
            );
        }

        return trailingslashit((string) get_permalink($pageId)) . EventSlug::forEvent($event) . '/';
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

    /**
     * Die Elternseiten-Ausführung. Läuft auf `wp`, also nachdem die Abfrage
     * steht und bevor das Template geladen wird: früh genug, um auf 404 zu
     * schalten und Filter zu setzen, spät genug, dass WordPress die Seite
     * bereits aufgelöst hat.
     */
    /**
     * Die Regel `^termine/([^/]+)/?$` steht mit `top` vor den eigenen Regeln
     * von WordPress — sie muss dort stehen, denn die generische Seitenregel
     * darunter fängt ohnehin alles. Damit fängt sie aber auch, was gar kein
     * Termin ist: eine echte Unterseite `/termine/anmeldung/`, die zweite Seite
     * eines langen Seiteninhalts `/termine/2/`. Ohne diesen Filter hätte das
     * Setzen der Einstellung solche Seiten in eine 404 verwandelt — und zwar
     * still, denn wer die Einstellung setzt, prüft danach seine Termine und
     * nicht seine Unterseiten.
     *
     * Der Filter läuft vor der Abfrage und gibt zurück, was nicht uns gehört:
     * Alles, was nicht auf `-tt-mm-jjjj` endet, ist keine unserer Adressen und
     * geht als Seitenpfad an WordPress zurück. Alles, was danach aussieht, aber
     * keinen Termin trifft, bekommt dieselbe zweite Chance, bevor es 404 wird.
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public static function resolveHostedRequest(array $vars): array
    {
        $slug = (string) ($vars[self::SLUG_QUERY_VAR] ?? '');
        if ($slug === '' || self::hostPageId() <= 0) {
            return $vars;
        }

        if (EventSlug::parse($slug) !== null) {
            $event = self::findBySlug($slug);
            if ($event !== null) {
                self::$hostedEvent = $event;

                return $vars;
            }
        }

        return self::handBackToWordPress($slug);
    }

    /**
     * @return array<string, mixed>
     */
    private static function handBackToWordPress(string $slug): array
    {
        $uri = (string) get_page_uri(self::hostPageId());

        // Genau das, was WordPress' eigene Seitenregel
        // `(.?.+?)(?:/([0-9]+))?/?$` aus diesem Pfad gemacht hätte: eine reine
        // Zahl ist die Seitennummer eines langen Inhalts, alles andere ein
        // Seitenpfad. Findet sich darunter nichts, wird es hier zur 404 — wie
        // ohne unsere Regel auch.
        if (ctype_digit($slug)) {
            return ['pagename' => $uri, 'page' => $slug];
        }

        return ['pagename' => $uri . '/' . $slug];
    }

    public static function maybeHostOnPage(): void
    {
        if (self::$hostedEvent === null) {
            // Der Slug hat die Regel ausgelöst, aber weder einen Termin noch
            // eine echte Seite getroffen (resolveHostedRequest() oben hätte
            // sonst umgeschrieben). Erfundene Adresse also.
            if ((string) get_query_var(self::SLUG_QUERY_VAR) !== '') {
                self::send404();
            }

            return;
        }

        $event = self::$hostedEvent;

        // WordPress schickt eine Seite, deren Adresse nicht ihrem Permalink
        // entspricht, von sich aus per 301 auf den Permalink — und genau das
        // ist diese Adresse. Ohne diese Zeile landete jeder Terminaufruf sofort
        // wieder auf der Elternseite.
        remove_action('template_redirect', 'redirect_canonical');

        add_filter('the_content', [self::class, 'filterHostedContent'], 20);
        add_filter('the_title', [self::class, 'filterHostedTitle'], 10, 2);
        add_filter('render_block', [self::class, 'filterHostedBlock'], 10, 3);
        add_filter('get_canonical_url', [self::class, 'filterHostedCanonicalUrl'], 10, 2);
        add_filter(
            'pre_get_document_title',
            static fn (): string => sprintf('%s – %s', $event['title'], get_bloginfo('name'))
        );
    }

    public static function filterHostedContent(string $content): string
    {
        if (self::$hostedEvent === null || get_the_ID() !== self::hostPageId()) {
            return $content;
        }

        return (new EventListRenderer())->renderDetail(self::$hostedEvent, true);
    }

    /**
     * Die Überschrift der Elternseite („Termine") weicht dem Termin: Sonst
     * stünde sie als Überschrift erster Ordnung über der Überschrift erster
     * Ordnung des Termins. Nur im Hauptdurchlauf und nur für diese eine Seite —
     * Menüeinträge und Widgets laufen durch denselben Filter.
     */
    public static function filterHostedTitle(string $title, $postId = 0): string
    {
        if (self::$hostedEvent === null || (int) $postId !== self::hostPageId()) {
            return $title;
        }

        return in_the_loop() && is_main_query() ? '' : $title;
    }

    /**
     * Dasselbe für Block-Themes. Dort steht die Überschrift nicht als
     * `the_title()` im Template, sondern als eigener Block — und der ruft
     * get_the_title() außerhalb des Loops auf, wo der Filter oben bewusst nicht
     * greift.
     *
     * Der Kontext des Blocks entscheidet mit: Steht auf derselben Seite eine
     * Abfrage-Schleife, die *andere* Beiträge auflistet, tragen deren
     * Titel-Blöcke die ID ihres eigenen Beitrags — die sollen ihre Überschrift
     * behalten. Nur der Titel-Block der Elternseite selbst weicht.
     *
     * @param array<string, mixed> $block
     * @param mixed                $instance WP_Block, sobald WordPress ihn mitgibt.
     */
    public static function filterHostedBlock(string $content, array $block, $instance = null): string
    {
        if (self::$hostedEvent === null || ($block['blockName'] ?? '') !== 'core/post-title') {
            return $content;
        }

        $contextId = (int) ($instance->context['postId'] ?? 0);

        return $contextId === 0 || $contextId === self::hostPageId() ? '' : $content;
    }

    public static function filterHostedCanonicalUrl(string $canonicalUrl, $post): string
    {
        if (self::$hostedEvent === null || (int) ($post->ID ?? 0) !== self::hostPageId()) {
            return $canonicalUrl;
        }

        return self::urlForEvent(self::$hostedEvent);
    }

    /**
     * Die Ausführung ohne Elternseite — und, sobald eine gesetzt ist, die
     * Weiterleitung der alten Adressen auf die neuen. Ohne diese Weiterleitung
     * wären alle bereits verschickten und verlinkten Termin-Adressen tot,
     * sobald jemand die Einstellung setzt.
     */
    public static function maybeRenderDetail(): void
    {
        $id = (int) get_query_var(self::QUERY_VAR);
        if ($id <= 0) {
            return;
        }

        $event = (new EventRepository())->find($id);

        // Guards against leaking a disabled/removed calendar's events via a
        // guessable sequential ID — the same privacy boundary every other
        // frontend surface (shortcode, filter, popup) already respects by only
        // ever querying enabled calendars in the first place.
        if ($event === null || !self::isVisible($event)) {
            self::send404();
            include get_query_template('404');
            exit;
        }

        if (self::hostPageId() > 0) {
            wp_safe_redirect(self::urlForEvent($event), 301);
            exit;
        }

        add_filter(
            'pre_get_document_title',
            static fn (): string => sprintf('%s – %s', $event['title'], get_bloginfo('name'))
        );
        add_action('wp_head', [self::class, 'maybeRenderViewportMetaTag'], 0);

        status_header(200);
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderDetail() escapes every field itself while building the markup (see partials/event-detail-content.php), same trust boundary as EventListRenderer::render()'s return value being echoed by Shortcode::render().
        echo (new EventListRenderer())->renderDetail($event);
        get_footer();
        exit;
    }

    /**
     * Block-Themes (Twenty Twenty-Two aufwärts, seit 2022 der Standard)
     * bekommen ihr <meta name="viewport"> nicht aus dem Theme, sondern von
     * WordPress selbst: locate_block_template() hängt dafür
     * _block_template_viewport_meta_tag() an wp_head. Diese Funktion läuft im
     * Template-Loader — also *nach* template_redirect, und damit nach dem
     * exit() oben. Auf einem Block-Theme hatte diese Seite deshalb gar kein
     * Viewport-Tag, und Telefone bauten sie in 980px Breite auf und zoomten
     * heraus: Alles korrekt angeordnet, nur unlesbar klein. Sichtbar wird das
     * nur auf einem echten Telefon oder in der Geräteansicht, nicht in einem
     * schmal gezogenen Fenster — deshalb ist es bis 1.3.1 nicht aufgefallen.
     *
     * Nur für Block-Themes, und nur wenn WordPress das Tag nicht doch selbst
     * beisteuert: Klassische Themes schreiben es in ihre header.php, die
     * get_header() unten ganz normal lädt, und zwei Viewport-Tags sind
     * schlimmer als eins.
     *
     * Die Elternseiten-Ausführung braucht das alles nicht: Dort läuft der
     * Template-Loader, und WordPress setzt das Tag selbst.
     */
    public static function maybeRenderViewportMetaTag(): void
    {
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return;
        }

        if (has_action('wp_head', '_block_template_viewport_meta_tag') !== false) {
            return;
        }

        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
    }

    /**
     * Die Seite, unter deren Adresse die Termine liegen — 0, solange keine
     * gesetzt ist oder die gesetzte nicht mehr veröffentlicht ist. Die zweite
     * Hälfte ist wichtiger, als sie klingt: Wird die Elternseite in den
     * Papierkorb gelegt, fielen sonst alle Termin-Adressen auf eine Seite, die
     * es nicht mehr gibt. So fallen sie stattdessen auf die ID-Adresse zurück.
     */
    private static function hostPageId(): int
    {
        $pageId = (int) (SettingsPage::get()['detail_page_id'] ?? 0);

        return $pageId > 0 && get_post_status($pageId) === 'publish' && self::mayHostEvents($pageId)
            ? $pageId
            : 0;
    }

    /**
     * Startseite und Beitragsseite scheiden als Elternseite aus, und zwar aus
     * demselben Grund in zwei Schärfegraden.
     *
     * Die Startseite hat als Pfad ihren Slug, als Adresse aber `/`. Die Regel
     * `^([^/]+)/?$`, die daraus entstünde, stünde mit `top` vor *allem* — sie
     * fischte jede Adresse der obersten Ebene ab, bevor WordPress sie zu sehen
     * bekommt. Ein Fehler, der die ganze Website beträfe und nicht nur die
     * Termine.
     *
     * Die Beitragsseite ist die mildere Fassung desselben: Steht die
     * Permalink-Struktur auf `/blog/%postname%/`, läge unsere Regel über jedem
     * einzelnen Beitrag.
     */
    private static function mayHostEvents(int $pageId): bool
    {
        return $pageId !== (int) get_option('page_on_front')
            && $pageId !== (int) get_option('page_for_posts');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findBySlug(string $slug): ?array
    {
        $parsed = EventSlug::parse($slug);
        if ($parsed === null) {
            return null;
        }

        $enabledIds = SettingsPage::getEnabledCalendarIds();
        if ($enabledIds === []) {
            // Ohne diese Zeile wäre die Kalenderbedingung in findOnDate() leer
            // und die Abfrage gäbe *jeden* Termin des Tages zurück — die
            // Umkehrung dessen, was „kein Kalender freigegeben" heißt.
            return null;
        }

        $candidates = (new EventRepository())->findOnDate($parsed['date'], $enabledIds);
        foreach ($candidates as $candidate) {
            if (EventSlug::matchesTitle($candidate, $parsed['title']) && self::isVisible($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isVisible(array $event): bool
    {
        return in_array((int) $event['ct_calendar_id'], SettingsPage::getEnabledCalendarIds(), true);
    }

    private static function send404(): void
    {
        global $wp_query;

        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}
