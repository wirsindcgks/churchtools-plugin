<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Settings;

/**
 * Die Raumliste aus ChurchTools und die Auswahl darin - Zwilling von
 * CalendarList, aus Admin\SettingsPage herausgeloest (Sicherheits-Review
 * 2026-09-14).
 */
final class ResourceList
{
    /**
     * Dasselbe fuer die Raumliste. Sie kommt aus einem anderen Modul und
     * haengt an einer eigenen Freigabe des API-Keys - eine leere Liste kann
     * deshalb auch heissen „darf dieser Key nicht sehen" statt „gibt es
     * nicht", und dann ist der Zeitstempel die einzige Auskunft darueber, ob
     * ueberhaupt schon einmal nachgesehen wurde.
     */
    public const FETCHED_OPTION = 'ctp_resources_fetched';

    /**
     * ChurchTools benennt die Ressourcentypen ueber Uebersetzungsschluessel;
     * `resource.type.room` ist der Raum. Am Schluessel erkannt und nicht am
     * angezeigten Namen, weil der uebersetzt und umbenannt werden kann.
     */
    private const ROOM_TYPE_KEY = 'resource.type.room';

    /** Erlaubte Werte fuer `rooms_mode` - alles andere faellt auf den Standard zurueck. */
    public const MODES = [RoomLookup::MODE_EXCLUSIVE, RoomLookup::MODE_SINGLE, RoomLookup::MODE_ALL];

    /**
     * Zwilling von CalendarList::refresh(), mit demselben Schutz gegen die leere
     * Antwort - und der stand hier bis 1.20.0 nicht.
     *
     * Die Begruendung dafuer lautete: an der Raumliste haenge nichts, was
     * verloren gehen koenne (keine Farben, keine Standardbilder), der Haken
     * ueberlebe in $existing. Der zweite Halbsatz war der Fehler. Der Haken
     * ueberlebt nur, solange die ID wiederkommt - und merge() baut die
     * Liste ausschliesslich aus der Antwort neu auf. Eine einzige leere Antwort
     * (ein `data: []` kommt durch Client::request(), das nur einen fehlenden
     * `data`-Schluessel abfaengt) loeschte damit die ganze Auswahl, und die
     * naechste, wieder vollstaendige Antwort brachte die Raeume unangehakt
     * zurueck. Der Haken *ist* das, was verloren gehen kann: Er steht nirgends
     * sonst, und ob er fehlt, sieht man nicht an der Liste, sondern erst Tage
     * spaeter an fehlenden Ortsangaben im Frontend (Nutzerbefund 2026-09-08:
     * „Bei dem Update ging wohl die Raumauswahl verloren").
     *
     * Geschuetzt ist wie bei den Kalendern nur der Alles-oder-nichts-Fall.
     * Verschwindet ein einzelner Raum, verschwindet er weiterhin samt Haken -
     * von hier aus sehen ein in ChurchTools geloeschter Raum und ein
     * zurueckgezogenes „Ressource sehen" gleich aus, und „faellt aus der Liste"
     * ist auf beides die richtige Antwort. Nur wenn *alle* auf einmal gehen,
     * ist der Datenverlust groesser als jede Erklaerung dafuer.
     *
     * Dass gar keine Raeume ausgewaehlt sind, bleibt der Normalzustand dieses
     * Plugins: Ist auch die gespeicherte Liste leer, ist die leere Antwort kein
     * Sonderfall, sondern das erwartete Ergebnis eines API-Keys ohne Freigabe
     * fuer Ressourcen.
     *
     * @return array{status: 'updated'|'empty', count: int, changed: bool, message?: string}
     */
    public static function refresh(Client $client): array
    {
        $settings = Settings::get();
        $existing = $settings['resources'] ?? [];
        $masterdata = $client->getResourceMasterdata();

        $roomTypeIds = [];

        foreach ($masterdata['resourceTypes'] as $type) {
            if ((string) ($type['name'] ?? '') === self::ROOM_TYPE_KEY) {
                $roomTypeIds[] = (int) ($type['id'] ?? 0);
            }
        }

        // Keine Ersatzliste mehr aus allen Typen: merge() liest die
        // leere Liste selbst als „nicht filtern". Die Ersatzliste war der
        // zweite Weg in denselben Verlust - kam `resourceTypes` leer zurueck,
        // lief array_map() ueber ein leeres Array und ergab wieder eine leere
        // Erlaubnisliste, die dann *jeden* Raum aussortierte. Gemeint war das
        // Gegenteil: Wer die Typen nicht kennt, filtert nicht.
        $merged = self::merge($existing, $masterdata['resources'], $roomTypeIds);

        if ($merged === [] && $existing !== []) {
            return [
                'status' => 'empty',
                'count' => count($existing),
                'changed' => false,
                'message' => __('ChurchTools hat keine Räume zurückgeliefert. Die gespeicherte Raumliste bleibt deshalb unverändert, damit die angehakten Räume nicht verloren gehen – bitte die Freigabe „Ressource sehen“ des API-Keys prüfen. Sind die Räume dort wirklich alle entfernt worden, lassen sie sich in der Liste einzeln abwählen.', 'churchtools-plugin'),
            ];
        }

        $changed = $merged !== $existing;

        if ($changed) {
            // Wie bei CalendarList::refresh(): am Sanitizer vorbei, der frisch
            // geholte, noch unbekannte IDs herausfiltern wuerde.
            Settings::writeUnsanitized(array_merge($settings, ['resources' => $merged]));
        }

        update_option(self::FETCHED_OPTION, current_time('mysql'));

        return [
            'status' => 'updated',
            'count' => count($merged),
            'changed' => $changed,
        ];
    }

    /**
     * Zwilling von mergeCalendars(). Der Haken bleibt beim Betreiber, Name und
     * Sortierschluessel kommen bei jedem Abgleich frisch aus ChurchTools - ein
     * umbenannter Raum heisst damit auch hier neu, ohne dass jemand etwas tun
     * muss.
     *
     * Gegenstaende bleiben draussen. `/api/resource/masterdata` fuehrt neben
     * Raeumen auch Technik und Aehnliches; als Ortsangabe kommt davon nichts in
     * Frage, und eine Liste, in der man sie erst wegsehen muss, waere schlechter
     * als eine kurze. Erkannt wird das am Typ, nicht am Namen.
     *
     * Eine leere $roomTypeIds heisst „nicht filtern", nicht „nichts erlauben" -
     * eine Instanz, die ihre Typen anders benannt hat, bekommt lieber Technik
     * zu viel in der Liste als eine Liste, die wortlos leer bleibt (und dabei
     * jeden Haken mitnimmt, siehe refreshResources()).
     *
     * @param int[] $roomTypeIds IDs der Ressourcentypen, die Raeume sind; leer heisst „alle"
     */
    public static function merge(array $existing, array $remoteResources, array $roomTypeIds): array
    {
        $merged = [];
        $rooms = array_flip(array_map('intval', $roomTypeIds));

        foreach ($remoteResources as $resource) {
            $id = (int) ($resource['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            if ($rooms !== [] && !isset($rooms[(int) ($resource['resourceTypeId'] ?? 0)])) {
                continue;
            }

            $merged[$id] = [
                'name' => (string) ($resource['name'] ?? ''),
                'enabled' => (bool) ($existing[$id]['enabled'] ?? false),
                // ChurchTools' eigene Ordnung, nur zum Sortieren der Liste im
                // Backend - grosse Raeume oben, Testressourcen unten. Sie
                // entscheidet nichts, siehe RoomLookup.
                'sort_key' => (int) ($resource['sortKey'] ?? 0),
                // Das Feld „Ort" an der Ressource: ein Gebaeudename, keine
                // Anschrift (an der Instanz nachgesehen 2026-09-11). Es
                // beantwortet die einzige Frage, die von aussen nicht zu
                // erraten ist - liegt dieser Raum im Haus der Gemeinde?
                // Siehe idsInBuilding().
                'location' => (string) ($resource['location'] ?? ''),
            ];
        }

        return $merged;
    }

    /**
     * Alle bekannten Raeume, angehakt oder nicht. Gebraucht wird das fuer den
     * strengen Modus: Um zu wissen, ob *nebenher* noch ein Raum belegt ist, muss
     * der Sync auch die Buchungen der nicht angehakten Raeume sehen.
     *
     * @return int[]
     */
    public static function knownIds(): array
    {
        return array_map('intval', array_keys(Settings::get()['resources'] ?? []));
    }

    /**
     * Wie die Ortsangabe aus den Buchungen gebildet wird - siehe die
     * Beschreibung im Tab „Raeume" und die MODE_*-Konstanten.
     *
     * Der Rueckfall auf `rooms_exclusive` ist die Bruecke aus 1.12.0, wo an
     * dieser Stelle noch ein Kaestchen stand: Eine Installation, die damals
     * streng eingestellt war, bleibt es.
     */
    public static function mode(): string
    {
        return self::resolveMode(Settings::get());
    }

    public static function resolveMode(array $settings): string
    {
        $mode = (string) ($settings['rooms_mode'] ?? '');

        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return !empty($settings['rooms_exclusive']) ? RoomLookup::MODE_EXCLUSIVE : RoomLookup::MODE_SINGLE;
    }

    /**
     * Die im Backend angehakten Raeume. Ist nichts angehakt, fragt der Sync die
     * Buchungen gar nicht erst ab - das Ressourcenmodul kostet dann nichts.
     *
     * @return int[]
     */
    public static function enabledIds(): array
    {
        $resources = array_filter(Settings::get()['resources'] ?? [], static fn (array $r): bool => !empty($r['enabled']));

        // In ChurchTools' eigener Ordnung, weil diese Reihenfolge im Modus
        // „alle Raeume nennen" die Anzeigereihenfolge ist - sonst haengt die
        // Zeile daran, wer wann gebucht hat.
        uasort($resources, static fn (array $a, array $b): int
            => [$a['sort_key'] ?? 0, $a['name']] <=> [$b['sort_key'] ?? 0, $b['name']]);

        return array_map('intval', array_keys($resources));
    }

    /**
     * Die Raeume, die ChurchTools im angegebenen Gebaeude fuehrt.
     *
     * Der Vergleich geht ueber das Feld „Ort" an der Ressource gegen den Namen
     * der Gemeindeanschrift aus `/api/info` - beides sind Gebaeudenamen, die
     * dieselbe Person in dieselbe Instanz getippt hat, und genau deshalb
     * unterscheiden sie sich in Schreibweise und Leerzeichen (an der echten
     * Instanz steht der Gebaeudename an der Anschrift in Grossbuchstaben, an
     * den Raeumen gemischt - streng verglichen traefe kein einziger Raum).
     * Normalisiert wird deshalb auf Kleinschreibung ohne
     * Leerraum - und keinen Schritt weiter: Aus „Haus 2" darf nie „Haus"
     * werden.
     *
     * Ein leerer Gebaeudename heisst „keine Aussage moeglich" und liefert eine
     * leere Liste, nicht etwa alle Raeume: Die Anschrift der Gemeinde an einen
     * Raum zu haengen, von dem niemand weiss, wo er liegt, waere geraten.
     *
     * @return int[]
     */
    public static function idsInBuilding(string $buildingName): array
    {
        $needle = self::normalizeBuildingName($buildingName);

        if ($needle === '') {
            return [];
        }

        $found = [];

        foreach (Settings::get()['resources'] ?? [] as $id => $resource) {
            if (self::normalizeBuildingName((string) ($resource['location'] ?? '')) === $needle) {
                $found[] = (int) $id;
            }
        }

        return $found;
    }

    private static function normalizeBuildingName(string $name): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($name))) ?? '';
    }
}
