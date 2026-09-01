<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\EventQueryCache;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class SyncEngine
{
    public static function registerHooks(): void
    {
        add_action('ctp_run_sync', [self::class, 'run']);
    }

    private const OPTION_LAST_SYNC_ERROR = 'ctp_last_sync_error';
    private const OPTION_EMPTY_RUNS = 'ctp_empty_sync_runs';

    /**
     * Nach wie vielen leeren Antworten in Folge eine leere Antwort als richtig
     * gilt und geloescht werden darf - zusammen mit einer Mindestdauer, ueber
     * die sie sich erstrecken muessen. Siehe looksLikeApiFailure().
     */
    private const EMPTY_RUNS_BEFORE_DELETE = 3;

    /**
     * Was beim Abgleich der Kalenderliste schiefging - getrennt vom
     * Sync-Fehler, weil ein Fehlschlag hier den Terminabgleich weder aufhaelt
     * noch entwertet (siehe refreshCalendarList()).
     */
    private const OPTION_CALENDARS_ERROR = 'ctp_calendars_sync_error';

    public static function run(): void
    {
        $settings = SettingsPage::get();

        if ($settings['instance'] === '' || $settings['api_key'] === '') {
            return;
        }

        // Erst die Kalenderliste, dann die Termine - und in dieser Reihenfolge,
        // damit ein in ChurchTools geloeschter Kalender nicht gleich danach
        // noch einmal abgefragt wird und ein dort neu angelegter sofort in der
        // Auswahl auftaucht.
        self::refreshCalendarList();

        $calendarIds = SettingsPage::getEnabledCalendarIds();

        if ($calendarIds === []) {
            // Unter demselben Schutz wie der Abgleich darunter, aus demselben
            // Grund: Auch dieser Zweig laeuft unbeaufsichtigt per WP-Cron.
            //
            // Ein noch gespeicherter Fehler wird dabei abgeraeumt wie nach
            // einem gelungenen Abgleich: Er beschreibt einen Lauf, den es so
            // nicht mehr gibt - und stehen bleiben duerfte er nur, wenn ihn
            // noch jemand wiederholen koennte. Wird spaeter wieder ein
            // Kalender aktiviert, meldet ihn der naechste Lauf ohnehin erneut.
            try {
                self::cleanUpAfterLastCalendar();
                delete_option(self::OPTION_LAST_SYNC_ERROR);
            } catch (Throwable $exception) {
                self::rememberError($exception);
            }

            return;
        }

        // ctp_run_sync is hooked directly to this method (see registerHooks()) and
        // fires unattended via WP-Cron — an uncaught exception here (e.g. the
        // ChurchTools API being down, a 401, a network error) would otherwise fatal
        // the cron request with nobody noticing except via debug.log. Catching here
        // means both the cron path and the manual "Jetzt synchronisieren" button
        // (ajaxRunSync(), which calls this method directly) get a persisted,
        // user-visible error instead.
        try {
            self::doRun($settings, $calendarIds);
            delete_option(self::OPTION_LAST_SYNC_ERROR);
            update_option('ctp_last_sync', current_time('mysql'));
            EventQueryCache::flush();
        } catch (Throwable $exception) {
            self::rememberError($exception);
        }
    }

    /**
     * Zieht die Kalenderliste bei jedem Lauf mit nach.
     *
     * Bewusst nicht toedlich: Der Terminabgleich ist die Aufgabe dieses Laufs,
     * und ein API-Key, der Termine lesen darf, aber die Kalenderliste nicht,
     * wuerde sonst einen bis dahin funktionierenden Sync zum Erliegen bringen.
     * Der Fehlschlag verschwindet trotzdem nicht still: er landet in einer
     * eigenen Option, die der Tab „Kalender“ als Hinweis anzeigt, und der
     * Zeitstempel „zuletzt geladen“ bleibt stehen.
     */
    private static function refreshCalendarList(): void
    {
        try {
            $client = new Client(SettingsPage::getBaseUrl(), SettingsPage::getDecryptedApiKey());
            $result = SettingsPage::refreshCalendars($client);

            if ($result['status'] === 'empty') {
                update_option(self::OPTION_CALENDARS_ERROR, [
                    'time' => current_time('mysql'),
                    'message' => $result['message'],
                ]);

                return;
            }

            delete_option(self::OPTION_CALENDARS_ERROR);

            // Name und Farbe eines Kalenders stehen auf jeder Kachel im
            // Frontend - aendert sich die Liste, ist das Gerenderte veraltet.
            if ($result['changed']) {
                EventQueryCache::flush();
            }
        } catch (Throwable $exception) {
            update_option(self::OPTION_CALENDARS_ERROR, [
                'time' => current_time('mysql'),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Gleiche Form und gleiche Pruefung wie getLastError(), fuer den
     * Kalenderabgleich - der Tab „Kalender“ liest ihn.
     *
     * @return array{time: string, message: string}|null
     */
    public static function getLastCalendarError(): ?array
    {
        $error = get_option(self::OPTION_CALENDARS_ERROR, null);

        if (!is_array($error) || !is_scalar($error['time'] ?? null) || !is_scalar($error['message'] ?? null)) {
            return null;
        }

        return [
            'time' => (string) $error['time'],
            'message' => (string) $error['message'],
        ];
    }

    private static function rememberError(Throwable $exception): void
    {
        update_option(self::OPTION_LAST_SYNC_ERROR, [
            'time' => current_time('mysql'),
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Wer den letzten aktiven Kalender abwaehlt, hat bisher dessen Termine
     * behalten: Dieser Lauf stieg vorher sofort aus, und
     * deleteFromCalendarsNotIn([]) loescht per eigener Schutzbedingung nichts.
     * Im Frontend waren sie damit zwar unsichtbar ("alle Kalender" heisst
     * alle *aktiven*), in der Datenbank und im Admin-Tab "Events" aber
     * weiterhin da - ohne dass es dafuer eine Bedienung gab. Die
     * Aufbewahrungsfrist haette sie erst abgeraeumt, nachdem sie vergangen
     * sind.
     *
     * Kein Fall fuer den Leer-Antwort-Schutz weiter unten: Der schuetzt vor
     * einer Antwort der API, die nicht stimmt. Hier hat niemand die API
     * gefragt, hier steht eine Einstellung, die jemand von Hand gesetzt hat.
     */
    private static function cleanUpAfterLastCalendar(): void
    {
        $repository = new EventRepository();

        if ($repository->count() > 0) {
            $repository->deleteAll();
            EventQueryCache::flush();
        }

        // Der Kehraus fuer importierte Bilder, die keine Zeile mehr
        // referenziert - hier besonders, weil doRun() ihn nicht mehr ausfuehrt,
        // solange kein Kalender aktiv ist, und weil nach deleteAll() jedes
        // Bild aus einem frueheren Rueckstand endgueltig unreferenziert ist.
        // Pro Lauf gedeckelt (siehe orphanedAttachmentIds()), ein groesserer
        // Rueckstand wird also ueber mehrere Laeufe abgearbeitet - deshalb
        // steht das hier ausserhalb der Bedingung darueber.
        foreach ($repository->orphanedAttachmentIds() as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * Geprueft wird die Form, nicht nur der Typ: is_array() allein sagt nichts
     * ueber die Schluessel, und die drei Aufrufer greifen alle direkt auf
     * 'time' bzw. 'message' zu (SettingsPage::renderStatusOverview(),
     * SettingsPage::ajaxRunSync(), SyncHealthNotice::problem()). Ohne diese
     * Pruefung braeuchte jeder von ihnen sein eigenes ?? '' - und ein
     * vergessenes waere eine Warnung auf einer Admin-Seite. In der Option kann
     * durchaus etwas anderes liegen: ein Wert aus einer aelteren Version, ein
     * teilweise eingespieltes Backup, ein fremdes Plugin.
     *
     * @return array{time: string, message: string}|null
     */
    public static function getLastError(): ?array
    {
        $error = get_option(self::OPTION_LAST_SYNC_ERROR, null);

        if (!is_array($error) || !is_scalar($error['time'] ?? null) || !is_scalar($error['message'] ?? null)) {
            return null;
        }

        return [
            'time' => (string) $error['time'],
            'message' => (string) $error['message'],
        ];
    }

    private static function doRun(array $settings, array $calendarIds): void
    {
        // $settings['api_key'] (checked in run()) is the encrypted value, so it
        // stays non-empty even after an AUTH_KEY rotation breaks decryption —
        // without this check, a garbage/empty decrypted key would silently reach
        // the Client and fail as a generic 401 instead of this explicit message.
        if (SettingsPage::apiKeyDecryptionFailed()) {
            throw new RuntimeException(SettingsPage::apiKeyDecryptionErrorMessage());
        }

        $client = new Client(SettingsPage::getBaseUrl(), SettingsPage::getDecryptedApiKey());
        $repository = new EventRepository();

        // current_datetime() (unlike `new DateTimeImmutable()`) is anchored to the
        // timezone configured in WordPress, matching how start_date/end_date are
        // stored (see toMysqlDate()) and how EventRepository::findUpcoming() already
        // determines "now" via current_time().
        $from = current_datetime()->setTime(0, 0);
        $daysAhead = max(1, (int) $settings['sync_days_ahead']);
        $to = $from->modify("+{$daysAhead} days");

        $appointmentEnvelopes = $client->getEvents($calendarIds, $from, $to);

        // Ein leeres Ergebnis ist der einzige Fall, in dem "die API ist die
        // Wahrheit" gefaehrlich wird: deleteOrphans() laesst bei leerer
        // Keep-Liste seine Schutzbedingung komplett weg und wuerde dann jeden
        // kuenftigen Termin aller aktiven Kalender loeschen.
        //
        // Ein kaputter Body kommt hier nicht mehr an - den wirft
        // Client::request() inzwischen selbst. Uebrig bleibt die wohlgeformt
        // leere Antwort, und die ist entweder echt (der Kalender wurde geleert)
        // oder eine still entzogene Leseberechtigung. Beide sehen identisch aus,
        // deshalb nicht dauerhaft blockieren, sondern verzoegern: Erst wenn die
        // Antwort ueber mehrere Laeufe *und* ueber die Zeit mehrerer planmaessiger
        // Laeufe hinweg leer bleibt, gilt sie als richtig (siehe
        // looksLikeApiFailure()). Eine voruebergehende Stoerung ist bis dahin
        // vorbei, ein wirklich geleerter Kalender kommt von selbst durch - ohne
        // diesen Ausweg bliebe der Sync fuer immer stehen, weil die
        // Fehlermeldung nur ein erfolgreicher Lauf wieder abraeumt.
        //
        // Das Fenster der Rueckfrage ist genau das der API-Abfrage: oben bis
        // $to, damit Zeilen jenseits des Horizonts (nach einem verkuerzten
        // Zeitraum) keine berechtigt leere Antwort zur Stoerung machen; unten ab
        // $from ohne "laeuft noch", weil deleteOrphans() ab da loescht. Gefragt
        // wird nur bei leerer Antwort - sonst entscheidet sie nichts.
        $hasStoredInWindow = $appointmentEnvelopes === [] && $repository->hasEventsBetween(
            $calendarIds,
            $from->format('Y-m-d H:i:s'),
            $to->setTime(23, 59, 59)->format('Y-m-d H:i:s')
        );

        $emptyStreak = $hasStoredInWindow ? self::recordEmptyRun() : self::forgetEmptyRuns();

        $apiFailure = self::looksLikeApiFailure(
            $appointmentEnvelopes,
            $hasStoredInWindow,
            $emptyStreak,
            time(),
            Installer::intervalSeconds($settings['sync_interval'])
        );

        if ($apiFailure) {
            throw new RuntimeException(sprintf(
                /* translators: %d: number of consecutive empty responses so far */
                __('Die ChurchTools-API hat keine Termine zurückgeliefert, obwohl für diesen Zeitraum welche gespeichert sind (%d. Lauf ohne Ergebnis). Es wurde nichts gelöscht – bitte Verbindung und Kalender-Berechtigungen prüfen. Bleibt die Antwort über mehrere planmäßige Läufe hinweg leer, gilt sie als richtig und die gespeicherten Termine werden entfernt.', 'churchtools-plugin'),
                $emptyStreak['runs']
            ));
        }

        $keepOccurrenceKeys = [];

        // A recurring series has one image shared by every occurrence row, so the
        // "does this series need a (re-)import" check must happen once per series,
        // not once per row.
        $seriesImageUrls = [];

        foreach ($appointmentEnvelopes as $envelope) {
            $row = self::mapOccurrence($envelope);

            if ($row === null) {
                continue;
            }

            $ctEventId = $row['ct_event_id'];
            $seriesImageUrls[$ctEventId] = $row['image_url'];

            $repository->upsert($row);
            $keepOccurrenceKeys[] = $ctEventId . ':' . $row['start_date'];
        }

        foreach ($seriesImageUrls as $ctEventId => $imageUrl) {
            self::syncSeriesImage($repository, $ctEventId, $imageUrl);
        }

        $repository->deleteOrphans($calendarIds, $from, $keepOccurrenceKeys);

        // Ein in den Einstellungen abgewaehlter Kalender wird vom Sync nicht mehr
        // besucht - seine Zeilen muessen deshalb hier weg, sonst blieben sie bis
        // zum Ablauf der Aufbewahrungsfrist *nach* ihrem Termin liegen.
        $repository->deleteFromCalendarsNotIn($calendarIds);

        // Sweeps up imported images nothing references any more (see
        // EventRepository::orphanedAttachmentIds() for how they came to exist).
        // Runs after the image loop above, so an attachment imported in this
        // very run has already been written to its series' rows.
        foreach ($repository->orphanedAttachmentIds() as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * Ausgelagert, damit die Entscheidung ohne Netzwerk testbar ist (siehe
     * SyncEngineTest). Bewusst nur "gar nichts zurueckgekommen" statt einer
     * prozentualen Plausibilitaetsschwelle: Ein Kalender, der ueber die Zeit
     * wirklich schrumpft, wuerde an einer Schwelle dauerhaft haengenbleiben und
     * genau die Handarbeit erzeugen, die das hier vermeiden soll. Null gegen
     * nicht-null ist die eine Grenze, die sich nicht falsch kalibrieren laesst -
     * $consecutiveEmptyRuns sorgt dafuer, dass sie trotzdem nachgibt, wenn die
     * Null bestehen bleibt.
     *
     * Faellt ein *einzelner* Kalender still aus, greift das hier nicht - dann
     * liefern die uebrigen ja Termine. Das ist Absicht: War der Ausfall
     * voruebergehend, stellt der naechste Lauf die Zeilen wieder her; war er
     * dauerhaft (Berechtigung entzogen), ist das Loeschen die richtige Antwort.
     */
    private static function looksLikeApiFailure(
        array $appointmentEnvelopes,
        bool $hasStoredInWindow,
        array $emptyStreak,
        int $now,
        int $intervalSeconds
    ): bool {
        if ($appointmentEnvelopes !== [] || !$hasStoredInWindow) {
            return false;
        }

        // Drei Laeufe - aber auch die Zeit, die drei planmaessige Laeufe
        // brauchen. Ohne die zweite Bedingung waere der Ausweg ueber den Knopf
        // "Jetzt synchronisieren" in Sekunden erreichbar: ajaxRunSync() ruft
        // run() direkt auf, drei Klicks eines ratlosen Admins waeren drei
        // Laeufe - und geloescht wuerde ausgerechnet dann, wenn jemand gerade
        // *wegen* der Stoerung am Suchen ist. Die Begruendung fuer das
        // Nachgeben ist "die Stoerung war offensichtlich keine
        // voruebergehende", und das ist eine Aussage ueber Zeit, nicht ueber
        // Klicks. Fuer WP-Cron aendert die Bedingung nichts: Drei Laeufe
        // dauern dort ohnehin laenger als zwei Intervalle.
        $spreadRequired = (self::EMPTY_RUNS_BEFORE_DELETE - 1) * $intervalSeconds;

        return $emptyStreak['runs'] < self::EMPTY_RUNS_BEFORE_DELETE
            || ($now - $emptyStreak['since']) < $spreadRequired;
    }

    /**
     * Zaehlt den laufenden Streak leerer Antworten hoch und merkt sich, wann er
     * begonnen hat. Wird vor dem Werfen geschrieben, damit der naechste Lauf ihn
     * auch dann sieht, wenn dieser hier als Fehler endet.
     *
     * @return array{runs: int, since: int}
     */
    private static function recordEmptyRun(): array
    {
        $streak = self::emptyStreak();
        $streak['runs']++;
        update_option(self::OPTION_EMPTY_RUNS, $streak);

        return $streak;
    }

    /**
     * Eine Antwort mit Terminen (oder nichts Gespeichertes, das zu schuetzen
     * waere) setzt den Streak zurueck - nur *aufeinanderfolgende* leere
     * Antworten duerfen sich zum Loeschen aufsummieren. Der Vergleich davor
     * spart den Schreibzugriff im Normalfall.
     *
     * @return array{runs: int, since: int}
     */
    private static function forgetEmptyRuns(): array
    {
        if (get_option(self::OPTION_EMPTY_RUNS, null) !== null) {
            delete_option(self::OPTION_EMPTY_RUNS);
        }

        return ['runs' => 0, 'since' => time()];
    }

    /**
     * @return array{runs: int, since: int}
     */
    private static function emptyStreak(): array
    {
        $stored = get_option(self::OPTION_EMPTY_RUNS, null);

        if (!is_array($stored) || !isset($stored['runs'], $stored['since'])) {
            return ['runs' => 0, 'since' => time()];
        }

        return ['runs' => (int) $stored['runs'], 'since' => (int) $stored['since']];
    }

    /**
     * A ChurchTools appointment can be a recurring series ("every Monday", "Mon-Fri",
     * ...); /api/calendars/appointments already expands that into one envelope per
     * actual occurrence inside the requested date range, not one envelope per series.
     * The series-level fields (title, location, image, ...) live under
     * `appointment.base`; the occurrence's own start/end lives under
     * `appointment.calculated` — there is no `calculatedDates` list to iterate
     * (verified against a live response with a recurring "Gottesdienst" series:
     * 54 separate envelopes sharing one `base.id`, not one envelope with 54 dates).
     */
    private static function mapOccurrence(array $envelope): ?array
    {
        $base = $envelope['appointment']['base'] ?? [];
        $calculated = $envelope['appointment']['calculated'] ?? [];

        $eventId = (int) ($base['id'] ?? 0);
        $calendarId = (int) ($base['calendar']['id'] ?? 0);

        if ($eventId === 0 || $calendarId === 0 || empty($calculated['startDate']) || empty($calculated['endDate'])) {
            return null;
        }

        $image = $base['image'] ?? null;
        $imageUrl = is_array($image) ? (string) ($image['fileUrl'] ?? '') : '';
        $location = self::formatAddress(is_array($base['address'] ?? null) ? $base['address'] : null);

        return [
            'ct_event_id' => $eventId,
            'ct_calendar_id' => $calendarId,
            'title' => (string) ($base['title'] ?? ''),
            'subtitle' => (string) ($base['subtitle'] ?? ''),
            'description' => (string) ($base['description'] ?? ''),
            'start_date' => self::toMysqlDate((string) $calculated['startDate']),
            'end_date' => self::toMysqlDate((string) $calculated['endDate']),
            'all_day' => !empty($base['allDay']),
            'location' => $location,
            'image_url' => $imageUrl,
            'raw_data' => $envelope,
        ];
    }

    /**
     * Imports (or clears) the WP attachment for one series. The "did the image
     * change" check compares against the '_ctp_source_image_url' postmeta stored on
     * the *existing attachment itself* (set in importImage()), not against this
     * table's image_url column — that column gets overwritten with ChurchTools'
     * current value by every upsert() regardless of whether an import ever
     * succeeded, so comparing against it would mean a single failed download (e.g.
     * a transient network error) permanently stops future retries: the next sync
     * would find image_url already "matching" the failed URL and skip re-importing
     * forever. Comparing against the attachment's own postmeta instead means we only
     * ever consider an import successful once it actually is.
     */
    private static function syncSeriesImage(EventRepository $repository, int $ctEventId, string $newImageUrl): void
    {
        $previousAttachmentId = $repository->getSeriesAttachmentId($ctEventId);

        if ($newImageUrl === '') {
            if ($previousAttachmentId !== null) {
                wp_delete_attachment($previousAttachmentId, true);
                $repository->setSeriesAttachment($ctEventId, null);
            }

            return;
        }

        $importedUrl = $previousAttachmentId !== null
            ? get_post_meta($previousAttachmentId, '_ctp_source_image_url', true)
            : null;

        if ($importedUrl === $newImageUrl) {
            // Image unchanged - but occurrences added since the last import were
            // INSERTed with a NULL attachment_id (see EventRepository::upsert()),
            // and returning here used to leave them that way permanently: nothing
            // ever revisits a series whose image didn't change. Those rows then
            // fell back to the raw ChurchTools image_url in the frontend, i.e.
            // hotlinked exactly what importing the image exists to avoid.
            // Re-stamping the series is a single cheap UPDATE.
            $repository->setSeriesAttachment($ctEventId, $previousAttachmentId);

            return;
        }

        $newAttachmentId = self::importImage($newImageUrl);

        if ($newAttachmentId === null) {
            return;
        }

        $repository->setSeriesAttachment($ctEventId, $newAttachmentId);

        if ($previousAttachmentId !== null && $previousAttachmentId !== $newAttachmentId) {
            wp_delete_attachment($previousAttachmentId, true);
        }
    }

    /**
     * Sideloads into the media library unattached to any post (post_id 0) — the
     * event row's attachment_id column is the only reference that needs to track it,
     * and tying it to a post would have no meaningful post to tie it to. Records the
     * source URL as postmeta so syncSeriesImage() can detect future changes (see its
     * docblock for why that can't just be the events table's image_url column).
     *
     * Deliberately not media_sideload_image() (WP core's usual one-liner for this):
     * it requires the URL itself to end in a recognizable image extension
     * (`preg_match('/[^\?]+\.(jpe?g|jpe|gif|png|webp|avif)\b/i', $url)` internally)
     * and immediately fails with "Invalid image URL" otherwise — before even
     * attempting a download. ChurchTools' file download endpoints are query-string
     * based (`…?q=public/filedownload&id=…&filename=<hash, no extension>`), so every
     * single import failed this way (verified: 0 of 154 synced rows ever got an
     * attachment_id, despite 116 of them having an image_url). Downloads manually
     * instead, determining the real file type from the downloaded content via
     * getimagesize() — not from the URL — and converting it to WebP via
     * prepareForSideload() before storing it.
     */
    private static function importImage(string $url): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $downloadedFile = download_url($url);

        if (is_wp_error($downloadedFile)) {
            return null;
        }

        [$sideloadFile, $extension] = self::prepareForSideload($downloadedFile);

        if ($sideloadFile !== $downloadedFile) {
            wp_delete_file($downloadedFile);
        }

        if ($sideloadFile === null) {
            return null;
        }

        $attachmentId = media_handle_sideload([
            'name' => 'churchtools-event-' . md5($url) . '.' . $extension,
            'tmp_name' => $sideloadFile,
        ], 0);

        if (is_wp_error($attachmentId)) {
            wp_delete_file($sideloadFile);

            return null;
        }

        update_post_meta((int) $attachmentId, '_ctp_source_image_url', $url);
        // Beim Import sind die Zusatzgroessen aus CardImage::SIZES schon
        // mitgeschrieben worden (registriert an after_setup_theme, also lange
        // vor diesem Cron-Lauf). Der Vermerk haelt das fest, damit
        // ImageSizeBackfill dieses Bild gar nicht erst anfasst.
        update_post_meta((int) $attachmentId, CardImage::VERSION_META_KEY, CardImage::SIZES_VERSION);

        return (int) $attachmentId;
    }

    /**
     * Converts the downloaded image to WebP — smaller files, one consistent format
     * regardless of whatever ChurchTools originally stored it as — using WordPress'
     * own image editor abstraction (GD or Imagick, whichever the server has).
     * WebP encoding support isn't guaranteed on every host (this plugin's own local
     * dev environment's GD build lacks it, for instance, and Imagick isn't always
     * installed either), so this falls back to keeping the original format rather
     * than failing the whole import — a differently-formatted image still beats
     * hotlinking ChurchTools' original DSGVO-wise, which is the actual point.
     *
     * @return array{0: ?string, 1: ?string} File path to sideload (WebP copy or the
     *                                       original download) and its extension;
     *                                       both null if the file isn't a real image.
     */
    private static function prepareForSideload(string $downloadedFile): array
    {
        $editor = wp_get_image_editor($downloadedFile);

        if (!is_wp_error($editor) && $editor->supports_mime_type('image/webp')) {
            $webpFile = $downloadedFile . '.webp';
            $saved = $editor->save($webpFile, 'image/webp');

            if (!is_wp_error($saved)) {
                return [$webpFile, 'webp'];
            }
        }

        $imageInfo = @getimagesize($downloadedFile);
        $extension = $imageInfo !== false ? image_type_to_extension($imageInfo[2], false) : false;

        return $extension !== false ? [$downloadedFile, $extension] : [null, null];
    }

    /**
     * ChurchTools returns all timestamps in Zulu/UTC. mysql2date() (used by the
     * frontend template) treats a stored DATETIME string as already being in the
     * site's configured timezone, so it must be converted here — not just formatted
     * — or every displayed time would be off by the site's UTC offset.
     */
    private static function toMysqlDate(string $isoZuluDate): string
    {
        return (new DateTimeImmutable($isoZuluDate))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    private static function formatAddress(?array $address): string
    {
        if ($address === null) {
            return '';
        }

        $cityLine = trim(($address['zip'] ?? '') . ' ' . ($address['city'] ?? ''));

        $parts = array_filter([
            $address['name'] ?? null,
            $address['street'] ?? null,
            $cityLine !== '' ? $cityLine : null,
        ]);

        return implode(', ', $parts);
    }
}
