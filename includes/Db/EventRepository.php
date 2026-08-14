<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Db;

use DateTimeInterface;

final class EventRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ctp_events';
    }

    public function upsert(array $event): void
    {
        global $wpdb;

        $wpdb->replace($this->table, [
            'ct_event_id' => $event['ct_event_id'],
            'ct_calendar_id' => $event['ct_calendar_id'],
            'title' => $event['title'],
            'subtitle' => $event['subtitle'] ?? '',
            'description' => $event['description'] ?? '',
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'all_day' => !empty($event['all_day']) ? 1 : 0,
            'location' => $event['location'] ?? '',
            'image_url' => $event['image_url'] ?? '',
            'raw_data' => wp_json_encode($event['raw_data'] ?? []),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function findUpcoming(array $calendarIds = [], int $limit = 10): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table} WHERE end_date >= %s";
        $params = [current_time('mysql')];

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        $sql .= ' ORDER BY start_date ASC LIMIT %d';
        $params[] = $limit;

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $results ?: [];
    }

    public function deleteOlderThan(DateTimeInterface $cutoff): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table} WHERE end_date < %s", $cutoff->format('Y-m-d H:i:s'))
        );

        return (int) $deleted;
    }

    public function deleteByEventId(int $ctEventId): void
    {
        global $wpdb;
        $wpdb->delete($this->table, ['ct_event_id' => $ctEventId]);
    }

    /**
     * Removes rows inside the just-synced window that no longer came back from
     * ChurchTools — e.g. a single occurrence of a recurring series was cancelled, or
     * a recurrence rule was shortened. $keepOccurrenceKeys uses the same
     * "{ct_event_id}:{start_date}" format the sync builds while upserting, so a row
     * survives exactly when its series+occurrence pair was present in this run.
     */
    public function deleteOrphans(array $calendarIds, DateTimeInterface $from, DateTimeInterface $to, array $keepOccurrenceKeys): int
    {
        global $wpdb;

        if ($calendarIds === []) {
            return 0;
        }

        $calendarPlaceholders = implode(',', array_fill(0, count($calendarIds), '%d'));
        $sql = "DELETE FROM {$this->table} WHERE ct_calendar_id IN ({$calendarPlaceholders}) AND start_date BETWEEN %s AND %s";
        $params = [...$calendarIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        if ($keepOccurrenceKeys !== []) {
            $keyPlaceholders = implode(',', array_fill(0, count($keepOccurrenceKeys), '%s'));
            $sql .= " AND CONCAT(ct_event_id, ':', start_date) NOT IN ({$keyPlaceholders})";
            array_push($params, ...$keepOccurrenceKeys);
        }

        $deleted = $wpdb->query($wpdb->prepare($sql, ...$params));

        return (int) $deleted;
    }
}
