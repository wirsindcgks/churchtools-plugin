<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

/**
 * Sammelt, woran Bild-Importe eines Laufs gescheitert sind, und haelt das als
 * Warnung fest.
 *
 * Anlass (2026-09-15): SyncEngine::importImage() gab bei jedem Fehler still
 * null zurueck. ChurchTools beantwortete den Bilddownload zwei Wochen lang mit
 * 401, jede Terminserie mit neuem Bild blieb ohne Bild - und weder der
 * Sync-Status noch die Uebersicht zeigten etwas, weil der Lauf selbst gelang.
 *
 * Eine Warnung neben dem Sync-Fehler und kein Sync-Fehler: Die Termine sind
 * das Wichtigere, und ein Lauf, der sie aktuell haelt, ist ein gelungener Lauf.
 * „Letzte Synchronisation" rueckt deshalb weiter, und SyncHealthNotice meldet
 * nichts. Stehen bleibt die Warnung, solange ein Lauf noch scheitert: Ein
 * gescheiterter Import wird bei jedem Lauf wiederholt (siehe
 * SyncEngine::syncSeriesImage()), der erste Lauf ohne Fehlschlag raeumt sie ab.
 *
 * Gezaehlt je Grund, nicht je Bild: Scheitern alle Bilder am selben 401, ist
 * das ein Befund und keine Liste von 35 Zeilen.
 */
final class ImageImportFailures
{
    /**
     * Wie viele verschiedene Gruende einzeln gefuehrt werden. Eine Meldung wie
     * „cURL error 28: Operation timed out after 300001 milliseconds" traegt
     * eine Zahl, die sich je Bild unterscheidet - ohne Deckel liefe die
     * Warnung mit jedem Bild um einen Eintrag laenger.
     */
    private const MAX_REASONS = 5;

    /** Laenger ist keine Meldung, die in einen Hinweiskasten gehoert. */
    private const MAX_REASON_LENGTH = 200;

    /** @var array<string, int> Grund => Anzahl */
    private array $reasons = [];

    public function record(string $reason): void
    {
        $reason = wp_html_excerpt(trim($reason), self::MAX_REASON_LENGTH, '…');

        if ($reason === '') {
            $reason = __('unbekannter Fehler', 'churchtools-plugin');
        }

        if (!isset($this->reasons[$reason]) && count($this->reasons) >= self::MAX_REASONS) {
            $reason = __('weitere Gründe', 'churchtools-plugin');
        }

        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }

    /**
     * Der Grund aus einem WP_Error von download_url() oder
     * media_handle_sideload().
     *
     * Der Status steht bei download_url() *nicht* in der Meldung: Fuer jede
     * Antwort ausser 200 heisst der Fehlercode `http_404`, die Meldung ist nur
     * der Statustext („Unauthorized"), und die Zahl liegt in den Daten unter
     * `code` (wp-admin/includes/file.php, gelesen an WordPress 7.1). Ohne sie
     * stuende in der Warnung „Unauthorized" - oder bei einem Server, der keinen
     * Statustext schickt, gar nichts.
     *
     * @param mixed $data Rueckgabe von WP_Error::get_error_data()
     */
    public static function reasonFor(string $message, $data): string
    {
        $message = trim($message);
        $status = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : 0;

        if ($status > 0) {
            return trim(sprintf('HTTP %d %s', $status, $message));
        }

        return $message;
    }

    public function count(): int
    {
        return array_sum($this->reasons);
    }

    /** @return array<string, int> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * Schreibt die Warnung dieses Laufs - oder raeumt die eines frueheren ab,
     * wenn in diesem nichts gescheitert ist. Nur aufrufen, wenn der Lauf bis
     * zu den Bildern gekommen ist: Ein Lauf, der vorher abbricht, hat nichts
     * versucht und darf eine bestehende Warnung nicht loeschen.
     */
    public function store(string $option, string $time): void
    {
        if ($this->reasons === []) {
            delete_option($option);

            return;
        }

        update_option($option, ['time' => $time, 'reasons' => $this->reasons], false);
    }

    /**
     * Die gespeicherte Warnung, mit derselben Formpruefung wie
     * SyncEngine::getLastError() und aus demselben Grund: In der Option kann
     * ein Wert aus einer anderen Version oder einem halben Backup liegen.
     *
     * @return array{time: string, count: int, reasons: string}|null `reasons`
     *         fertig fuer die Anzeige, `count` die Zahl der gescheiterten Bilder
     */
    public static function read(string $option): ?array
    {
        $stored = get_option($option, null);

        if (!is_array($stored) || !is_scalar($stored['time'] ?? null) || !is_array($stored['reasons'] ?? null)) {
            return null;
        }

        $reasons = [];

        foreach ($stored['reasons'] as $reason => $count) {
            if (is_int($count) && $count > 0) {
                $reasons[(string) $reason] = $count;
            }
        }

        if ($reasons === []) {
            return null;
        }

        return [
            'time' => (string) $stored['time'],
            'count' => array_sum($reasons),
            'reasons' => self::describe($reasons),
        ];
    }

    /**
     * „HTTP 401 Unauthorized (3×) · Die Antwort ist kein Bild (1×)" - der
     * haeufigste Grund zuerst.
     *
     * @param array<string, int> $reasons
     */
    public static function describe(array $reasons): string
    {
        arsort($reasons);

        $parts = [];

        foreach ($reasons as $reason => $count) {
            $parts[] = sprintf('%s (%d×)', $reason, $count);
        }

        return implode(' · ', $parts);
    }
}
