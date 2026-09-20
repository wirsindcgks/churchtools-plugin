<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use DateTimeImmutable;

/**
 * Das Kennzeichen „läuft gerade" an einem Termin, der genau jetzt stattfindet.
 *
 * Die Entscheidung *ob* er gerade läuft fällt bewusst nicht hier, sondern im
 * Browser (siehe assets/js/frontend.js): Die Ausgabe der Shortcodes wird von
 * jedem Caching-Plugin als fertiges HTML abgelegt und Stunden später
 * unverändert erneut ausgeliefert. Ein serverseitig gesetztes „läuft gerade"
 * wäre damit die Behauptung, die am zuverlässigsten falsch ist - sie steht
 * genau so lange, wie der Seiten-Cache hält.
 *
 * PHP gibt deshalb nur den Rahmen aus: das fertige, per `hidden` verborgene
 * Etikett samt Anfang und Ende des Termins als ISO-8601-Zeitpunkte. Das
 * Skript nimmt `hidden` weg, solange die Uhr dazwischen steht, und hängt es
 * wieder an. Kommt das Skript nicht an (abgeschaltet, Fehler, Ladeblocker),
 * bleibt das Etikett verborgen - die Kachel sieht dann aus wie vorher, statt
 * etwas Falsches zu behaupten.
 *
 * Ohne eingestelltes Wort (Design-Tab, Einstellung `live_label`) gibt es gar
 * kein Etikett und auch keine Zeitstempel im Quelltext: Das leere Feld ist
 * der Ausschalter.
 */
final class LiveBadge
{
    /**
     * Wie lang das eingestellte Wort höchstens sein darf. Es steht als Pille
     * neben dem Terminnamen und teilt sich die Zeile mit ihm - alles, was
     * darüber hinausgeht, ist kein Etikett mehr, sondern ein Satz.
     */
    public const MAX_LABEL_LENGTH = 24;

    /**
     * Das fertige Etikett für einen Termin, oder ein leerer String, wenn es
     * keins geben soll (kein Wort eingestellt, oder ein Termin ohne brauchbare
     * Zeitangabe).
     *
     * Die Rückgabe ist fertig maskiert und wird von den Templates direkt
     * ausgegeben - wie bei Icons:: und ClickTrigger:: auch.
     */
    public static function render(array $event, string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return '';
        }

        $range = self::range($event);

        if ($range === null) {
            return '';
        }

        return '<span class="ctp-events__badge ctp-events__badge--live"'
            . ' data-ctp-live-start="' . esc_attr($range[0]) . '"'
            . ' data-ctp-live-end="' . esc_attr($range[1]) . '"'
            . ' hidden>'
            . '<span class="ctp-events__live-dot" aria-hidden="true"></span>'
            . esc_html($label)
            . '</span>';
    }

    /**
     * Kürzt und säubert ein eingegebenes Wort auf das, was als Pille taugt.
     * Liegt hier und nicht im Admin, damit die Länge an derselben Stelle steht
     * wie die Ausgabe, die sie tragen muss.
     */
    public static function sanitizeLabel(string $label): string
    {
        $label = sanitize_text_field($label);

        return trim(mb_substr($label, 0, self::MAX_LABEL_LENGTH));
    }

    /**
     * Anfang und Ende des Termins als ISO-8601-Zeitpunkte, oder null, wenn
     * sich daraus kein Zeitraum ergibt.
     *
     * Der Versatz („+02:00") gehört zwingend dazu: `start_date`/`end_date`
     * stehen in der Zeitzone der Website (Sync\SyncEngine rechnet die
     * UTC-Angaben von ChurchTools beim Import um), die Uhr im Browser läuft
     * aber in der Zeitzone des Besuchers. Ohne den Versatz wäre das Etikett
     * für jeden, der nicht zufällig in derselben Zone sitzt, um Stunden
     * verschoben - dieselbe Überlegung wie in EventSchema::isoDate().
     *
     * @return array{0: string, 1: string}|null
     */
    private static function range(array $event): ?array
    {
        $start = self::toDate((string) ($event['start_date'] ?? ''));

        if ($start === null) {
            return null;
        }

        // Ohne brauchbares Ende gilt der Beginn auch als Ende: Ein Termin ohne
        // Dauer ist dann für den Moment seines Beginns „jetzt" und danach
        // vorbei. Das ist die vorsichtige Richtung - die andere wäre ein
        // Etikett, das nie wieder verschwindet.
        $end = self::toDate((string) ($event['end_date'] ?? '')) ?? $start;

        if (!empty($event['all_day'])) {
            /*
             * Ganztägige Termine laufen vom ersten Augenblick ihres ersten bis
             * zum letzten ihres letzten Tages - die Uhrzeit, die in der
             * Datenbank steht, ist bei ihnen bedeutungslos (siehe
             * EventFormatter::timeRange(), das sie aus demselben Grund nicht
             * anzeigt).
             *
             * Der Rückschritt um einen Tag gilt der zweiten Schreibweise, in
             * der ein ganztägiger Termin vorkommen kann: Ende um Mitternacht
             * des *Folgetags*, also als offenes Intervall. Beide Formen sind
             * verbreitet, und welche ChurchTools liefert, darf für das Etikett
             * keinen Unterschied machen - ohne diesen Schritt liefe eine
             * Freizeit in der zweiten Schreibweise einen Tag zu lang als
             * „läuft gerade".
             */
            if ($end > $start && $end->format('H:i:s') === '00:00:00') {
                $end = $end->modify('-1 day');
            }

            $start = $start->setTime(0, 0, 0);
            $end = $end->setTime(23, 59, 59);
        }

        if ($end < $start) {
            return null;
        }

        return [$start->format('c'), $end->format('c')];
    }

    /**
     * Ein gespeicherter MySQL-Zeitpunkt in der Zeitzone der Website, oder null
     * für das, was keiner ist - leer, oder der Nullwert „0000-00-00", den
     * MySQL für ein fehlendes Datum ablegt und aus dem DateTimeImmutable sonst
     * ein Datum im Jahr -0001 machen würde.
     */
    private static function toDate(string $mysqlDate): ?DateTimeImmutable
    {
        $mysqlDate = trim($mysqlDate);

        if ($mysqlDate === '' || str_starts_with($mysqlDate, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($mysqlDate, wp_timezone());
        } catch (\Exception) {
            // Ein unlesbares Datum ist kein Grund, die ganze Liste scheitern zu
            // lassen - der Termin bekommt dann eben kein Etikett.
            return null;
        }
    }
}
