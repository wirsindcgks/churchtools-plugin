<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Admin\SettingsPage;

/**
 * Was im <head> einer Terminseite über den Termin stehen muss: Dokumenttitel,
 * Kurzbeschreibung, die Adresse als Canonical und die Angaben, aus denen
 * WhatsApp, Facebook & Co. ihre Vorschau bauen (Open Graph).
 *
 * Der Grund für diese Klasse ist, dass ein Termin keinen eigenen Beitrag hat
 * (siehe docs/ARCHITECTURE.md). Alles, was WordPress und die SEO-Plugins in den
 * Kopf schreiben, holen sie aus dem Beitrag — auf einer Terminseite ist das
 * entweder gar keiner (Adresse `/churchtools-termin/<id>/`) oder die
 * *Elternseite* (Adresse `/termine/<slug>/`). Ohne diese Klasse trägt jede
 * Terminseite deshalb Titel, Beschreibung, Vorschaubild und Canonical der
 * Elternseite: Geteilte Termine sähen alle gleich aus, und ein Canonical, das
 * auf die Terminliste zeigt, sagt einer Suchmaschine „diese Seite ist nur eine
 * Kopie jener" — die zuverlässigste Art, einen Termin aus dem Index zu halten.
 *
 * Zwei Wege führen zum selben Ziel, und beide werden bedient:
 *
 *   ohne SEO-Plugin — die Angaben schreibt diese Klasse selbst auf `wp_head`.
 *   mit SEO-Plugin  — dann schreibt das Plugin sie, und zwar unabhängig davon,
 *                     was hier ausgegeben würde. Yoast und Rank Math bringen
 *                     ihre Werte aber durch je einen Filter, und in genau die
 *                     wird der Termin gereicht. Deshalb registriert
 *                     registerForEvent() diese Filter immer: Fehlt das Plugin,
 *                     wird der Hook nie ausgelöst und die Registrierung kostet
 *                     nichts.
 *
 * Für andere SEO-Plugins (SEOPress, AIOSEO, The SEO Framework) gilt der zweite
 * Fall ohne den zweiten Halbsatz: Ihre Filter kennt diese Klasse nicht, also
 * bleiben Titel und Canonical dort die der Elternseite. Erkannt werden sie
 * trotzdem — sonst stünden ihre und unsere Angaben doppelt im Kopf, was
 * schlechter ist als einmal die falschen. Die strukturierten Daten
 * (EventSchema) sind davon unberührt und in jedem Fall richtig.
 */
final class DetailSeo
{
    /**
     * Wie viele Zeichen die Kurzbeschreibung höchstens hat. Suchmaschinen
     * schneiden sie um die 160 herum ab; was darüber steht, ist geschrieben,
     * aber nicht gelesen.
     */
    private const DESCRIPTION_LENGTH = 160;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $event = null;

    /**
     * Ob die Seite ihr Canonical selbst setzen muss. Auf der Elternseiten-Route
     * gibt es einen echten Beitrag, also gibt WordPress selbst eines aus (und
     * EventDetailPage::filterHostedCanonicalUrl() biegt es auf den Termin um);
     * auf der ID-Route gibt es keinen, und damit auch keines.
     */
    private static bool $ownCanonical = false;

    /**
     * Die aufgelöste Bildadresse, sobald einmal danach gefragt wurde — null,
     * solange nicht (siehe imageUrl()).
     */
    private static ?string $imageUrl = null;

    /**
     * @param array<string, mixed> $event
     */
    public static function registerForEvent(array $event, bool $ownCanonical = false): void
    {
        self::$event = $event;
        self::$ownCanonical = $ownCanonical;
        self::$imageUrl = null;

        add_filter('pre_get_document_title', [self::class, 'title']);
        add_action('wp_head', [self::class, 'renderMetaTags'], 1);

        // Yoast. Die Namen stammen aus den Presentern in src/presenters/ —
        // jeder von ihnen reicht seinen fertigen Wert durch genau einen
        // Filter, bevor er ihn ausgibt.
        add_filter('wpseo_title', [self::class, 'title']);
        add_filter('wpseo_metadesc', [self::class, 'description']);
        add_filter('wpseo_canonical', [self::class, 'canonicalUrl']);
        add_filter('wpseo_opengraph_title', [self::class, 'title']);
        add_filter('wpseo_opengraph_desc', [self::class, 'description']);
        add_filter('wpseo_opengraph_url', [self::class, 'canonicalUrl']);
        add_filter('wpseo_opengraph_image', [self::class, 'imageUrlOrKeep']);

        // Rank Math. Die Open-Graph-Filter folgen dem dokumentierten Muster
        // `rank_math/opengraph/{netzwerk}/{eigenschaft}`; trifft eine
        // Eigenschaft einmal anders heißen, läuft die Registrierung ins Leere
        // und ändert nichts — dieselbe Folgenlosigkeit wie bei einem gar nicht
        // installierten Plugin.
        add_filter('rank_math/frontend/title', [self::class, 'title']);
        add_filter('rank_math/frontend/description', [self::class, 'description']);
        add_filter('rank_math/frontend/canonical', [self::class, 'canonicalUrl']);
        add_filter('rank_math/opengraph/url', [self::class, 'canonicalUrl']);
        add_filter('rank_math/opengraph/facebook/og_title', [self::class, 'title']);
        add_filter('rank_math/opengraph/facebook/og_description', [self::class, 'description']);
        add_filter('rank_math/opengraph/facebook/image', [self::class, 'imageUrlOrKeep']);
        add_filter('rank_math/opengraph/twitter/twitter_title', [self::class, 'title']);
        add_filter('rank_math/opengraph/twitter/twitter_description', [self::class, 'description']);
        add_filter('rank_math/opengraph/twitter/image', [self::class, 'imageUrlOrKeep']);
    }

    /**
     * Nur für Tests: den registrierten Termin wieder vergessen. Im Betrieb
     * endet ein Request mit der Seite, hier laufen mehrere hintereinander im
     * selben Prozess.
     */
    public static function reset(): void
    {
        self::$event = null;
        self::$ownCanonical = false;
        self::$imageUrl = null;
    }

    /**
     * „Gottesdienst – Musterkirche". Der Seitenname gehört dazu: In einer
     * Trefferliste steht ein blanker „Gottesdienst" ohne jeden Hinweis
     * darauf, wessen Gottesdienst gemeint ist.
     */
    public static function title(string $title = ''): string
    {
        if (self::$event === null) {
            return $title;
        }

        $siteName = trim((string) get_bloginfo('name'));
        $eventTitle = (string) self::$event['title'];

        return $siteName === '' ? $eventTitle : sprintf('%s – %s', $eventTitle, $siteName);
    }

    /**
     * Die Kurzbeschreibung: erst die Eckdaten, dann so viel Beschreibung, wie
     * noch hineinpasst. Die Reihenfolge ist Absicht — wer in einer Trefferliste
     * steht, will als Erstes wissen, wann und wo, und genau das schneidet eine
     * Suchmaschine sonst als Letztes ab.
     */
    public static function description(string $description = ''): string
    {
        if (self::$event === null) {
            return $description;
        }

        $event = self::$event;
        $facts = array_filter([
            EventFormatter::dateOnly($event),
            EventFormatter::timeRange($event),
            trim((string) ($event['location'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        $text = trim((string) ($event['description'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($event['subtitle'] ?? ''));
        }
        $text = EventFormatter::plainText($text);

        $parts = [implode(', ', $facts)];
        if ($text !== '') {
            $parts[] = $text;
        }

        return self::shorten(trim(implode(' – ', array_filter($parts))), self::DESCRIPTION_LENGTH);
    }

    /**
     * Die Adresse des Termins — nicht die der Seite, unter der er liegt.
     */
    public static function canonicalUrl(string $url = ''): string
    {
        return self::$event === null ? $url : EventDetailPage::urlForEvent(self::$event);
    }

    /**
     * Das Bild des Termins, solange er eines hat. Sonst bleibt stehen, was das
     * SEO-Plugin gefunden hat: Ein Termin ohne eigenes Bild ist kein Grund,
     * die Vorschau bildlos zu machen — das Logo der Gemeinde aus den
     * Plugin-Einstellungen ist dort die bessere Antwort als nichts.
     *
     * Und ebenso, wenn der Wert gar keine Adresse ist: Yoast reicht durch
     * `wpseo_opengraph_image` eine Zeichenkette, Rank Math kann durch seinen
     * Bild-Filter aber auch ein Feld mit mehreren Angaben schicken (Adresse,
     * Größe, Typ). Eine Zeichenkette an dessen Stelle zurückzugeben, hieße
     * einem fremden Plugin etwas unterzuschieben, das es so nicht erwartet —
     * eine Vorschau ohne Bild ist der bessere Ausgang als eine Seite mit
     * Fehler. Deshalb greift diese Ersetzung nur dort, wo ohnehin eine
     * Zeichenkette steht.
     *
     * @param mixed $image
     *
     * @return mixed
     */
    public static function imageUrlOrKeep($image = '')
    {
        if (!is_string($image)) {
            return $image;
        }

        $own = self::imageUrl();

        return $own !== '' ? $own : $image;
    }

    /**
     * Achtung, hier steckt eine Falle: Die Spalte `image_url` der Tabelle ist
     * *nicht* die Adresse, unter der das Bild ausgeliefert wird — sie hält die
     * ursprüngliche ChurchTools-Adresse, und die antwortet einem Besucher
     * (und jedem Dienst, der eine Vorschau baut) mit HTTP 401. Ausgeliefert
     * wird der importierte Anhang, ersatzweise das Standardbild des Kalenders,
     * und genau diese Frage beantwortet EventListRenderer::resolveImage().
     *
     * Einmal je Request beantwortet: Die Kopfzeilen fragen mehrfach danach,
     * die Antwort kostet zwei Nachschläge in der Mediathek.
     */
    public static function imageUrl(): string
    {
        if (self::$event === null) {
            return '';
        }

        if (self::$imageUrl === null) {
            $calendars = SettingsPage::get()['calendars'];
            $calendar = $calendars[(int) (self::$event['ct_calendar_id'] ?? 0)] ?? null;
            self::$imageUrl = EventListRenderer::resolveImage(self::$event, $calendar)['url'];
        }

        return self::$imageUrl;
    }

    /**
     * Die Angaben, die WordPress von sich aus nicht kennt. Nur ohne SEO-Plugin:
     * Ist eines im Haus, schreibt es dieselben Zeilen selbst, und zwei Sätze
     * og:title im selben Kopf sind für jeden Dienst, der sie liest, eine
     * Auslegungsfrage, die niemand stellen sollte.
     */
    public static function renderMetaTags(): void
    {
        if (self::$event === null) {
            return;
        }

        self::renderCanonicalIfNeeded();

        if (self::hasSeoPlugin()) {
            return;
        }

        $description = self::description();
        $image = self::imageUrl();
        $url = self::canonicalUrl();
        $siteName = trim((string) get_bloginfo('name'));

        if ($description !== '') {
            printf('<meta name="description" content="%s" />' . "\n", esc_attr($description));
            printf('<meta property="og:description" content="%s" />' . "\n", esc_attr($description));
            printf('<meta name="twitter:description" content="%s" />' . "\n", esc_attr($description));
        }

        printf('<meta property="og:title" content="%s" />' . "\n", esc_attr(self::title()));
        printf('<meta name="twitter:title" content="%s" />' . "\n", esc_attr(self::title()));
        printf('<meta property="og:type" content="article" />' . "\n");

        if ($url !== '') {
            printf('<meta property="og:url" content="%s" />' . "\n", esc_url($url));
        }

        if ($siteName !== '') {
            printf('<meta property="og:site_name" content="%s" />' . "\n", esc_attr($siteName));
        }

        if ($image !== '') {
            printf('<meta property="og:image" content="%s" />' . "\n", esc_url($image));
            printf('<meta name="twitter:image" content="%s" />' . "\n", esc_url($image));
        }

        printf(
            '<meta name="twitter:card" content="%s" />' . "\n",
            $image !== '' ? 'summary_large_image' : 'summary'
        );
    }

    /**
     * Das Canonical der Terminseite, und zwar nach einer eigenen Regel — es
     * ist die einzige Angabe hier, bei der die falsche schlimmer ist als gar
     * keine.
     *
     * Ausgegeben wird es nur auf der Route ohne eigenen Beitrag (die
     * Elternseiten-Route bekommt ihres von WordPress) und nur dann, wenn kein
     * Plugin im Haus ist, dessen Canonical-Filter diese Klasse bedient.
     *
     * Der Grund für den zweiten Teil: Ein SEO-Plugin, dessen Filter wir nicht
     * kennen, schreibt auf dieser Route das Canonical der *Startseite* — die
     * Adresse `/churchtools-termin/<id>/` bringt keine bekannte Abfragevariable
     * mit, WordPress hält sie deshalb für die Startseite. Ein zweites,
     * richtiges Canonical daneben ist dann der bessere Ausgang: Widersprechen
     * sich zwei, verwerfen Suchmaschinen beide und nehmen die aufgerufene
     * Adresse — also die des Termins. Ein einzelnes falsches nähmen sie beim
     * Wort.
     */
    private static function renderCanonicalIfNeeded(): void
    {
        if (!self::$ownCanonical || self::hasSupportedSeoPlugin()) {
            return;
        }

        $url = self::canonicalUrl();
        if ($url !== '') {
            printf('<link rel="canonical" href="%s" />' . "\n", esc_url($url));
        }
    }

    /**
     * Ob ein SEO-Plugin die Kopfzeilen bereits schreibt. Geprüft wird an den
     * Konstanten, die diese Plugins beim Laden definieren — nicht an
     * is_plugin_active(), das nur im Backend zur Verfügung steht.
     */
    public static function hasSeoPlugin(): bool
    {
        return self::hasSupportedSeoPlugin()
            || defined('SEOPRESS_VERSION')
            || defined('AIOSEO_VERSION')
            || defined('THE_SEO_FRAMEWORK_VERSION');
    }

    /**
     * Die beiden, deren Filter diese Klasse bedient — bei ihnen tragen Titel,
     * Beschreibung, Canonical und Vorschau die Angaben des Termins, ohne dass
     * hier eine einzige Zeile ausgegeben wird.
     */
    public static function hasSupportedSeoPlugin(): bool
    {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION');
    }

    /**
     * Kürzt an der letzten Wortgrenze davor, nicht mitten im Wort — und hängt
     * nur dann ein Auslassungszeichen an, wenn wirklich etwas fehlt.
     */
    private static function shorten(string $text, int $length): string
    {
        if ($text === '' || mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($length / 2)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:–-") . '…';
    }
}
