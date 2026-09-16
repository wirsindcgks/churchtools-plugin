<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Db;

use DateTimeInterface;

/**
 * SQL-Zugriffe fuer `wp_ctp_log` - dieselbe Aufteilung wie Log (Fassade) und
 * EventRepository (SQL): Log kennt kein SQL, diese Klasse kennt keine
 * Datenschutzregeln.
 */
final class LogRepository
{
    /** Wie viele Zeilen der Reiter "Protokoll" je Seite zeigt. */
    public const PAGE_SIZE = 50;

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ctp_log';
    }

    /**
     * @param array<string, mixed> $context bereits bereinigt (siehe Log::sanitizeContext())
     */
    public function insert(string $level, string $area, string $message, array $context): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (logged_at, level, area, message, context) VALUES (%s, %s, %s, %s, %s)',
            $this->table,
            current_time('mysql'),
            $level,
            $area,
            $message,
            $context === [] ? '' : wp_json_encode($context)
        ));
    }

    /**
     * @param array{level?: string, area?: string, since?: string} $filters
     *
     * @return array<int, array{id: int, logged_at: string, level: string, area: string, message: string, context: array<string, mixed>}>
     */
    public function find(array $filters = [], int $page = 1): array
    {
        global $wpdb;

        [$where, $params] = self::whereClause($filters);
        $offset = max(0, $page - 1) * self::PAGE_SIZE;

        // array_merge statt einer Argumentenliste, weil PHP eine Auspackung
        // (...$params) nicht vor weiteren Positionsargumenten erlaubt - $params
        // hat hier je nach Filter eine unterschiedliche Laenge.
        $args = array_merge([$this->table], $params, [self::PAGE_SIZE, $offset]);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where ist aus Literalen plus "level = %s"/"area = %s" gebaut (whereClause()), keine Nutzereingabe; die zugehoerigen Werte stehen in $params und gehen mit durch prepare().
        $sql = "SELECT id, logged_at, level, area, message, context FROM %i {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql ist aus Literalen plus $where gebaut (siehe oben) und geht direkt in prepare(); der Sniff kann die Platzhalter innerhalb von $where nicht mitzaehlen, $args traegt sie.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * @param array{level?: string, area?: string, since?: string} $filters
     */
    public function count(array $filters = []): int
    {
        global $wpdb;

        [$where, $params] = self::whereClause($filters);
        $args = array_merge([$this->table], $params);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- siehe find() oben.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM %i {$where}", ...$args));
    }

    /**
     * Loescht, was aelter als $cutoff ist, und kappt danach auf hoechstens
     * $maxEntries - beide Grenzen gelten unabhaengig voneinander (siehe
     * Sync\RetentionCleanup::run(), das taeglich aufraeumt).
     */
    public function prune(DateTimeInterface $cutoff, int $maxEntries): int
    {
        global $wpdb;

        $deletedByAge = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE logged_at < %s',
            $this->table,
            $cutoff->format('Y-m-d H:i:s')
        ));

        $excess = $this->count() - $maxEntries;

        if ($excess <= 0) {
            return $deletedByAge;
        }

        // Die "id"-Spalte waechst mit der Zeit (siehe insert()) und ist damit
        // fuer "die aeltesten zuerst" gleichwertig zu logged_at, aber ohne
        // Mehrdeutigkeit bei zwei Eintraegen derselben Sekunde. Die
        // Komma-Form von LIMIT ist absichtlich gewaehlt: SQLite (Tests) und
        // MySQL lesen sie beide als "OFFSET, LIMIT" (siehe SqliteWpdb).
        $cutoffId = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i ORDER BY id ASC LIMIT %d, 1',
            $this->table,
            $excess - 1
        ));

        if ($cutoffId === null) {
            return $deletedByAge;
        }

        $deletedByCount = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE id <= %d',
            $this->table,
            (int) $cutoffId
        ));

        return $deletedByAge + $deletedByCount;
    }

    /**
     * @param array{level?: string, area?: string, since?: string} $filters
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function whereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['level'])) {
            $conditions[] = 'level = %s';
            $params[] = (string) $filters['level'];
        }

        if (!empty($filters['area'])) {
            $conditions[] = 'area = %s';
            $params[] = (string) $filters['area'];
        }

        // "since" statt "since_or_equal": SyncHealthNotice fragt hiermit ab,
        // was seit dem als "logged_at" gespeicherten Erfolgszeitpunkt selbst
        // dazugekommen ist - der Erfolg zaehlt nicht als Warnung mit.
        if (!empty($filters['since'])) {
            $conditions[] = 'logged_at > %s';
            $params[] = (string) $filters['since'];
        }

        return $conditions === [] ? ['', []] : ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, logged_at: string, level: string, area: string, message: string, context: array<string, mixed>}
     */
    private static function hydrate(array $row): array
    {
        $context = json_decode((string) ($row['context'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'logged_at' => (string) $row['logged_at'],
            'level' => (string) $row['level'],
            'area' => (string) $row['area'],
            'message' => (string) $row['message'],
            'context' => is_array($context) ? $context : [],
        ];
    }
}
