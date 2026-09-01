<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Db;

use DateTimeInterface;

/**
 * Not `final` solely so PHPUnit can double it: SyncEngine::syncSeriesImage()
 * takes this class directly, and tests/Sync/SeriesImageTest.php asserts which
 * repository calls that method makes for a given series state — behavior that
 * caused a live data bug (see that test's docblock) and is worth pinning down.
 * Nothing subclasses it in production.
 */
class EventRepository
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

        // "AND attachment_id IS NOT NULL" is load-bearing, not a tidiness filter:
        // a recurring series grows new occurrence rows on every sync, and
        // upsert() INSERTs those without an attachment_id (the image import runs
        // afterwards, per series). An unqualified LIMIT 1 therefore returns
        // whichever row the storage engine happens to hand back first - often a
        // brand-new NULL one - even though the series has a perfectly good
        // attachment, which made this method report "never imported" at random.
        $attachmentId = $wpdb->get_var($wpdb->prepare(
            'SELECT attachment_id FROM %i WHERE ct_event_id = %d AND attachment_id IS NOT NULL LIMIT 1',
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

    /**
     * Gespeicherte Termine je Kalender, aufgeschluesselt nach "kommend" und
     * "gesamt" - die Zahl, die im Tab „Kalender“ neben jedem Eintrag steht.
     * Ohne sie sieht eine Kalenderliste, in der ein Kalender seit Monaten
     * nichts mehr liefert, genauso aus wie eine gesunde.
     *
     * Eine Abfrage fuer alle Kalender statt einer pro Zeile: die Liste hat so
     * viele Zeilen, wie ChurchTools Kalender kennt, und das kann zweistellig
     * werden.
     *
     * @return array<int, array{total: int, upcoming: int}>
     */
    public function countsByCalendar(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ct_calendar_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN end_date >= %s THEN 1 ELSE 0 END) AS upcoming
             FROM %i
             GROUP BY ct_calendar_id',
            current_time('mysql'),
            $this->table
        ), ARRAY_A);

        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(int) $row['ct_calendar_id']] = [
                'total' => (int) $row['total'],
                'upcoming' => (int) $row['upcoming'],
            ];
        }

        return $counts;
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
     *
     * $search narrows the same query by title/subtitle/location — the frontend
     * search box (searchUpcoming() below, which is this method without bounds)
     * and the eventfinder both need it, and the finder needs it *together with*
     * a date range, which is why it lives here rather than in a second query
     * that would have to grow its own copy of the window handling.
     */
    public function findInWindow(
        array $calendarIds = [],
        ?string $startFrom = null,
        ?string $startBefore = null,
        int $limit = 0,
        int $offset = 0,
        string $search = ''
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

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= ' AND (title LIKE %s OR subtitle LIKE %s OR location LIKE %s)';
            array_push($params, $like, $like, $like);
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
     * Alle Termine eines Kalendertages, aufsteigend — die Auflösung der
     * sprechenden Adresse (siehe Frontend\EventSlug). Der Titel-Teil des Slugs
     * wird danach in PHP verglichen, nicht in SQL: Aus `sanitize_title()` führt
     * kein Weg zurück, den eine WHERE-Klausel nachbilden könnte, und ein Tag
     * hat eine Handvoll Termine, keine Handvoll Tausend.
     *
     * Als Bereich über `start_date` statt als `DATE(start_date) = %s`: Eine
     * Funktion um die Spalte herum schließt den Index auf `start_date` aus, den
     * diese Tabelle als einzigen hat.
     *
     * Anders als findInWindow() ohne `end_date >= jetzt`: Eine Adresse, die
     * jemand aus einem Newsletter oder aus dem Verlauf aufruft, soll den
     * Termin auch am Tag danach noch zeigen und nicht ins Leere laufen,
     * solange die Zeile in der Tabelle steht (siehe `retention_days`).
     *
     * @param string $date Y-m-d
     * @param int[]  $calendarIds Leer heißt „jeder Kalender in der Tabelle".
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOnDate(string $date, array $calendarIds = []): array
    {
        global $wpdb;

        $sql = 'SELECT * FROM %i WHERE start_date >= %s AND start_date <= %s';
        $params = [$this->table, $date . ' 00:00:00', $date . ' 23:59:59'];

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        $sql .= ' ORDER BY start_date ASC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findInWindow() above: string literals plus a generated "%d,%d,..." list, prepared with matching positional params.
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Which of the given calendars actually have something coming up — the
     * calendars the frontend's filter dropdown and the eventfinder's
     * "Thema"-buttons are allowed to offer.
     *
     * Without it the toolbar offered every *configured* calendar, and a
     * calendar that happens to have nothing scheduled (a wedding calendar in a
     * quiet quarter, say) was a button that led to an empty list — the visitor
     * had no way of telling that apart from a broken filter.
     *
     * DISTINCT over the whole sync horizon rather than a per-calendar EXISTS:
     * one pass answers it for every calendar at once, and the table is indexed
     * on start_date, not on ct_calendar_id.
     *
     * @param int[] $calendarIds Empty means "every calendar in the table".
     *
     * @return int[]
     */
    public function calendarIdsWithUpcoming(array $calendarIds = []): array
    {
        global $wpdb;

        $sql = 'SELECT DISTINCT ct_calendar_id FROM %i WHERE end_date >= %s';
        $params = [$this->table, current_time('mysql')];

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findInWindow() above.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return array_map(static fn (array $row): int => (int) $row['ct_calendar_id'], (array) $rows);
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

    /**
     * Ob im *abgefragten* Zeitfenster gespeicherte Termine liegen - die Frage
     * hinter dem Leer-Antwort-Schutz in SyncEngine::doRun().
     *
     * Bewusst nicht hasEventsFrom(): das beantwortet die Frage der
     * Load-more-Schaltflaeche ("kommt noch etwas?") und passt hier an beiden
     * Enden nicht. Oben fehlt ihm die Grenze, sodass Zeilen jenseits des
     * Sync-Horizonts (nach einem verkuerzten Zeitraum) eine berechtigt leere
     * Antwort zur Stoerung machen wuerden; unten zaehlt es mit end_date >= jetzt
     * die heute bereits beendeten Termine nicht mit - die deleteOrphans() aber
     * sehr wohl loescht, weil dessen Untergrenze start_date ist.
     *
     * @param int[] $calendarIds
     */
    public function hasEventsBetween(array $calendarIds, string $from, string $to): bool
    {
        global $wpdb;

        $sql = 'SELECT 1 FROM %i WHERE start_date >= %s AND start_date <= %s';
        $params = [$this->table, $from, $to];

        if ($calendarIds !== []) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '%d'));
            $sql .= " AND ct_calendar_id IN ({$placeholders})";
            array_push($params, ...$calendarIds);
        }

        $sql .= ' LIMIT 1';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findInWindow() above.
        return $wpdb->get_var($wpdb->prepare($sql, ...$params)) !== null;
    }

    /**
     * Backs the admin "Events" tab, which — unlike every frontend query — has
     * to be able to look at past occurrences and at all calendars regardless
     * of what's enabled, and to narrow that down by hand.
     *
     * @param array{calendar_id?: int, search?: string, scope?: string} $filters
     *        scope: 'upcoming' (default) | 'past' | 'all'.
     */
    public function findForAdmin(array $filters, int $limit = 25, int $offset = 0): array
    {
        global $wpdb;

        [$where, $params] = $this->adminWhere($filters);
        $direction = ($filters['scope'] ?? 'upcoming') === 'past' ? 'DESC' : 'ASC';

        $sql = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY start_date ' . $direction . ' LIMIT %d OFFSET %d';
        array_push($params, max(1, $limit), max(0, $offset));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from string literals plus the placeholder list adminWhere() returns alongside its matching params, then passed straight into $wpdb->prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->table, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Total matching findForAdmin()'s filters, for the pager. Counted rather
     * than derived from the returned page, since the page is capped.
     *
     * @param array{calendar_id?: int, search?: string, scope?: string} $filters
     */
    public function countForAdmin(array $filters): int
    {
        global $wpdb;

        [$where, $params] = $this->adminWhere($filters);
        $sql = 'SELECT COUNT(*) FROM %i WHERE ' . $where;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findForAdmin().
        return (int) $wpdb->get_var($wpdb->prepare($sql, $this->table, ...$params));
    }

    /**
     * One row per ChurchTools series instead of one per occurrence — the same
     * filters, collapsed. A parish with a handful of weekly series turns 155
     * occurrence rows into 43 series rows, which is the difference between
     * scrolling and actually finding something.
     *
     * Grouped by ct_event_id plus the fields that are constant within a series
     * anyway, so the aggregate stays valid under ONLY_FULL_GROUP_BY.
     *
     * @param array{calendar_id?: int, search?: string, scope?: string} $filters
     */
    public function findSeriesForAdmin(array $filters, int $limit = 25, int $offset = 0): array
    {
        global $wpdb;

        [$where, $params] = $this->adminWhere($filters);
        $direction = ($filters['scope'] ?? 'upcoming') === 'past' ? 'DESC' : 'ASC';

        $sql = 'SELECT ct_event_id, ct_calendar_id, title, subtitle,
                       COUNT(*) AS occurrences,
                       MIN(start_date) AS first_start,
                       MAX(start_date) AS last_start,
                       MAX(attachment_id) AS attachment_id,
                       MIN(id) AS sample_id
                FROM %i WHERE ' . $where . '
                GROUP BY ct_event_id, ct_calendar_id, title, subtitle
                ORDER BY first_start ' . $direction . ' LIMIT %d OFFSET %d';
        array_push($params, max(1, $limit), max(0, $offset));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findForAdmin().
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->table, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * @param array{calendar_id?: int, search?: string, scope?: string} $filters
     */
    public function countSeriesForAdmin(array $filters): int
    {
        global $wpdb;

        [$where, $params] = $this->adminWhere($filters);
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM %i WHERE ' . $where . ' GROUP BY ct_event_id) AS series';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see findForAdmin().
        return (int) $wpdb->get_var($wpdb->prepare($sql, $this->table, ...$params));
    }

    /**
     * Frontend search across the *entire* synced horizon, not just the month
     * window a page happens to have loaded (see EventWindow). The client-side
     * filter in assets/js/frontend.js can only ever match what is already in
     * the DOM, so searching "Taufe" on a list showing August/September silently
     * missed one in November — the single biggest findability gap in the
     * frontend.
     *
     * Hard-capped: this is reachable from a public endpoint, and a LIKE with a
     * leading wildcard cannot use an index.
     *
     * An unbounded findInWindow() with a search term, i.e. the same query
     * without the window — kept as its own method because that is the question
     * the search box asks, and because the cap belongs to *it* rather than to
     * every windowed query.
     */
    public function searchUpcoming(array $calendarIds, string $search, int $limit = 100): array
    {
        if (trim($search) === '') {
            return [];
        }

        return $this->findInWindow($calendarIds, null, null, max(1, min(200, $limit)), 0, $search);
    }

    /**
     * The four headline numbers on the Events tab. One grouped query rather
     * than four COUNT(*)s — the admin screen shows them together, and the
     * table is small enough that a single pass is cheaper than four scans.
     *
     * @return array{total: int, upcoming: int, past: int, with_image: int}
     */
    public function stats(): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN end_date >= %s THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN end_date < %s THEN 1 ELSE 0 END) AS past,
                SUM(CASE WHEN attachment_id IS NOT NULL THEN 1 ELSE 0 END) AS with_image
             FROM %i',
            current_time('mysql'),
            current_time('mysql'),
            $this->table
        ), ARRAY_A);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'past' => (int) ($row['past'] ?? 0),
            'with_image' => (int) ($row['with_image'] ?? 0),
        ];
    }

    /**
     * Shared WHERE fragment for the two admin queries above, so the list and
     * its own row count can never disagree about what "matching" means.
     *
     * @param array{calendar_id?: int, search?: string, scope?: string} $filters
     *
     * @return array{0: string, 1: array}
     */
    private function adminWhere(array $filters): array
    {
        global $wpdb;

        $clauses = ['1=1'];
        $params = [];

        $scope = $filters['scope'] ?? 'upcoming';
        if ($scope === 'upcoming') {
            $clauses[] = 'end_date >= %s';
            $params[] = current_time('mysql');
        } elseif ($scope === 'past') {
            $clauses[] = 'end_date < %s';
            $params[] = current_time('mysql');
        }

        $calendarId = (int) ($filters['calendar_id'] ?? 0);
        if ($calendarId > 0) {
            $clauses[] = 'ct_calendar_id = %d';
            $params[] = $calendarId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // esc_like() before the wildcards, so a literal "%" or "_" typed
            // into the search box matches itself instead of acting as one.
            $like = '%' . $wpdb->esc_like($search) . '%';
            $clauses[] = '(title LIKE %s OR subtitle LIKE %s OR location LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Attachments this plugin imported that no event row points at any more.
     *
     * These are debris, not normal operation: every other path already deletes
     * an attachment at the moment it becomes unreferenced (image changed,
     * series removed, retention cutoff). They accumulated because
     * getSeriesAttachmentId() used to report "never imported" at random for a
     * series that did have an attachment (see its docblock) - the image was
     * then downloaded a second time and the first copy left behind, unreferenced
     * and invisible except as a duplicate in the media library. Found live at
     * 34 orphans against 36 genuinely used attachments.
     *
     * Scoped by the '_ctp_source_image_url' postmeta, so only attachments this
     * plugin created itself are ever considered. Capped per run: a large
     * backlog gets worked off over several syncs rather than turning one cron
     * request into hundreds of file deletions.
     *
     * @return int[]
     */
    public function orphanedAttachmentIds(int $limit = 50): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a WordPress-provided table name, not request input; every value below goes through prepare().
        $sql = "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                  AND post_id NOT IN (SELECT attachment_id FROM %i WHERE attachment_id IS NOT NULL)
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see above.
        $ids = $wpdb->get_col($wpdb->prepare($sql, '_ctp_source_image_url', $this->table, max(1, $limit)));

        return array_map('intval', $ids ?: []);
    }

    /**
     * Removes every row belonging to a calendar that is no longer enabled.
     *
     * deleteOrphans() cannot do this: it is scoped to the calendars of the run
     * that just happened, so a calendar switched off in the settings simply
     * stops being visited and its rows sit there untouched. Retention only ever
     * caught them once they were in the past, which for a calendar full of
     * future appointments means up to sync_days_ahead + retention_days - well
     * over a year at the new defaults - of stale data lingering.
     *
     * @param int[] $enabledCalendarIds
     */
    public function deleteFromCalendarsNotIn(array $enabledCalendarIds): int
    {
        global $wpdb;

        if ($enabledCalendarIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($enabledCalendarIds), '%d'));
        $whereSql = 'ct_calendar_id NOT IN (' . $placeholders . ')';
        $whereParams = array_map('intval', $enabledCalendarIds);

        $affectedSeries = $this->seriesAttachmentsWhere($whereSql, $whereParams);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see deleteOrphans().
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ' . $whereSql, $this->table, ...$whereParams));

        $this->deleteOrphanedAttachments($affectedSeries);

        return (int) $deleted;
    }

    /**
     * Raeumt die Tabelle komplett ab - der Weg zurueck, wenn *kein* Kalender
     * mehr aktiv ist (siehe SyncEngine::run()).
     *
     * Bewusst eine eigene Methode statt deleteFromCalendarsNotIn([]): dort ist
     * die leere Liste der Fehlerfall, den die Schutzbedingung abfaengt - ohne
     * sie waere "NOT IN ()" ungueltiges SQL und die naheliegende Reparatur
     * ("dann eben alles loeschen") genau die Falle, in die ein leerer
     * Kalenderparameter aus Versehen laufen soll. Hier ist das Loeschen die
     * Absicht, und das soll am Namen zu sehen sein.
     */
    public function deleteAll(): int
    {
        global $wpdb;

        $affectedSeries = $this->seriesAttachmentsWhere('1=1', []);

        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i', $this->table));

        $this->deleteOrphanedAttachments($affectedSeries);

        return (int) $deleted;
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
     * Removes every row from $from onwards that did not come back from ChurchTools
     * in this run — a cancelled occurrence of a recurring series, a shortened
     * recurrence rule, or an appointment deleted outright. $keepOccurrenceKeys uses
     * the same "{ct_event_id}:{start_date}" format the sync builds while upserting,
     * so a row survives exactly when its series+occurrence pair was present.
     *
     * Deliberately unbounded at the top, where it used to stop at the sync horizon
     * ($to). Rows *beyond* the horizon were then never refreshed and never removed:
     * shortening "Sync-Zeitraum" from a year to 180 days left everything past day
     * 180 frozen in the database forever, still rendered by the frontend (which has
     * no upper bound either) and no longer tracking ChurchTools. Found live at 31
     * such rows, among them a wedding 12 months out that no sync had touched since.
     *
     * The lower bound stays: past occurrences are the retention job's business
     * (deleteOlderThan()), not this one's.
     */
    public function deleteOrphans(
        array $calendarIds,
        DateTimeInterface $from,
        array $keepOccurrenceKeys
    ): int {
        global $wpdb;

        if ($calendarIds === []) {
            return 0;
        }

        $calendarPlaceholders = implode(',', array_fill(0, count($calendarIds), '%d'));
        $whereSql = 'ct_calendar_id IN (' . $calendarPlaceholders . ') AND start_date >= %s';
        $whereParams = [...$calendarIds, $from->format('Y-m-d H:i:s')];

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
     * Selbst importierte Bilder, die die Zusatzgroessen aus CardImage::SIZES noch
     * nicht haben - fuer den nachtraeglichen Durchlauf (siehe ImageSizeBackfill).
     *
     * Erkannt am Fehlen des Postmeta CardImage::VERSION_META_KEY in der aktuellen
     * Fassung, nicht am Inhalt der Bild-Metadaten: Ob eine Groesse darin steckt,
     * liesse sich nur nach dem Deserialisieren pruefen, also erst nachdem man
     * jedes Bild einzeln geladen hat. So beantwortet eine einzige Abfrage die
     * Frage "ist ueberhaupt noch etwas zu tun?", und wenn nichts mehr offen ist,
     * kostet der Durchlauf genau diese eine Abfrage.
     *
     * Wie orphanedAttachmentIds() ueber '_ctp_source_image_url' auf die Bilder
     * begrenzt, die dieses Plugin selbst angelegt hat - fremde Anhaenge der
     * Mediathek gehen es nichts an. Und wie dort gedeckelt, damit ein grosser
     * Rueckstand ueber mehrere Laeufe abgearbeitet wird statt einen Cron-Lauf in
     * hunderte Bildskalierungen zu verwandeln.
     *
     * @return int[]
     */
    public function attachmentIdsMissingSizes(string $sizesVersion, string $versionMetaKey, int $limit = 10): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a WordPress-provided table name, not request input; every value below goes through prepare().
        $sql = "SELECT src.post_id FROM {$wpdb->postmeta} src
                LEFT JOIN {$wpdb->postmeta} done
                  ON done.post_id = src.post_id
                 AND done.meta_key = %s
                 AND done.meta_value = %s
                WHERE src.meta_key = %s
                  AND done.post_id IS NULL
                LIMIT %d";

        $params = [$versionMetaKey, $sizesVersion, '_ctp_source_image_url', max(1, $limit)];

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see above.
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$params));

        return array_map('intval', $ids ?: []);
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
