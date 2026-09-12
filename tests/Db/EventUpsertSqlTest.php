<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Db;

use ChurchToolsPlugin\Db\EventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Die INSERT-Anweisung des Syncs lässt sich nicht gegen die SQLite-Attrappe
 * ausführen — `ON DUPLICATE KEY UPDATE` gibt es dort nicht. Geprüft wird
 * deshalb ihr Bau: Spalten, Platzhalter und übergebene Werte müssen in Zahl
 * und Reihenfolge zusammenpassen.
 *
 * Der Anlass ist konkret: Beim Ergänzen von `location_at_church` waren drei
 * Stellen anzufassen — Spaltenliste, Platzhalter und Argumente.
 * Fehlt eine davon, verschiebt sich jeder folgende Wert um eine Spalte, und
 * `$wpdb->prepare()` meldet das erst zur Laufzeit im Cron-Lauf, wo es
 * niemandem auffällt.
 */
final class EventUpsertSqlTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class {
            public string $prefix = 'wp_';

            public string $sql = '';

            /** @var array<int, mixed> */
            public array $args = [];

            public function prepare(string $sql, ...$args): string
            {
                $this->sql = $sql;
                $this->args = $args;

                return $sql;
            }

            public function query(string $sql): int
            {
                return 1;
            }

            public function get_charset_collate(): string
            {
                return '';
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testEveryColumnHasItsPlaceholderAndItsValue(): void
    {
        (new EventRepository())->upsert($this->event());

        preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/s', $this->wpdb->sql, $matches);

        $columns = array_map('trim', explode(',', $matches[1]));
        $placeholders = array_map('trim', explode(',', $matches[2]));

        $this->assertCount(count($columns), $placeholders, 'Spalten und Platzhalter');

        // Ein Platzhalter mehr als Spalten: der Tabellenname (%i) steht vorn.
        $this->assertCount(count($placeholders) + 1, $this->wpdb->args, 'Platzhalter und Werte');
    }

    /**
     * Jede Spalte, die der Sync schreibt, muss auch im `ON DUPLICATE KEY
     * UPDATE` stehen — sonst bekäme sie ihren Wert genau einmal, beim ersten
     * Anlegen der Zeile, und jeder weitere Sync ließe den alten stehen. Für
     * eine Terminserie, deren Zeilen bei jedem Lauf wieder durch dieselbe
     * Anweisung gehen, heißt das: Die Angabe käme nie an.
     *
     * Bewusst über *alle* Spalten geprüft statt je Spalte einzeln: Beim
     * Ergänzen von `location_data` fehlte genau diese Zeile, und der Test,
     * der nur `location_at_church` kannte, hat es nicht gemerkt.
     */
    public function testEveryWrittenColumnIsAlsoUpdatedOnAnExistingRow(): void
    {
        (new EventRepository())->upsert($this->event());

        preg_match('/\(([^)]+)\)\s*VALUES/s', $this->wpdb->sql, $insert);
        preg_match('/ON DUPLICATE KEY UPDATE(.+)$/s', $this->wpdb->sql, $update);

        $columns = array_map('trim', explode(',', $insert[1]));

        foreach ($columns as $column) {
            // Die beiden Schlüsselspalten identifizieren die Zeile, die gerade
            // getroffen wurde - sie zu überschreiben hätte keinen Sinn.
            if (in_array($column, ['ct_event_id', 'start_date'], true)) {
                continue;
            }

            $this->assertStringContainsString(
                "{$column} = VALUES({$column})",
                $update[1],
                "Spalte {$column} wird beim ersten Anlegen geschrieben, aber nie wieder aktualisiert."
            );
        }
    }

    /**
     * Der Merker ist ein Ja/Nein in der Datenbank: `true` muss als 1
     * ankommen, nicht als „1"-Zeichenkette oder als leerer String.
     */
    public function testTheFlagArrivesAsOneOrZero(): void
    {
        $repository = new EventRepository();

        $repository->upsert($this->event(['location_at_church' => true]));
        $this->assertContains(1, $this->wpdb->args);

        $repository->upsert($this->event(['location_at_church' => false]));
        $this->assertNotContains(true, $this->wpdb->args);
    }

    private function event(array $overrides = []): array
    {
        return array_replace([
            'ct_event_id' => 123,
            'ct_calendar_id' => 7,
            'title' => 'Gottesdienst',
            'subtitle' => '',
            'description' => '',
            'start_date' => '2026-11-01 10:30:00',
            'end_date' => '2026-11-01 12:00:00',
            'all_day' => false,
            'location' => 'Saal 1',
            'location_at_church' => true,
            'image_url' => '',
            'raw_data' => [],
        ], $overrides);
    }
}
