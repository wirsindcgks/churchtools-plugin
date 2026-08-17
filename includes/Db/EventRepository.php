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

    /**
     * Uses INSERT ... ON DUPLICATE KEY UPDATE instead of $wpdb->replace() (which
     * deletes+reinserts the row on conflict) deliberately: attachment_id is populated
     * separately by SyncEngine's image import step and must survive being upserted
     * again on the next sync run, or every hourly sync would trigger a fresh
     * re-download.
     */
    public function upsert(array $event): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i
                (ct_event_id, ct_calendar_id, title, subtitle, description, start_date, end_date, all_day, location, image_url, raw_data, updated_at)
             VALUES (%d, %d, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                ct_calendar_id = VALUES(ct_calendar_id),
                title = VALUES(title),
                subtitle = VALUES(subtitle),
                description = VALUES(description),
                end_date = VALUES(end_date),
                all_day = VALUES(all_day),
                location = VALUES(location),
                image_url = VALUES(image_url),
                raw_data = VALUES(raw_data),
                updated_at = VALUES(updated_at)',
            $this->table,
            $event['ct_event_id'],
            $event['ct_calendar_id'],
            $event['title'],
            $event['subtitle'] ?? '',
            $event['description'] ?? '',
            $event['start_date'],
            $event['end_date'],
            !empty($event['all_day']) ? 1 : 0,
            $event['location'] ?? '',
            $event['image_url'] ?? '',
            wp_json_encode($event['raw_data'] ?? []),
            current_time('mysql')
        ));
    }

    /**
     * Image import happens per series (ct_event_id), not per occurrence row — a
     * recurring series has many rows sharing the same image, and re-downloading it
     * for each would be wasteful. Reads back the currently-stored attachment_id from
     * any one row of the series so SyncEngine can decide whether a (re-)import is
     * needed (see SyncEngine::syncSeriesImage() for why the comparison uses the
     * attachment's own postmeta rather than this table's image_url column).
     */
    public function getSeriesAttachmentId(int $ctEventId): ?int
    {
        global $wpdb;

        $attachmentId = $wpdb->get_var($wpdb->prepare(
            'SELECT attachment_id FROM %i WHERE ct_event_id = %d LIMIT 1',
            $this->table,
            $ctEventId
        ));

        return $attachmentId !== null ? (int) $attachmentId : null;
    }

    /**
     * Applies the imported (or cleared) attachment to every row of the series at
     * once — all occurrences of a recurring event share one ct_event_id and the same
     * source image.
     */
    public function setSeriesAttachment(int $ctEventId, ?int $attachmentId): void
    {
        global $wpdb;

        $wpdb->update($this->table, ['attachment_id' => $attachmentId], ['ct_event_id' => $ctEventId]);
    }

    public function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $this->table));
    }

    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table, $id), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Plain "the next N events" query, without a date window — used by the
     * admin overview and by the count-based "upcoming" layout, which shows a
     * fixed number of events rather than a time span (see EventListRenderer).
     */
    public function findUpcoming(array $calendarIds = [], int $limit = 10): array
    {
        return $this->findInWindow($calendarIds, null, null, $limit);
    }

    /**
     * The frontend paging's workhorse: everything still upcoming whose
     * start_date falls into the half-open [$startFrom, $startBefore) window (see
     * EventWindow for how those bounds are derived). Both bounds are optional —
     * null means "unbounded on that side", which is what makes findUpcoming()
     * above a special case of this method rather than a second query.
     *
     * $limit is a cap, not the driver: 0 means "everything in the window", which
     * is the normal case now that the window decides how much is shown.
     * MySQL treats LIMIT 0 as "no rows", so it has to be omitted rather than
     * passed through as a zero — and OFFSET is only valid alongside a LIMIT,
     * so both are appended together or not at all (with $offset only ever
     * non-zero when a cap is in play, see EventPager).
     */
    public function findInWindow(
        array $calendarIds = [],
        ?string $startFrom = null,
        ?string $startBefore = null,
        int $limit = 0,
        int $offset = 0
    ): array {
        global $wpdb;

        $sql = 'SELECT * FROM %i WHERE end_date >= %s';
        $params = [$this->table, current_time('mysql')];

        if ($startFrom !== null) {
            $sql .= ' AND start_date >= %s';
            $params[] = $startFrom;
        }

        if ($startBefore !== null) {
            $sql .= ' AND start_date < %s';
            $params[] = $startBefore;
        }

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        $sql .= ' ORDER BY start_date ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = max(0, $offset);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from string literals plus a dynamically-sized "%d,%d,..." placeholder list (WordPress's own documented pattern for IN clauses with a variable-length array), then passed straight into $wpdb->prepare() with matching positional $params.
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Whether anything is still upcoming at or after $startFrom — the "is there
     * another page?" question behind the load-more button. Deliberately a
     * SELECT 1 ... LIMIT 1 rather than a COUNT(*): the caller only needs the
     * yes/no, and counting would scan every remaining row of the sync horizon.
     */
    public function hasEventsFrom(array $calendarIds, string $startFrom): bool
    {
        global $wpdb;

        $sql = 'SELECT 1 FROM %i WHERE end_date >= %s AND start_date >= %s';
        $params = [$this->table, current_time('mysql'), $startFrom];

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        $sql .= ' LIMIT 1';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findInWindow() above.
        return $wpdb->get_var($wpdb->prepare($sql, ...$params)) !== null;
    }

    public function deleteOlderThan(DateTimeInterface $cutoff): int
    {
        global $wpdb;

        $affectedSeries = $this->seriesAttachmentsWhere('end_date < %s', [$cutoff->format('Y-m-d H:i:s')]);

        $deleted = $wpdb->query(
            $wpdb->prepare('DELETE FROM %i WHERE end_date < %s', $this->table, $cutoff->format('Y-m-d H:i:s'))
        );

        $this->deleteOrphanedAttachments($affectedSeries);

        return (int) $deleted;
    }

    public function deleteByEventId(int $ctEventId): void
    {
        global $wpdb;

        $attachmentId = $this->getSeriesAttachmentId($ctEventId);

        $wpdb->delete($this->table, ['ct_event_id' => $ctEventId]);

        // Deletes the whole series in one call, so any attachment it had is by
        // definition orphaned now — no "last remaining row" check needed here.
        if ($attachmentId !== null) {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * Removes rows inside the just-synced window that no longer came back from
     * ChurchTools — e.g. a single occurrence of a recurring series was cancelled, or
     * a recurrence rule was shortened. $keepOccurrenceKeys uses the same
     * "{ct_event_id}:{start_date}" format the sync builds while upserting, so a row
     * survives exactly when its series+occurrence pair was present in this run.
     */
    public function deleteOrphans(
        array $calendarIds,
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $keepOccurrenceKeys
    ): int {
        global $wpdb;

        if ($calendarIds === []) {
            return 0;
        }

        $calendarPlaceholders = implode(',', array_fill(0, count($calendarIds), '%d'));
        $whereSql = 'ct_calendar_id IN (' . $calendarPlaceholders . ') AND start_date BETWEEN %s AND %s';
        $whereParams = [...$calendarIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        if ($keepOccurrenceKeys !== []) {
            $keyPlaceholders = implode(',', array_fill(0, count($keepOccurrenceKeys), '%s'));
            $whereSql .= " AND CONCAT(ct_event_id, ':', start_date) NOT IN ({$keyPlaceholders})";
            array_push($whereParams, ...$keepOccurrenceKeys);
        }

        $affectedSeries = $this->seriesAttachmentsWhere($whereSql, $whereParams);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from string literals plus dynamically-sized "%d,%d,..." / "%s,%s,..." placeholder lists (WordPress's own documented pattern for IN/NOT IN clauses with variable-length arrays), then passed straight into $wpdb->prepare() with matching positional $params.
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ' . $whereSql, $this->table, ...$whereParams));

        $this->deleteOrphanedAttachments($affectedSeries);

        return (int) $deleted;
    }

    /**
     * Returns the distinct ct_event_id => attachment_id pairs among rows matching
     * $whereSql — read *before* a delete runs, so deleteOrphanedAttachments() can
     * check afterwards whether each series still has any row left.
     */
    private function seriesAttachmentsWhere(string $whereSql, array $whereParams): array
    {
        global $wpdb;

        $sql = 'SELECT DISTINCT ct_event_id, attachment_id FROM %i WHERE attachment_id IS NOT NULL AND ' . $whereSql;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from string literals plus dynamically-sized placeholder lists (see deleteOlderThan()/deleteOrphans()), then passed straight into $wpdb->prepare() with matching positional params.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->table, ...$whereParams), ARRAY_A);

        $series = [];
        foreach ($rows ?: [] as $row) {
            $series[(int) $row['ct_event_id']] = (int) $row['attachment_id'];
        }

        return $series;
    }

    /**
     * Deletes each series' attachment only if no row of that series remains — a
     * retention cutoff or orphan cleanup can remove some occurrences of a recurring
     * series while leaving future ones (and their shared attachment) in place.
     */
    private function deleteOrphanedAttachments(array $ctEventIdToAttachmentId): void
    {
        global $wpdb;

        foreach ($ctEventIdToAttachmentId as $ctEventId => $attachmentId) {
            $stillExists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(1) FROM %i WHERE ct_event_id = %d',
                $this->table,
                $ctEventId
            ));

            if ($stillExists === 0) {
                wp_delete_attachment($attachmentId, true);
            }
        }
    }
}
