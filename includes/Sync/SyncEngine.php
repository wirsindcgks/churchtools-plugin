<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use DateTimeImmutable;

final class SyncEngine
{
    public static function registerHooks(): void
    {
        add_action('ctp_run_sync', [self::class, 'run']);
    }

    public static function run(): void
    {
        $settings = SettingsPage::get();
        $calendarIds = SettingsPage::getEnabledCalendarIds();

        if ($settings['instance'] === '' || $settings['api_key'] === '' || $calendarIds === []) {
            return;
        }

        $client = new Client(SettingsPage::getBaseUrl(), SettingsPage::getDecryptedApiKey());
        $repository = new EventRepository();

        // current_datetime() (unlike `new DateTimeImmutable()`) is anchored to the
        // timezone configured in WordPress, matching how start_date/end_date are
        // stored (see toMysqlDate()) and how EventRepository::findUpcoming() already
        // determines "now" via current_time().
        $from = current_datetime()->setTime(0, 0);
        $to = $from->modify('+1 year');

        $appointmentEnvelopes = $client->getEvents($calendarIds, $from, $to);
        $keepOccurrenceKeys = [];

        foreach ($appointmentEnvelopes as $envelope) {
            $row = self::mapOccurrence($envelope);

            if ($row === null) {
                continue;
            }

            $repository->upsert($row);
            $keepOccurrenceKeys[] = $row['ct_event_id'] . ':' . $row['start_date'];
        }

        $repository->deleteOrphans($calendarIds, $from, $to, $keepOccurrenceKeys);

        update_option('ctp_last_sync', current_time('mysql'));
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
