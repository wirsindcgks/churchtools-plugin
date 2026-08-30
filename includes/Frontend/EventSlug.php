<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Baut und liest die sprechende Adresse eines Termins: „gottesdienst-06-09-2026".
 *
 * Titel *und* Datum, nicht der Titel allein — das war eine Entscheidung an
 * echten Daten. In der Testinstanz stehen 120 Termine auf 29 verschiedene
 * Titel, „Gottesdienst" allein 21-mal: Ein Titel-Slug benennt eine Serie, nicht
 * ein Vorkommnis. Und nicht die lokale ID, obwohl die eindeutig wäre: Sie ist
 * ein Auto-Increment dieser Installation, das ein erneuter Vollsync neu
 * vergibt. Titel und Datum stehen dagegen im Termin selbst, und dieselbe
 * Adresse zeigt nach einem Neuaufbau der Tabelle wieder auf denselben Termin.
 *
 * Deshalb gibt es hier auch keine Slug-Spalte in `ctp_events`: Der Slug ist
 * eine Ableitung, keine gespeicherte Eigenschaft. Wird der Titel eines Termins
 * in ChurchTools geändert, ändert sich die Adresse mit — das ist bei einer
 * abgeleiteten Adresse die ehrliche Folge und kein Datenverlust.
 *
 * Bewusste Grenze: Zwei Termine mit demselben Titel am selben Tag (der 9-Uhr-
 * und der 11-Uhr-Gottesdienst) teilen sich eine Adresse; sie führt auf den
 * früheren der beiden. Ein Zeit-Anhängsel im Slug wäre die Abhilfe, kostet
 * aber jede Adresse ihre Lesbarkeit, damit ein seltener Fall aufgeht.
 */
final class EventSlug
{
    /**
     * Datum als d-m-Y, in derselben Reihenfolge wie die deutsche Schreibweise
     * daneben auf der Seite (06.09.2026 → 06-09-2026). Punkte gehen in einer
     * URL nicht, Bindestriche schon.
     */
    private const DATE_FORMAT = 'd-m-Y';

    public static function forEvent(array $event): string
    {
        $title = sanitize_title((string) ($event['title'] ?? ''));
        if ($title === '') {
            // sanitize_title() liefert für einen Titel ohne lateinische
            // Zeichen (rein kyrillisch, rein Emoji) einen leeren String. Ein
            // Slug, der nur aus dem Datum besteht, wäre von parse() unten
            // nicht mehr als Titel+Datum zu lesen.
            $title = 'termin';
        }

        return $title . '-' . self::formatDate((string) ($event['start_date'] ?? ''));
    }

    /**
     * Zerlegt einen Slug wieder in Titel-Slug und Datum (Y-m-d), oder gibt
     * null zurück, wenn er nicht dieser Form entspricht.
     *
     * @return array{title: string, date: string}|null
     */
    public static function parse(string $slug): ?array
    {
        if (preg_match('/^(?<title>.+)-(?<d>\d{2})-(?<m>\d{2})-(?<y>\d{4})$/', $slug, $m) !== 1) {
            return null;
        }

        $day = (int) $m['d'];
        $month = (int) $m['m'];
        $year = (int) $m['y'];

        // Ohne diese Prüfung würde „sommerfest-31-02-2026" als gültige Anfrage
        // durchgehen und eine leere Datumsabfrage auslösen statt einer 404.
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return [
            'title' => $m['title'],
            'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
        ];
    }

    /**
     * Prüft, ob ein Termin zu einem geparsten Titel-Slug gehört — über
     * denselben Weg, auf dem der Slug entstanden ist, statt über einen
     * Vergleich der Rohtitel.
     */
    public static function matchesTitle(array $event, string $titleSlug): bool
    {
        $slug = sanitize_title((string) ($event['title'] ?? ''));

        return ($slug === '' ? 'termin' : $slug) === $titleSlug;
    }

    private static function formatDate(string $startDate): string
    {
        $timestamp = strtotime($startDate);

        return gmdate(self::DATE_FORMAT, $timestamp === false ? 0 : $timestamp);
    }
}
