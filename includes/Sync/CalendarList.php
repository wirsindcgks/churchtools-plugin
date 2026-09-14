<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Settings;

/**
 * Die Kalenderliste aus ChurchTools, abgeglichen mit den Einstellungen - fuer
 * den Knopf „Kalender von ChurchTools laden" und fuer jeden Sync-Lauf.
 *
 * Aus Admin\SettingsPage herausgeloest (Sicherheits-Review 2026-09-14), damit
 * der Sync nicht die Admin-Seite aufrufen muss, um seine eigene Arbeit zu tun.
 */
final class CalendarList
{
    /**
     * Wann die Kalenderliste zuletzt von ChurchTools geholt wurde. Steht in der
     * Statuszeile des Tabs „Kalender“ - ohne sie sieht eine Liste, die seit
     * einem halben Jahr niemand mehr aktualisiert hat, genauso aus wie eine
     * gerade eben geladene.
     */
    public const FETCHED_OPTION = 'ctp_calendars_fetched';

    /**
     * Holt die Kalenderliste von ChurchTools und schreibt sie zurueck - der
     * eine Weg fuer beide Aufrufer: den Knopf „Kalender von ChurchTools laden“
     * und den planmaessigen Sync (siehe SyncEngine::run()).
     *
     * Vorher lag diese Logik nur im AJAX-Handler, und die Kalenderliste
     * veraenderte sich damit ausschliesslich dann, wenn ein Mensch daran
     * dachte. Ein in ChurchTools umbenannter Kalender behielt im Plugin
     * monatelang seinen alten Namen, eine dort geaenderte Farbe kam nie an,
     * und ein neu angelegter Kalender tauchte in der Auswahl gar nicht erst
     * auf - waehrend der Sync daneben stuendlich lief.
     *
     * Wirft nur, was der Client wirft (Netz, HTTP-Fehler, unerwartete
     * Antwort). Die leere Antwort ist kein Fehler, sondern ein eigener
     * Zustand: merge() baut die Liste ausschliesslich aus der Antwort
     * neu auf, eine leere Antwort loeschte sie also samt eingestellter Farben
     * und Standardbilder. Ein kaputter Body wirft inzwischen in
     * Client::request(), ein wohlgeformtes data: [] kaeme aber weiterhin bis
     * hierher. Sind die Kalender wirklich alle weg, bleiben sie abwaehlbar in
     * der Liste stehen - ihre Termine raeumt der Sync ueber seinen eigenen
     * Schutz ab.
     *
     * @return array{status: 'updated'|'empty', count: int, changed: bool, message: string}
     */
    public static function refresh(Client $client): array
    {
        $settings = Settings::get();
        $merged = self::merge($settings['calendars'], $client->getCalendars());

        if ($merged === [] && $settings['calendars'] !== []) {
            return [
                'status' => 'empty',
                'count' => 0,
                'changed' => false,
                'message' => __('ChurchTools hat keine Kalender zurückgeliefert. Die gespeicherte Kalenderliste bleibt deshalb unverändert, damit eingestellte Farben und Standardbilder nicht verloren gehen – bitte die Berechtigungen des API-Keys prüfen. Sind die Kalender dort wirklich alle entfernt worden, lassen sie sich in der Liste einzeln abwählen.', 'churchtools-plugin'),
            ];
        }

        // Nur bei echter Aenderung schreiben. Der Sync ruft das jetzt
        // stuendlich auf, und ein update_option() mit unveraendertem Inhalt
        // waere zwar folgenlos, aber onSettingsUpdated() haengt an diesem
        // Hook - und der Frontend-Cache unten soll nicht stuendlich ohne Grund
        // verworfen werden.
        $changed = $merged !== $settings['calendars'];

        if ($changed) {
            // register_setting() hooks sanitize_option_{option} onto every
            // update_option() call for this option, not just Settings API form
            // submissions — without bypassing it (Settings::writeUnsanitized()), sanitizeSettings() would run
            // $merged (already-trusted, freshly-fetched data) back through
            // sanitizeCalendars()'s "only IDs already known" allowlist and silently
            // drop every calendar on the very first fetch (nothing was "known" yet).
            Settings::writeUnsanitized(array_merge($settings, ['calendars' => $merged]));
        }

        // Auch ohne Aenderung: der Zeitstempel beantwortet „wann wurde zuletzt
        // nachgesehen“, nicht „wann hat sich zuletzt etwas geaendert“.
        update_option(self::FETCHED_OPTION, current_time('mysql'));

        return [
            'status' => 'updated',
            'count' => count($merged),
            'changed' => $changed,
            'message' => '',
        ];
    }

    /**
     * Keeps enabled/color/default_image_id for calendars that still exist remotely,
     * seeds new ones as disabled with ChurchTools' own color, and drops ones that
     * were removed on the ChurchTools side — that a *single* calendar disappearing
     * takes its settings with it is deliberate: from here a revoked read permission
     * and a deleted calendar look identical, and "verschwindet aus der Liste" is the
     * right answer to both. Only the all-or-nothing case is guarded, by the caller
     * (see ajaxFetchCalendars()), because there the same ambiguity costs every
     * calendar at once. `default_color` is always overwritten
     * with ChurchTools' current value (never carried over from $existing) so the
     * "Auf Standardfarbe zurücksetzen" button (renderCalendarCard()) keeps pointing
     * at ChurchTools' actual color even if it changed there since the last fetch.
     */
    public static function merge(array $existing, array $remoteCalendars): array
    {
        $merged = [];

        foreach ($remoteCalendars as $calendar) {
            $id = (int) ($calendar['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $remoteColor = (string) ($calendar['color'] ?? '#3388ff');

            $merged[$id] = [
                'name' => (string) ($calendar['name'] ?? ''),
                'enabled' => (bool) ($existing[$id]['enabled'] ?? false),
                'color' => (string) ($existing[$id]['color'] ?? $remoteColor),
                'default_color' => $remoteColor,
                'default_image_id' => (int) ($existing[$id]['default_image_id'] ?? 0),
                'is_public' => self::isPublic($calendar),
            ];
        }

        return $merged;
    }

    /**
     * ChurchTools' eigene Einschaetzung, nicht unsere: `type` (`church` /
     * `group` / `personal`) ist der Nachfolger von `isPublic`/`isPrivate` -
     * beide stehen an der Instanz, gegen die dies verifiziert wurde, unter
     * `@deprecated` als Alias von `type`, und `isPublic === true` deckt sich
     * dort lueckenlos mit `type === 'church'`.
     *
     * Gewarnt wird nur auf eine ausdrueckliche Aussage hin: `type` group oder
     * personal, sonst ein `isPublic: false`. Ein `null` oder ein Typ, den
     * diese Fassung nicht kennt, ist keine Aussage - er faellt auf `isPublic`
     * zurueck und zuletzt auf `true`, wie ein ganz fehlendes Feld (aeltere
     * Instanz, geaenderte Antwortform). Ein Fehlalarm auf jedem Kalender
     * waere schlimmer als ein ausbleibender Hinweis. Deshalb `isset` und kein
     * `array_key_exists`: Die Fassung vor dem Umbau las `isPublic ?? true`
     * und liess ein `null` damit ebenfalls durch.
     */
    private static function isPublic(array $calendar): bool
    {
        $type = $calendar['type'] ?? null;

        if ($type === 'church') {
            return true;
        }

        if ($type === 'group' || $type === 'personal') {
            return false;
        }

        if (isset($calendar['isPublic'])) {
            return (bool) $calendar['isPublic'];
        }

        return true;
    }

    /**
     * Angehakte Kalender, die ChurchTools selbst nicht als oeffentlich fuehrt.
     *
     * Bewusst nur gemeldet und nicht stillschweigend uebergangen: Anders als
     * ein interner Termin (den SyncEngine::mapOccurrence() gar nicht erst
     * speichert) ist ein angehakter Kalender eine ausdrueckliche Entscheidung
     * im WordPress-Backend. Verschwaende sein Inhalt wortlos, suchte man den
     * Grund an der falschen Stelle - und die Person, die das Haekchen gesetzt
     * hat, sitzt genau dort, wo dieser Hinweis erscheint.
     *
     * @return array<int, string> Kalender-ID => Name
     */
    public static function nonPublicEnabled(): array
    {
        $found = [];

        foreach (Settings::get()['calendars'] as $id => $calendar) {
            if (empty($calendar['enabled'])) {
                continue;
            }

            // Fehlendes Feld gilt als oeffentlich - siehe merge().
            if (($calendar['is_public'] ?? true)) {
                continue;
            }

            $found[(int) $id] = (string) ($calendar['name'] ?? (string) $id);
        }

        return $found;
    }
}
