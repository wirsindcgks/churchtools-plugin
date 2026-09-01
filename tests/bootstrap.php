<?php

/**
 * Deliberately not a full WordPress test bootstrap (wp-phpunit, a test database,
 * wp-load.php, ...) — these tests target the plugin's pure/near-pure logic
 * (Crypto roundtrip, input sanitization, API response mapping), not anything that
 * needs a running WordPress. Pulling in the full WP test suite for that would be a
 * lot of infrastructure (and CI time) for functions that don't touch the database,
 * hooks, or output. Instead, only the handful of WP functions these specific
 * classes call are stubbed below — see each stub's comment for why it's needed.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Die Testklassen selbst laedt PHPUnit ueber die Verzeichnisliste in
// phpunit.xml.dist; Helfer wie dieser stehen in keiner solchen Liste und
// haben keinen autoload-dev-Eintrag, also von Hand.
require __DIR__ . '/Support/SqliteWpdb.php';

// Crypto::key() derives its encryption key from AUTH_KEY when defined, so a fixed
// test value keeps encrypt()/decrypt() deterministic without needing wp_salt().
define('AUTH_KEY', 'phpunit-test-auth-key-do-not-use-in-production');

// Das Plugin-Verzeichnis - SettingsPage::changelogReleases() liest die
// mitgelieferte CHANGELOG.md darueber ein (siehe ChangelogParserTest).
define('CTP_PLUGIN_DIR', dirname(__DIR__) . '/');

/**
 * In-memory stand-in for the options table, only as deep as SettingsPage::get()
 * needs: reading a single named option. Tests populate it via ctp_test_set_option()
 * before exercising code that calls SettingsPage::get()/resolveCalendarIds().
 */
$GLOBALS['ctp_test_options'] = [];

function ctp_test_set_option(string $name, $value): void
{
    $GLOBALS['ctp_test_options'][$name] = $value;
}

function ctp_test_reset_options(): void
{
    $GLOBALS['ctp_test_options'] = [];
}

/**
 * Ein API-Key in der Form, in der Crypto ihn vor 0.12.4 abgelegt hat:
 * base64(iv . ciphertext), ohne das Praefix, an dem Crypto::isCiphertext()
 * heute einen eigenen Ciphertext erkennt. Zwei davon ineinander sind genau
 * der Zustand, den WordPress' doppelter Sanitizer-Aufruf beim allerersten
 * Speichern erzeugt hat - siehe SettingsPage::storedApiKey().
 */
function ctp_test_legacy_encrypt(string $plaintext): string
{
    $iv = openssl_random_pseudo_bytes(16);

    return base64_encode(
        $iv . openssl_encrypt($plaintext, 'aes-256-cbc', hash('sha256', AUTH_KEY, true), OPENSSL_RAW_DATA, $iv)
    );
}

function get_option(string $name, $default = false)
{
    return $GLOBALS['ctp_test_options'][$name] ?? $default;
}

/**
 * Reduzierter Nachbau von WordPress' sanitize_title() — genau der Teil, den
 * Frontend\EventSlug braucht, und bewusst nicht mehr.
 *
 * Das Original (wp-includes/formatting.php) ruft im Standardkontext „save"
 * erst remove_accents() und dann sanitize_title_with_dashes(). Dessen letzte
 * vier Zeilen sind hier eins zu eins nachgebaut, und für reines ASCII ist das
 * Ergebnis identisch. Was fehlt, ist alles rund um Nicht-ASCII: remove_accents()
 * bildet Umlaute je nach Sprache verschieden ab (ä→a in en_US, ä→ae in de_DE),
 * und utf8_uri_encode() lässt aus allem übrigen Prozent-Oktette werden, die
 * das Original stehen lässt.
 *
 * Deshalb prüft EventSlugTest exakte Zeichenketten nur an ASCII-Titeln; für
 * alles andere prüft er die Rundreise (bauen → zerlegen → wiederfinden), und
 * die gilt unabhängig davon, wie ein Zeichen abgebildet wird.
 */
function sanitize_title(string $title): string
{
    $title = strtolower($title);
    $title = str_replace('.', '-', $title);
    $title = (string) preg_replace('/[^a-z0-9 _-]/', '', $title);
    $title = (string) preg_replace('/\s+/', '-', $title);
    $title = (string) preg_replace('|-+|', '-', $title);

    return trim($title, '-');
}

/**
 * Beiträge, so weit die Prüfung in SettingsPage::sanitizeSettings() für
 * „detail_page_id" sie befragt: Typ und Status. Tests legen sie über
 * ctp_test_set_post() an.
 */
$GLOBALS['ctp_test_posts'] = [];

function ctp_test_set_post(int $id, string $type, string $status, string $uri = ''): void
{
    $GLOBALS['ctp_test_posts'][$id] = ['type' => $type, 'status' => $status, 'uri' => $uri];
}

function get_post_type($post = null)
{
    return $GLOBALS['ctp_test_posts'][(int) $post]['type'] ?? false;
}

function get_post_status($post = null)
{
    return $GLOBALS['ctp_test_posts'][(int) $post]['status'] ?? false;
}

function get_page_uri($post = null)
{
    return $GLOBALS['ctp_test_posts'][(int) $post]['uri'] ?? false;
}

/**
 * SyncEngine::getLastError()/run() read and write the "ctp_last_sync_error" option
 * through update_option()/delete_option(), not just get_option() — needed so tests
 * can exercise that persisted-error round trip the same way the real option table
 * would behave.
 */
function update_option(string $name, $value): bool
{
    $GLOBALS['ctp_test_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['ctp_test_options'][$name]);

    return true;
}

/**
 * Mirrors WP core's actual merge behaviour (array args override matching default
 * keys, defaults fill in the rest) closely enough for SettingsPage::get()'s use —
 * it always passes two arrays, never the string/object forms wp_parse_args() also
 * accepts.
 */
function wp_parse_args($args, $defaults = [])
{
    $parsedArgs = is_array($args) ? $args : (array) $args;

    return array_merge($defaults, $parsedArgs);
}

/**
 * SyncEngine::toMysqlDate() converts ChurchTools' UTC timestamps into this
 * timezone before storing them. Fixed to a non-UTC zone so a test asserting on the
 * converted value would actually catch a missing/broken conversion.
 */
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Berlin');
}

// WP-Kernkonstanten, die hier zur Klassenladezeit gebraucht werden:
// EventQueryCache::TTL rechnet mit der ersten, SyncHealthNotice::MIN_STALE_SECONDS
// mit der dritten, und Installer::intervalSeconds() faellt auf die zweite zurueck.
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

/**
 * In-memory stand-in for transients, only as deep as EventQueryCacheTest needs:
 * pre-seeding a cache-hit and reading it back. No expiration handling — nothing
 * here exercises TTL behaviour, that's WP core's job, not this plugin's.
 */
$GLOBALS['ctp_test_transients'] = [];

function get_transient(string $key)
{
    return $GLOBALS['ctp_test_transients'][$key] ?? false;
}

function set_transient(string $key, $value, int $expiration = 0): bool
{
    $GLOBALS['ctp_test_transients'][$key] = $value;

    return true;
}

function ctp_test_reset_transients(): void
{
    $GLOBALS['ctp_test_transients'] = [];
}

/**
 * SettingsPage::sanitizeSettings() calls this directly for "accent_color" (and
 * indirectly, via sanitizeCalendars(), for each calendar's "color") — a faithful
 * port of WP core's own implementation (3/6-digit hex or empty string, null
 * otherwise), not a simplified stand-in, since the exact null-vs-'' distinction is
 * what sanitizeSettings() branches on.
 */
/**
 * WordPress' absint(): Betrag der Ganzzahl. Gebraucht von
 * SettingsPage::sanitizeCalendars().
 *
 * @param mixed $maybeInt
 */
function absint($maybeInt): int
{
    return abs((int) $maybeInt);
}

function sanitize_hex_color(string $color): ?string
{
    if ($color === '') {
        return '';
    }

    return (bool) preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color) ? $color : null;
}

/**
 * SyncEngine::syncSeriesImage() reads the "_ctp_source_image_url" postmeta it
 * stamped onto an imported attachment to decide whether the image changed.
 * Tests seed it via ctp_test_set_post_meta().
 */
$GLOBALS['ctp_test_post_meta'] = [];

function ctp_test_set_post_meta(int $postId, string $key, $value): void
{
    $GLOBALS['ctp_test_post_meta'][$postId][$key] = $value;
}

function ctp_test_reset_post_meta(): void
{
    $GLOBALS['ctp_test_post_meta'] = [];
}

function get_post_meta(int $postId, string $key = '', bool $single = false)
{
    return $GLOBALS['ctp_test_post_meta'][$postId][$key] ?? '';
}

/**
 * syncSeriesImage() deletes the attachment of a series whose image disappeared
 * on the ChurchTools side. Records the calls so tests can assert on them without
 * a media library.
 */
$GLOBALS['ctp_test_deleted_attachments'] = [];

function wp_delete_attachment(int $attachmentId, bool $force = false)
{
    $GLOBALS['ctp_test_deleted_attachments'][] = $attachmentId;

    return true;
}

function ctp_test_deleted_attachments(): array
{
    return $GLOBALS['ctp_test_deleted_attachments'];
}

function ctp_test_reset_deleted_attachments(): void
{
    $GLOBALS['ctp_test_deleted_attachments'] = [];
}

/**
 * Client::excerpt() streift Markup ab, bevor es einen Fehlerkörper kürzt — sonst
 * bliebe von einer HTML-Fehlerseite nur Markup in der Meldung übrig. Grob
 * derselbe Ablauf wie in WordPress: Script-/Style-Inhalte raus, dann Tags.
 */
function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
{
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
    $text = strip_tags($text);

    return trim($text);
}

/**
 * EventRepository fordert Ergebniszeilen als assoziative Arrays an - in
 * WordPress eine Konstante, hier eine, damit die Aufrufe nicht auf einen
 * undefinierten Bezeichner laufen.
 */
define('ARRAY_A', 'ARRAY_A');

/**
 * EventRepository::hasEventsFrom() fragt damit nach "jetzt". Fest verdrahtet,
 * damit die Fenstertests (tests/Db/) eine Uhrzeit haben, gegen die sich ihre
 * Testdaten sinnvoll legen lassen - ctp_test_set_current_time() verschiebt sie,
 * wo ein Test das braucht.
 */
$GLOBALS['ctp_test_current_time'] = '2026-08-18 12:00:00';

function current_time(string $type, $gmt = 0)
{
    return $GLOBALS['ctp_test_current_time'];
}

function ctp_test_set_current_time(string $mysqlDate): void
{
    $GLOBALS['ctp_test_current_time'] = $mysqlDate;
}

/**
 * Setzt eine leere SQLite-Datenbank als $wpdb ein und gibt sie zurueck, damit
 * der Test seine Zeilen anlegen kann. Siehe SqliteWpdb fuer den Grund, warum
 * es diesen Ersatz ueberhaupt gibt.
 */
function ctp_test_install_wpdb(): \ChurchToolsPlugin\Tests\Support\SqliteWpdb
{
    $wpdb = new \ChurchToolsPlugin\Tests\Support\SqliteWpdb();
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/**
 * The layout templates guard themselves with `if (!defined('ABSPATH')) exit;`,
 * so rendering one in a test (see tests/Frontend/PopupTemplateTest.php) needs the
 * constant to exist. Pointed at the plugin directory rather than at a WordPress
 * root, since nothing reachable from a template does anything with the value —
 * the two callers that build paths from it (Installer::createTables(),
 * SyncEngine::syncSeriesImage()) load wp-admin includes and are out of reach of
 * these tests either way.
 */
define('ABSPATH', dirname(__DIR__) . '/');

/**
 * Escaping and translation, as far as rendering a template needs them. Faithful
 * enough for the markup to come out parseable (esc_html()/esc_attr() really do
 * encode, so a fixture value with an angle bracket can't invent an element),
 * without pulling in kses or the l10n stack — the template tests assert on the
 * *structure* of the output, and the escaping rules themselves are WP core's
 * job, not this plugin's.
 */
function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_html_e(string $text, string $domain = ''): void
{
    echo esc_html($text);
}

function esc_attr_e(string $text, string $domain = ''): void
{
    echo esc_attr($text);
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

/**
 * Date formatting for EventFormatter, which every layout template calls for its
 * date/time lines and month dividers. mysql2date() reads the stored
 * "Y-m-d H:i:s" values; date_i18n() only ever gets a timestamp from it
 * (monthLabel()), and translating the month name is exactly the part that has
 * no meaning without WordPress, so it stays English here.
 */
function mysql2date(string $format, string $date, bool $translate = true): string
{
    return (new DateTimeImmutable($date))->format($format);
}

function date_i18n(string $format, $timestamp = false): string
{
    return gmdate($format, (int) $timestamp);
}

/**
 * Same word split and ellipsis WP core uses, minus the filters and the
 * multibyte/CJK branch — EventFormatter::excerpt() passes plain descriptions
 * through it, and the templates only need a shortened string back.
 */
function wp_trim_words(string $text, int $numWords = 55, ?string $more = null): string
{
    $words = preg_split('/[\n\r\t ]+/', wp_strip_all_tags($text), $numWords + 1);

    if (count($words) > $numWords) {
        array_pop($words);

        return implode(' ', $words) . ($more ?? '&hellip;');
    }

    return implode(' ', $words);
}
