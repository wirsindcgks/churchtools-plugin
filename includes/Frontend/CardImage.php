<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Die Bildbreiten, in denen Termine ausgeliefert werden, und die `sizes`-Angabe
 * je Ansicht.
 *
 * Vorher bekam jede Kachel dieselbe Datei: WordPress' `large` (bis 1024px), ohne
 * `srcset`. Gemessen auf der Testseite hieß das 1024px-Dateien in 365px breiten
 * Kacheln - Faktor 2,8 auf einem gewoehnlichen Bildschirm, und bei vier Spalten
 * (267px) noch deutlicher. Acht sichtbare Kacheln waren 494 KB.
 *
 * `srcset` allein haette daran wenig geaendert, weil WordPress' Standardgroessen
 * an dieser Stelle schlecht passen: `medium` ist mit 300px zu klein fuer eine
 * 365px-Kachel, und `medium_large` (768px) ist bei den hochkanten Flyern, die
 * ChurchTools oft liefert, sogar *groesser* als `large` (724x1024). Zwischen 300
 * und 768 klafft genau die Luecke, in der jede Kachel liegt - deshalb die beiden
 * eigenen Breiten unten.
 */
final class CardImage
{
    /**
     * Die zusaetzlichen Breiten, nach den gemessenen Kachelbreiten gewaehlt: 400
     * deckt drei Spalten auf einem gewoehnlichen Bildschirm ab (gemessen 365px),
     * 600 die zweispaltige Anzeige (567px) und die dreispaltige auf einem
     * Retina-Geraet. Beide ohne Zuschnitt (`false`), damit sie dasselbe
     * Seitenverhaeltnis behalten wie das Original - `wp_calculate_image_srcset()`
     * nimmt nur Groessen mit passendem Verhaeltnis in die Liste auf.
     *
     * Nach der Breite benannt statt nach ihrem Zweck ("ctp-card"): Wofuer 400px
     * gut sind, entscheidet die Spaltenzahl der jeweiligen Instanz, nicht der
     * Name.
     */
    public const SIZES = [
        'ctp-400' => 400,
        'ctp-600' => 600,
    ];

    /**
     * Obergrenze fuer die `srcset`-Liste einer *Kachel* (Grid und „Naechster
     * Termin"), in Pixeln.
     *
     * Ohne sie waere die Umstellung fuer einen Teil der Besucher eine
     * Verschlechterung statt einer Ersparnis. Die Rechnung: Eine Kachel ist rund
     * 365px breit, `sizes` meldet also etwa 390. Auf einem Geraet mit doppelter
     * Pixeldichte sucht der Browser damit 780px - und die naechste Stufe
     * oberhalb von WordPress' 768 ist bei den hochkanten Flyern 1086. Er haette
     * also 134 KB geladen, wo vorher pauschal `large` mit 82 KB stand. Bei
     * dreifacher Dichte, auf Mobilgeraeten alles andere als selten, noch mehr.
     *
     * Mit dem Deckel endet die Leiter bei 768, also ungefaehr dort, wo vorher
     * ohnehin jeder gelandet ist: Wer wenig Pixel hat, spart jetzt deutlich, und
     * wer viele hat, zahlt nicht drauf.
     *
     * Gilt bewusst *nicht* fuer die Detailansicht (siehe detailSizes()): Dort
     * ist der Flyer der Inhalt und wird gelesen, nicht als Vorschaubild
     * ueberflogen - da ist Schaerfe die Bytes wert.
     */
    public const CARD_MAX_SRCSET_WIDTH = 800;

    /**
     * Bezugsgroesse, gegen die die gedeckelte Kachel-Liste gerechnet wird - muss
     * unter CARD_MAX_SRCSET_WIDTH liegen, sonst hebt sie den Deckel selbst
     * wieder auf (siehe srcsetFor()).
     */
    public const CARD_REFERENCE_SIZE = 'ctp-600';

    /**
     * Wird an jedes selbst importierte Bild geschrieben, sobald es die Groessen
     * oben hat (siehe SyncEngine::importImage() und ImageSizeBackfill). Steigt
     * diese Zahl, weil SIZES sich aendert, gelten alle vorhandenen Bilder wieder
     * als nachzugenerieren.
     */
    public const SIZES_VERSION = '1';

    /** Postmeta-Schluessel zu SIZES_VERSION. */
    public const VERSION_META_KEY = '_ctp_sizes_version';

    public static function registerHooks(): void
    {
        // after_setup_theme statt init: Groessen muessen registriert sein, bevor
        // irgendetwas ein Bild erzeugt - der Sync laeuft ueber Cron spaeter, das
        // reicht also, und frueher als hier gibt es keine sinnvolle Stelle.
        add_action('after_setup_theme', [self::class, 'registerSizes']);
    }

    public static function registerSizes(): void
    {
        foreach (self::SIZES as $name => $width) {
            add_image_size($name, $width, 0, false);
        }
    }

    /**
     * Die `srcset`-Liste eines Anhangs, oder '' wenn es keine gibt - etwa weil
     * das Bild kleiner ist als jede Zusatzgroesse, oder weil die Zeile noch auf
     * die urspruengliche ChurchTools-Adresse zeigt (dann gibt es hier gar keinen
     * Anhang, und ohne eigene Kopien gibt es auch nichts anzubieten).
     *
     * Bezugsgroesse ist `large`, dieselbe, aus der EventListRenderer das `src`
     * aufloest - sonst rechnete der Browser die Kandidaten gegen eine andere
     * Breite als die, die im `src` steht.
     *
     * $maxWidth deckelt die Liste (0 = kein Deckel), siehe
     * CARD_MAX_SRCSET_WIDTH. Der Filter wird nur fuer diesen einen Aufruf
     * gesetzt und danach wieder abgeraeumt - er gilt global fuer *jedes* Bild
     * der Seite, auch fuer die des Themes, und duerfte deshalb keine Sekunde
     * laenger stehen als noetig.
     *
     * $reference ist der Grund, warum der Deckel ueberhaupt greift:
     * wp_calculate_image_srcset() nimmt die Groesse, die im `src` steht,
     * *immer* in die Liste auf - ausdruecklich auch dann, wenn sie ueber dem
     * Deckel liegt. Mit 'large' als Bezug hiess das bei querformatigen Bildern,
     * deren `large` 1024px breit ist, dass genau die 1024 als Kandidat
     * stehenblieb und auf Geraeten mit hoher Pixeldichte auch gewaehlt wurde -
     * der Deckel war wirkungslos, gemessen an 19 Kacheln 1247 statt der
     * erwarteten Kilobyte. Mit einer Bezugsgroesse unterhalb des Deckels faellt
     * diese Ausnahme weg. Auf die Auswahl selbst wirkt sich der Bezug nicht
     * aus: Die Kandidaten tragen absolute Breiten (`w`), der Bezug entscheidet
     * nur ueber das Seitenverhaeltnis, nach dem die Liste zusammengestellt
     * wird.
     */
    public static function srcsetFor(int $attachmentId, int $maxWidth = 0, string $reference = 'large'): string
    {
        if ($attachmentId <= 0) {
            return '';
        }

        $cap = static fn (): int => $maxWidth;

        if ($maxWidth > 0) {
            add_filter('max_srcset_image_width', $cap);
        }

        $srcset = wp_get_attachment_image_srcset($attachmentId, $reference);

        if ($maxWidth > 0) {
            remove_filter('max_srcset_image_width', $cap);
        }

        return is_string($srcset) ? $srcset : '';
    }

    /**
     * Die `sizes`-Angabe fuer eine Grid-Kachel bei $columns eingestellten
     * Spalten.
     *
     * Eine *genaue* Angabe ist hier unmoeglich: Das Raster richtet sich nach der
     * Breite seines Containers (siehe die Begruendung zum "RAM"-Muster in
     * frontend.css), `sizes` kennt aber nur das Fenster. Die Werte unten sind an
     * den gemessenen Verhaeltnissen der Testseite ausgerichtet - bei drei
     * Spalten 365px Kachel auf 1280px Fenster, 417 auf 1440, 283 auf 1024 - und
     * bewusst leicht zu grosszuegig: Ein zu kleiner Wert liefert ein unscharfes
     * Bild, ein zu grosser nur ein paar Kilobyte zu viel.
     *
     * Die 92vw/46vw spiegeln die beiden Stufen, an denen das Raster ohnehin
     * umbricht (eine Spalte unter 480px, zwei bis 900px), nicht eigene
     * Haltepunkte.
     */
    public static function gridSizes(int $columns): string
    {
        $columns = max(1, $columns);

        return sprintf(
            '(max-width: 480px) 92vw, (max-width: 900px) 46vw, calc(92vw / %d)',
            $columns
        );
    }

    /**
     * "Naechster Termin": Das Bild steht ab 768px neben dem Text (gemessen 502px
     * auf 1280px Fenster), darunter allein darueber.
     */
    public static function heroSizes(): string
    {
        return '(max-width: 767px) 92vw, 42vw';
    }

    /**
     * Detailansicht und Popup. Hier haengt die Breite nicht am Fenster, sondern
     * am Seitenverhaeltnis des Bildes: `max-height: 60vh` mit `width: auto`
     * (frontend.css) macht aus einem hochkanten Flyer eine schmale Spalte - auf
     * der Testseite 272px - und aus einem querformatigen die volle Spaltenbreite
     * von rund 460px. Der feste Wert deckt beides ab, statt eine Rechnung
     * vorzugeben, die fuer die Haelfte der Bilder nicht stimmt.
     */
    public static function detailSizes(): string
    {
        return '(max-width: 700px) 92vw, 480px';
    }
}
