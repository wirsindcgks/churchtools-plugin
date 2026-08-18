<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
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

    public static function run(): void
    {
        $settings = SettingsPage::get();
        $calendarIds = SettingsPage::getEnabledCalendarIds();

        if ($settings['instance'] === '' || $settings['api_key'] === '' || $calendarIds === []) {
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
            update_option(self::OPTION_LAST_SYNC_ERROR, [
                'time' => current_time('mysql'),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{time: string, message: string}|null
     */
    public static function getLastError(): ?array
    {
        $error = get_option(self::OPTION_LAST_SYNC_ERROR, null);

        return is_array($error) ? $error : null;
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

        $repository->deleteOrphans($calendarIds, $from, $to, $keepOccurrenceKeys);

        // Sweeps up imported images nothing references any more (see
        // EventRepository::orphanedAttachmentIds() for how they came to exist).
        // Runs after the image loop above, so an attachment imported in this
        // very run has already been written to its series' rows.
        foreach ($repository->orphanedAttachmentIds() as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
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
