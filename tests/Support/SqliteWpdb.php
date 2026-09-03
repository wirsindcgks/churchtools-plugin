<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Support;

use PDO;

/**
 * Der schmalste $wpdb-Ersatz, mit dem sich eine Abfrage von EventRepository
 * wirklich *ausfuehren* laesst - nicht nur ihr SQL-Text vergleichen.
 *
 * Warum ueberhaupt: tests/bootstrap.php verzichtet bewusst auf wp-phpunit und
 * eine Testdatenbank (siehe dessen Docblock), weshalb alles, was $wpdb
 * anfasst, bisher ungetestet blieb - darunter hasEventsBetween(), die
 * Fensterabfrage hinter dem Leer-Antwort-Schutz. Deren Fehler waeren
 * Grenzfehler (eine Grenze zu viel, eine zu wenig, die falsche Spalte), und
 * genau die faengt ein Vergleich auf den erzeugten SQL-Text nicht: Er sagt
 * nur, dass dasteht, was jemand hingeschrieben hat.
 *
 * SQLite reicht dafuer, weil die geprueften Abfragen nur Vergleiche auf
 * "Y-m-d H:i:s"-Zeichenketten (dort lexikografisch = chronologisch, wie in
 * MySQL auf DATETIME), eine IN-Liste und LIMIT verwenden. Kein Ersatz fuer
 * echte Integrationstests und bewusst nicht dazu ausgebaut: Was hier fehlt
 * (CONCAT, ON DUPLICATE KEY UPDATE, Zeitzonen), gehoert in eine echte
 * WordPress-Testumgebung, nicht in eine Nachbildung davon.
 */
final class SqliteWpdb
{
    public string $prefix = 'wp_';

    private PDO $pdo;

    private int $nextEventId = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Nur die Spalten, welche die hier geprueften Abfragen lesen - die
        // uebrigen aus Installer::createTables() wuerden nichts entscheiden.
        $this->pdo->exec(
            'CREATE TABLE `wp_ctp_events` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ct_event_id INTEGER NOT NULL,
                ct_calendar_id INTEGER NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                attachment_id INTEGER NULL,
                title TEXT NOT NULL DEFAULT \'\',
                subtitle TEXT NOT NULL DEFAULT \'\',
                location TEXT NOT NULL DEFAULT \'\',
                updated_at TEXT NOT NULL DEFAULT \'\'
            )'
        );
    }

    /**
     * Legt eine Vorkommnis-Zeile an. $ctEventId ist nur dort interessant, wo
     * eine Terminserie mehrere Zeilen haben soll (Bildzuordnung), sonst
     * bekommt jede Zeile ihre eigene.
     */
    public function seedEvent(
        int $calendarId,
        string $startDate,
        string $endDate,
        ?int $attachmentId = null,
        ?int $ctEventId = null,
        array $text = []
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO `wp_ctp_events`
                (ct_event_id, ct_calendar_id, start_date, end_date, attachment_id, title, subtitle, location, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $ctEventId ?? ++$this->nextEventId,
            $calendarId,
            $startDate,
            $endDate,
            $attachmentId,
            $text['title'] ?? '',
            $text['subtitle'] ?? '',
            $text['location'] ?? '',
            // Wann der Abgleich die Zeile zuletzt angefasst hat - die Angabe,
            // die als <lastmod> in der Termin-Sitemap steht (EventSitemap).
            $text['updated_at'] ?? '',
        ]);
    }

    /**
     * Wie $wpdb->esc_like(): maskiert die LIKE-Metazeichen, damit ein Suchwort
     * mit "%" oder "_" als Text und nicht als Muster gilt.
     *
     * Nur da, damit die Suchabfrage hier überhaupt laufen kann - *ob* die
     * Maskierung wirkt, lässt sich mit SQLite nicht pruefen: MySQL nimmt den
     * Backslash in LIKE von sich aus als Escape-Zeichen, SQLite nur mit einem
     * ESCAPE-Zusatz, den die Abfrage (richtigerweise) nicht mitbringt. Ein
     * Test darauf würde hier also das Gegenteil dessen belegen, was in
     * Produktion passiert.
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function countRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(1) FROM `wp_ctp_events`')->fetchColumn();
    }

    /**
     * Setzt die Platzhalter ein wie $wpdb->prepare(): %i als Bezeichner, %s
     * zitiert, %d als Ganzzahl. Bewusst kein Nachbau der Sonderfaelle von
     * WordPress (%%, Platzhalter in Zeichenketten) - keine der geprueften
     * Abfragen enthaelt so etwas.
     */
    public function prepare(string $query, ...$args): string
    {
        $index = 0;

        return (string) preg_replace_callback(
            '/%[isd]/',
            function (array $match) use ($args, &$index): string {
                $value = $args[$index] ?? null;
                $index++;

                return match ($match[0]) {
                    '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                    '%d' => (string) (int) $value,
                    default => $this->pdo->quote((string) $value),
                };
            },
            $query
        );
    }

    /**
     * @return string|null
     */
    public function get_var(string $query)
    {
        $value = $this->pdo->query($query)->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function query(string $query): int
    {
        return $this->pdo->exec($query);
    }
}
