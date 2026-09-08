<?php

/**
 * Renders exactly one element of the single-event detail view. Split out of
 * event-detail-content.php so that file can render the elements twice over in
 * two different groupings — flat for the popup, wrapped for the "own page"
 * layout — without the markup of every field existing twice.
 *
 * @var array  $event         Already enriched via EventListRenderer::withCalendarMeta().
 * @var string $key           One of DetailDesign::ELEMENT_KEYS.
 * @var string $detailContext 'popup' or 'page', see event-detail-content.php.
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Frontend\EventIcs;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php switch ($key) :
    case 'media':
        ?>
        <?php if ($event['image_url'] !== '') : ?>
            <?php
            // The outer element is the full-width row the detail view's
            // flex layout gives it; the inner frame is what shrink-wraps
            // the (possibly portrait) image and carries radius, shadow
            // and the fallback scrim. Splitting the two is what lets a
            // narrow image sit centred without a full-width frame
            // around empty space beside it.
            ?>
            <div class="ctp-events__detail-media">
                <div class="ctp-events__detail-media-frame<?php echo $event['image_is_fallback'] ? ' ctp-events__detail-media-frame--fallback' : ''; ?>">
                    <?php
                    /*
                     * skip-lazy/data-no-lazy: Dieses Bild steckt im
                     * <template> jeder Kachel und wird erst beim
                     * Oeffnen des Popups in die Seite kopiert. Ein
                     * Lazyload-Plugin (WP Rocket & Co.) ersetzt beim
                     * Ausliefern trotzdem das src durch einen
                     * Platzhalter und merkt sich die echte Adresse in
                     * data-src - seinen Beobachter bekommt der Klon
                     * danach aber nie zu sehen, das Popup blieb also
                     * ohne Bild. Diese beiden Kennzeichen sind die
                     * gaengigen Ausnahmen; unabhaengig davon holt
                     * assets/js/frontend.js beim Klonen ein bereits
                     * ersetztes src wieder zurueck.
                     *
                     * alt: der Titel, aber nur beim eigenen Bild. Auf dieser
                     * Seite ist der Flyer der Inhalt und nicht die
                     * Verzierung, und er ist das, was die Bildersuche zu
                     * diesem Termin findet. Das Standardbild eines Kalenders
                     * bleibt ohne Beschreibung - es zeigt nicht diesen
                     * Termin, es steht nur da, wo keiner ist.
                     */
                    ?>
                    <img
                        src="<?php echo esc_url($event['image_url']); ?>"
                        <?php if (($event['image_srcset_full'] ?? '') !== '') : ?>
                            srcset="<?php echo esc_attr($event['image_srcset_full']); ?>"
                            sizes="<?php echo esc_attr(CardImage::detailSizes()); ?>"
                        <?php endif; ?>
                        alt="<?php echo $event['image_is_fallback'] ? '' : esc_attr($event['title']); ?>"
                        class="skip-lazy"
                        data-no-lazy="1"
                        loading="eager"
                    />
                </div>
            </div>
        <?php endif; ?>
        <?php
        break;

    case 'calendar':
        ?>
        <?php if ($event['calendar_name'] !== '') : ?>
            <span class="ctp-events__eyebrow">
                <?php echo esc_html($event['calendar_name']); ?>
            </span>
        <?php endif; ?>
        <?php
        break;

    case 'title':
        ?>
        <?php
        /*
         * Datums-Chip vor dem Titel, wie in den Kacheln: Er ist die
         * Marke, an der man einen Termin wiedererkennt, und stand
         * bisher nur in den Listen. Die Datumszeile weiter unten
         * bleibt daneben bestehen - sie nennt Wochentag und volles
         * Datum, der Chip ist aria-hidden und damit fuer Screenreader
         * nicht die zweite Stimme derselben Angabe.
         *
         * Auf der eigenen Seite ist der Titel ein h1: Dort ist der
         * Termin der Gegenstand der Seite und nichts anderes steht
         * ueber ihm. Im Popup bleibt es ein h2 - das Dokument hat dort
         * bereits eine eigene Ueberschrift, und der Dialog ist ein
         * Ausschnitt daraus, keine neue Seite.
         */
        $titleTag = $detailContext === 'page' ? 'h1' : 'h2';
        ?>
        <div class="ctp-events__detail-heading">
            <span class="ctp-events__date-chip ctp-events__date-chip--detail" aria-hidden="true">
                <span class="ctp-events__day">
                    <?php echo esc_html(EventFormatter::dayNumber($event['start_date'])); ?>
                </span>
                <span class="ctp-events__month">
                    <?php echo esc_html(EventFormatter::monthAbbreviation($event['start_date'])); ?>
                </span>
            </span>
            <?php printf('<%s class="ctp-events__detail-title">', esc_html($titleTag)); ?>
                <?php echo esc_html($event['title']); ?>
                <?php if (!empty($event['all_day'])) : ?>
                    <span class="ctp-events__badge">
                        <?php esc_html_e('Ganztägig', 'churchtools-plugin'); ?>
                    </span>
                <?php endif; ?>
            <?php printf('</%s>', esc_html($titleTag)); ?>
        </div>
        <?php
        break;

    case 'subtitle':
        ?>
        <?php if ($event['subtitle'] !== '') : ?>
            <p class="ctp-events__subtitle"><?php echo esc_html($event['subtitle']); ?></p>
        <?php endif; ?>
        <?php
        break;

    case 'date':
        ?>
        <p class="ctp-events__meta-item ctp-events__meta-item--date">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input, same trust boundary as the rest of this template's static HTML (see Icons.php docblock). ?>
            <?php echo Icons::calendar(); ?>
            <?php echo esc_html(EventFormatter::dateOnly($event)); ?>
        </p>
        <?php
        break;

    case 'time':
        ?>
        <?php if (EventFormatter::timeRange($event) !== '') : ?>
            <p class="ctp-events__meta-item ctp-events__meta-item--time">
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                <?php echo Icons::clock(); ?>
                <?php echo esc_html(EventFormatter::timeRange($event)); ?>
            </p>
        <?php endif; ?>
        <?php
        break;

    case 'location':
        ?>
        <?php if ($event['location'] !== '') : ?>
            <p class="ctp-events__meta-item ctp-events__meta-item--location">
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                <?php echo Icons::location(); ?>
                <?php echo esc_html($event['location']); ?>
            </p>
        <?php endif; ?>
        <?php
        break;

    case 'description':
        ?>
        <?php if ($event['description'] !== '') : ?>
            <div class="ctp-events__detail-description">
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses_post() before adding any markup of its own (see its docblock). ?>
                <?php echo EventFormatter::descriptionHtml($event['description']); ?>
            </div>
        <?php endif; ?>
        <?php
        break;

    case 'share':
        ?>
        <?php
        /*
         * Ob dieser Fall überhaupt drankommt, entscheidet
         * event-detail-content.php anhand der Einstellung
         * `detail_share_enabled` — hier steht nur noch die Frage, ob es eine
         * Adresse zu teilen gibt. Die setzt EventListRenderer::withCalendarMeta()
         * für jeden Termin; leer ist sie nur, wenn fremder Code sich seine
         * Termine selbst baut (ein Theme-Override, ein Test).
         *
         * Kein `id` irgendwo in diesem Block: Dasselbe Markup steckt im
         * <template> *jeder* Kachel einer Liste, und doppelte IDs sind
         * ungültiges HTML — dieselbe Falle, die ReturnAnchor für die
         * Sprungziele löst.
         *
         * Die Beschriftung der Rückmeldung reist als data-Attribut mit, statt
         * über wp_localize_script(): Das Frontend-Skript bekommt bisher gar
         * keine Daten von PHP, und eine Zeichenkette rechtfertigt diesen Weg
         * nicht.
         */
        ?>
        <?php if (($event['detail_url'] ?? '') !== '') : ?>
            <div class="ctp-events__share">
                <button
                    type="button"
                    class="ctp-events__share-btn"
                    data-ctp-share-url="<?php echo esc_url($event['detail_url']); ?>"
                    data-ctp-share-title="<?php echo esc_attr($event['title']); ?>"
                    data-ctp-share-done="<?php esc_attr_e('Link kopiert', 'churchtools-plugin'); ?>"
                >
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                    <?php echo Icons::share(); ?>
                    <?php esc_html_e('Teilen', 'churchtools-plugin'); ?>
                </button>
                <?php // role="status" meldet den Wechsel des Textes von selbst — ohne das bliebe „Link kopiert" für Screenreader unbemerkt, und der Klick hätte dort gar keine Rückmeldung. ?>
                <span class="ctp-events__share-feedback" role="status"></span>
            </div>
        <?php endif; ?>
        <?php
        break;

    case 'ics':
        ?>
        <?php
        /*
         * „Importieren" — ein gewoehnlicher Verweis mit `download`,
         * kein Knopf mit Skript dahinter. Eine Kalenderdatei oeffnet auf iOS,
         * Android, Outlook und Thunderbird von selbst das richtige Programm;
         * ueber navigator.share({files}) waere derselbe Weg von der
         * Unterstuetzung des Browsers abhaengig und ohne Skript gar nicht da.
         *
         * `download` ist dabei nur ein Wunsch an den Browser — den Dateinamen
         * setzt ohnehin der Content-Disposition-Kopf der Route (siehe
         * Frontend\EventIcs), und der gilt auch dort, wo das Attribut ignoriert
         * wird.
         *
         * Sichtbar steht dort ein Wort, vorgelesen der ganze Satz: „Importieren"
         * allein ist Systemsprache, und das Kalendersymbol daneben, das die
         * Bedeutung im Bild traegt, ist aria-hidden. Das aria-label enthaelt die
         * sichtbare Beschriftung und ergaenzt sie nur — so verlangt es WCAG 2.5.3.
         *
         * Das Wort ist mit Bedacht nicht „Abonnieren": Das gehoert einer
         * moeglichen Feed-Adresse (siehe plan.md) und waere das Gegenteil davon,
         * dauerhaft statt einmalig.
         */
        ?>
        <?php
        /*
         * Ein Termin, zwei mögliche Dateien — und ab hier eine Rückfrage statt
         * eines zweiten Knopfs daneben.
         *
         * Erst standen sie nebeneinander. Nachgemessen waren das mit „Teilen"
         * zusammen 401px Knopfleiste (87 + 131 + 183) für lauter Nebensachen;
         * auf der eigenen Seite ging das noch, im 640px breiten Popup war es
         * eine volle Zeile. Zwei Chips sind die richtige Menge, also klappt
         * der dritte unter den zweiten.
         *
         * **Nur bei einer Serie.** Rund ein Viertel der Termine ist gar keine
         * (an den Daten der Instanz: 28 von 115 künftigen Zeilen), und dort
         * wäre die Rückfrage ein Klick ohne Wahl. Der Einzeltermin bleibt
         * deshalb genau der direkte Verweis, der er seit 1.18.0 ist.
         *
         * **`<details>` und kein Skript.** Der Import war von Anfang an ein
         * blanker `<a download>` und funktioniert ohne JavaScript; ein
         * Skript-Menü hätte genau das aufgegeben. `<details>` bringt Tastatur,
         * Rolle und `aria-expanded` von selbst mit — und ist damit weniger
         * Bedienlogik als vorher, nicht mehr.
         *
         * Aufgeklappt wird *im Fluss* und nicht schwebend: Der Rumpf des
         * Popups ist ein eigener Scroll-Container (siehe frontend.css), eine
         * absolut gesetzte Fläche würde dort abgeschnitten.
         */
        $ctpSerie = (int) ($event['series_count'] ?? 1);

        /*
         * Die Zahl steht sichtbar in der Beschriftung, und das ist keine
         * Verzierung. „Ganze Serie" wäre eine Zusage, die das Plugin nicht
         * halten kann: Gezählt wird über ct_event_id, und das ist
         * ChurchTools' Basistermin und nicht die Serie, die ein Mensch sieht —
         * ein einzeln bearbeitetes Datum löst sich dort in einen eigenen
         * Basistermin auf. An den Daten der Instanz nachgezählt: Der
         * wöchentliche „Kindergottesdienst" steht als eine Serie mit 11
         * Terminen *und* sechs gleichnamigen Einzelterminen daneben. Dazu
         * reicht der Abgleich nur `sync_days_ahead` weit voraus. Eine Zahl ist
         * gegen die Liste daneben prüfbar, ein Versprechen nicht.
         *
         * Kein _n(): Die Auswahl erscheint erst ab zwei Terminen, die
         * Einzahlform käme also nie vor.
         *
         * Die vorgelesene Fassung beginnt mit der sichtbaren und ergänzt sie
         * nur — so verlangt es WCAG 2.5.3. Im ersten Anlauf stand sichtbar
         * „Nur dieser Termin" und vorgelesen „Nur *diesen* Termin …": eine
         * Sprachsteuerung hätte dann nicht auf das gehört, was dasteht. Der
         * Gedankenstrich statt einer Umformulierung, weil er beides erlaubt,
         * einen sauberen Satz und die wörtliche Übernahme.
         *
         * series_count fällt auf 1 zurück: Wer dieses Partial direkt einbindet,
         * hat die Zahl nicht mitgeschickt, und 1 heißt „kein Serienfall".
         */
        $ctpSerieText = sprintf(
            /* translators: %d: Anzahl der künftigen Termine dieser Serie. */
            __('Alle %d Termine', 'churchtools-plugin'),
            $ctpSerie
        );
        $ctpSerieBeschreibung = sprintf(
            /* translators: %d: Anzahl der künftigen Termine dieser Serie. */
            __('Alle %d Termine dieser Serie — in den Kalender importieren', 'churchtools-plugin'),
            $ctpSerie
        );
        ?>
        <?php if (($event['detail_url'] ?? '') !== '') : ?>
            <div class="ctp-events__share ctp-events__share--ics">
                <?php if ($ctpSerie > 1) : ?>
                    <details class="ctp-events__import">
                        <summary class="ctp-events__share-btn ctp-events__import-summary">
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                            <?php echo Icons::calendarPlus(); ?>
                            <?php esc_html_e('Importieren', 'churchtools-plugin'); ?>
                        </summary>
                        <?php
                        /*
                         * Die Reihenfolge ist Absicht: der einzelne Termin
                         * zuerst. Er ist die kleinere und rücknehmbarere Wahl —
                         * wer 15 Einträge im Telefonkalender wieder loswerden
                         * will, hat einen langen Abend vor sich.
                         */
                        ?>
                        <div class="ctp-events__import-choices">
                            <a
                                class="ctp-events__import-choice"
                                href="<?php echo esc_url(EventIcs::urlForEvent($event)); ?>"
                                aria-label="<?php esc_attr_e('Nur dieser Termin — in den Kalender importieren', 'churchtools-plugin'); ?>"
                                download
                            >
                                <?php esc_html_e('Nur dieser Termin', 'churchtools-plugin'); ?>
                            </a>
                            <a
                                class="ctp-events__import-choice"
                                href="<?php echo esc_url(EventIcs::urlForSeries($event)); ?>"
                                aria-label="<?php echo esc_attr($ctpSerieBeschreibung); ?>"
                                download
                            >
                                <?php echo esc_html($ctpSerieText); ?>
                            </a>
                        </div>
                    </details>
                <?php else : ?>
                    <a
                        class="ctp-events__share-btn"
                        href="<?php echo esc_url(EventIcs::urlForEvent($event)); ?>"
                        aria-label="<?php esc_attr_e('Termin in den Kalender importieren', 'churchtools-plugin'); ?>"
                        download
                    >
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                        <?php echo Icons::calendarPlus(); ?>
                        <?php esc_html_e('Importieren', 'churchtools-plugin'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
        break;
endswitch;
