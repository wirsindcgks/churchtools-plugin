<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Groups;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\ImageImportFailures;
use ChurchToolsPlugin\Sync\RunLock;
use ChurchToolsPlugin\Sync\SyncEngine;
use RuntimeException;
use Throwable;

/**
 * Uebernimmt die Gruppen der angehakten Gruppen-Homepages als Kopie nach
 * WordPress - der Ersatz fuer den iframe, mit der Optik des Plugins.
 *
 * ChurchTools entscheidet selbst, welche Gruppen erscheinen und was an ihnen
 * zu sehen ist: Die Homepage „Hauskreise" hat zehn Untergruppen, zurueck kommen
 * zwei; wo die Homepage keine Gruppenbilder zeigt, fehlt `imageUrl` in der
 * Antwort. Eine zweite Auswahl in WordPress gibt es deshalb nicht.
 *
 * Abgefragt wird mit dem API-Key, wie jeder andere Aufruf (bis 1.26.0 ohne,
 * siehe Client::getGroupHomepages() fuer den Vergleich, der die Umstellung
 * getragen hat). Was die Antwort mit Key zusaetzlich traegt - Angaben ueber
 * den API-Benutzer, Leiter mit Personenobjekten -, bleibt draussen:
 * normalizeGroup() uebernimmt nur benannte Felder.
 *
 * Gespeichert wird in Optionen statt in einer Tabelle: Eine Homepage hat an der
 * Referenzinstanz hoechstens zwoelf Gruppen, und es gibt weder ein Zeitfenster
 * noch Paging, fuer das sich eine Tabelle lohnen wuerde. Beide Optionen ohne
 * Autoload - sie werden nur auf Seiten mit Gruppenliste gebraucht.
 */
final class GroupSync
{
    public const HOOK = 'ctp_run_group_sync';

    /**
     * Nach Homepage-ID: [ 'fetched' => mysql, 'empty_runs' => int, 'groups' => list<array> ].
     */
    public const DATA_OPTION = 'ctp_groups';

    /** Nach Gruppen-ID: Attachment-ID des importierten Bildes. */
    public const IMAGES_OPTION = 'ctp_group_images';

    public const ERROR_OPTION = 'ctp_group_sync_error';

    /**
     * Welche Gruppenbilder der letzte Lauf nicht uebernehmen konnte - wie bei
     * den Terminen eine Warnung neben dem Fehler (siehe ImageImportFailures).
     */
    public const IMAGE_WARNING_OPTION = 'ctp_group_image_warning';

    public const LAST_SYNC_OPTION = 'ctp_group_last_sync';

    public const HOMEPAGES_FETCHED_OPTION = 'ctp_group_homepages_fetched';

    /**
     * Eigener Merker statt '_ctp_source_image_url' - warum, steht an
     * SyncEngine::importImage().
     */
    public const IMAGE_META_KEY = '_ctp_group_source_image_url';

    /**
     * Wie viele leere Antworten in Folge eine Homepage leeren duerfen. Eine
     * leere Gruppenliste ist fuer sich ein gueltiger Fall (fuenf der elf
     * Homepages der Referenzinstanz sind leer), eine Homepage, die gestern noch
     * Gruppen hatte und heute keine, sieht aber genauso aus wie eine voruebergehend
     * entzogene Freigabe. Beim Standardintervall „taeglich" sind das drei Tage.
     */
    private const EMPTY_RUNS_BEFORE_CLEAR = 3;

    public static function registerHooks(): void
    {
        add_action(self::HOOK, [self::class, 'runScheduled']);
    }

    /** Fuer WP-Cron: Eine Action gibt nichts zurueck, run() sagt, ob es lief. */
    public static function runScheduled(): void
    {
        self::run();
    }

    /** Name der Sperre dieses Abgleichs, siehe Sync\RunLock. */
    public const LOCK = 'groups';

    /**
     * @return bool false, wenn gerade ein anderer Gruppen-Lauf die Sperre hielt
     */
    public static function run(): bool
    {
        return RunLock::run(self::LOCK, static function (): void {
            self::runUnlocked();
        });
    }

    private static function runUnlocked(): void
    {
        $baseUrl = Settings::getBaseUrl();

        if ($baseUrl === '') {
            return;
        }

        if (!ApiKey::isUsable()) {
            // Still bleiben, solange es nichts abzugleichen gibt - eine
            // Installation ohne Gruppen soll keine Gruppen-Meldung bekommen.
            if (GroupSettings::enabledHomepages() !== []) {
                update_option(self::ERROR_OPTION, [
                    'time' => current_time('mysql'),
                    'message' => ApiKey::unusableMessage(),
                ]);
            }

            return;
        }

        $client = new Client($baseUrl, ApiKey::current());
        $errors = [];

        // Faellt der Listenabruf aus, laufen die angehakten Homepages trotzdem:
        // Ihr Hash steht in den Einstellungen.
        try {
            $result = self::refreshHomepageList($client);

            if ($result['status'] === 'empty') {
                $errors[] = $result['message'];
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        $stored = self::storedData();
        $data = [];
        $now = current_time('mysql');

        foreach (GroupSettings::enabledHomepages() as $id => $homepage) {
            $id = (int) $id;

            try {
                $response = $client->getGroupHomepage((string) $homepage['hash']);
                $groups = [];

                foreach ((array) ($response['groups'] ?? []) as $group) {
                    $normalized = is_array($group) ? self::normalizeGroup($group, $baseUrl) : null;

                    if ($normalized !== null) {
                        $groups[] = $normalized;
                    }
                }

                $data[$id] = self::nextHomepageData($stored[$id] ?? null, $groups, $now);
                $data[$id]['filters'] = self::shownFilters($response);
            } catch (Throwable $exception) {
                $errors[] = sprintf('%s: %s', (string) $homepage['name'], $exception->getMessage());

                // Der Bestand bleibt, bis ein Abruf gelingt.
                if (isset($stored[$id])) {
                    $data[$id] = $stored[$id];
                }
            }
        }

        // Nicht mehr angehakte Homepages fallen hier weg, weil $data nur die
        // angehakten traegt - samt ihren Bildern, sofern keine andere Homepage
        // dieselbe Gruppe zeigt.
        update_option(self::DATA_OPTION, $data, false);

        $imageFailures = new ImageImportFailures();
        self::syncImages($data, $imageFailures);
        $imageFailures->store(self::IMAGE_WARNING_OPTION, $now);

        if ($errors === []) {
            delete_option(self::ERROR_OPTION);
            update_option(self::LAST_SYNC_OPTION, $now);

            return;
        }

        update_option(self::ERROR_OPTION, [
            'time' => $now,
            'message' => implode(' · ', $errors),
        ]);
    }

    /**
     * Gleicht die Liste der Homepages im Reiter mit ChurchTools ab.
     *
     * Mit dem Schutz aus CalendarList::refresh(): Eine leere Antwort
     * bei nicht leerem Bestand wird verworfen. Der Haken an einer Homepage
     * steht nirgends sonst, und eine einzige leere Antwort haette ihn geloescht
     * (siehe den Fall der Raumliste in 1.20.1).
     *
     * @return array{status: 'updated'|'empty', count: int, message: string}
     */
    public static function refreshHomepageList(Client $client): array
    {
        $settings = GroupSettings::get();
        $merged = self::mergeHomepages($settings['homepages'], $client->getGroupHomepages());

        if ($merged === [] && $settings['homepages'] !== []) {
            return [
                'status' => 'empty',
                'count' => 0,
                'message' => __('ChurchTools hat keine Gruppen-Homepages zurückgeliefert. Die gespeicherte Auswahl bleibt deshalb unverändert.', 'churchtools-plugin'),
            ];
        }

        if ($merged !== $settings['homepages']) {
            GroupSettings::saveHomepages($merged);
        }

        update_option(self::HOMEPAGES_FETCHED_OPTION, current_time('mysql'));

        return ['status' => 'updated', 'count' => count($merged), 'message' => ''];
    }

    /**
     * Baut die Liste aus der Antwort neu und traegt den Haken aus dem Bestand
     * mit. Die Eintraege nennen den Hash nicht als eigenes Feld, er steht am
     * Ende von `apiUrl`; die ID heisst `domainIdentifier` und ist dort eine
     * Zeichenkette. Ein Eintrag ohne brauchbaren Hash faellt heraus - ohne ihn
     * laesst sich die Homepage nicht abrufen.
     *
     * @return array<int, array{name: string, hash: string, enabled: bool}>
     */
    public static function mergeHomepages(array $existing, array $remote): array
    {
        $merged = [];

        foreach ($remote as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = (int) ($entry['domainIdentifier'] ?? 0);
            $path = (string) wp_parse_url((string) ($entry['apiUrl'] ?? ''), PHP_URL_PATH);
            $hash = basename($path);

            if ($id <= 0 || !Client::isValidHomepageHash($hash)) {
                continue;
            }

            $merged[$id] = [
                'name' => trim((string) ($entry['title'] ?? '')),
                'hash' => $hash,
                'enabled' => !empty($existing[$id]['enabled']),
            ];
        }

        uasort($merged, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $merged;
    }

    /**
     * Die Felder, die eine Kachel braucht, aus einer Gruppe der Homepage-Antwort.
     *
     * Freie Plaetze: Hoechstzahl minus Mitglieder minus offene Anfragen. Dass
     * offene Anfragen einen Platz belegen, ist eine Annahme aus dem Feldnamen
     * (`requestedSeatsCount`) - an der Referenzinstanz hat keine Gruppe mit
     * Hoechstzahl eine offene Anfrage, der Unterschied liess sich also nicht
     * nachsehen. Die Annahme irrt hoechstens zur vorsichtigen Seite: Sie zeigt
     * einen Platz zu wenig, nie einen zu viel.
     */
    public static function normalizeGroup(array $group, string $baseUrl): ?array
    {
        $id = (int) ($group['id'] ?? 0);
        $name = trim((string) ($group['name'] ?? ''));

        if ($id <= 0 || $name === '') {
            return null;
        }

        $information = is_array($group['information'] ?? null) ? $group['information'] : [];
        $maxMembers = (int) ($group['maxMemberCount'] ?? 0);
        $taken = (int) ($group['currentMemberCount'] ?? 0) + (int) ($group['requestedSeatsCount'] ?? 0);

        return [
            'id' => $id,
            'name' => $name,
            'note' => trim((string) ($information['note'] ?? '')),
            'image_url' => is_string($information['imageUrl'] ?? null) ? SyncEngine::sizedImageUrl($information['imageUrl']) : '',
            'weekday' => is_array($information['weekday'] ?? null)
                ? trim((string) ($information['weekday']['nameTranslated'] ?? ''))
                : '',
            // sortKey statt der ID: Sonntag hat die ID 0 und den sortKey 6 - die
            // Knoepfe des Gruppenfinders stehen damit Montag bis Sonntag.
            'weekday_sort' => self::sortKey($information['weekday'] ?? null),
            'meeting_time' => trim((string) ($information['meetingTime'] ?? '')),
            // „Jeder" ist eine gewaehlte Zielgruppe wie jede andere, keine
            // Vorgabe: An der Referenzinstanz tragen 7 von 17 Gruppen gar
            // keine (`null`, gelesen 2026-09-15). Die Zeile bleibt dann leer,
            // statt „Jeder" zu unterstellen.
            'target_group' => is_array($information['targetGroup'] ?? null)
                ? trim((string) ($information['targetGroup']['nameTranslated'] ?? ''))
                : '',
            // Der feste Schluessel („everyone") statt der Beschriftung: Der
            // Gruppenfinder erkennt „Jeder" daran, auch in einer anderen Sprache.
            'target_group_key' => is_array($information['targetGroup'] ?? null)
                ? trim((string) ($information['targetGroup']['name'] ?? ''))
                : '',
            'target_group_sort' => self::sortKey($information['targetGroup'] ?? null),
            // Kategorien tragen laut Spec `name` und kein `nameTranslated` - sie
            // sind selbst angelegte Stammdaten, keine uebersetzten Vorgaben.
            'category' => is_array($information['groupCategory'] ?? null)
                ? trim((string) ($information['groupCategory']['nameTranslated'] ?? $information['groupCategory']['name'] ?? ''))
                : '',
            'category_sort' => self::sortKey($information['groupCategory'] ?? null),
            'max_members' => $maxMembers > 0 ? $maxMembers : null,
            'free_places' => $maxMembers > 0 ? max(0, $maxMembers - $taken) : null,
            'waitinglist' => !empty($group['allowWaitinglist']),
            'url' => trailingslashit($baseUrl) . 'publicgroup/' . $id,
        ];
    }

    /** Der sortKey eines Stammdaten-Objekts, oder null, wenn es keins gibt. */
    private static function sortKey($value): ?int
    {
        return is_array($value) && is_numeric($value['sortKey'] ?? null) ? (int) $value['sortKey'] : null;
    }

    /**
     * Die Filter, die eine Homepage in ChurchTools eingeschaltet hat
     * (`filters[].show`), als Liste ihrer Typen - `weekday`, `targetgroups`,
     * `groupcategory` und so fort. Der Gruppenfinder bietet nur an, was die
     * Homepage selbst anbietet: ChurchTools entscheidet, was erscheint.
     *
     * @return list<string>
     */
    public static function shownFilters(array $response): array
    {
        $shown = [];

        foreach ((array) ($response['filters'] ?? []) as $filter) {
            if (is_array($filter) && !empty($filter['show']) && is_string($filter['type'] ?? null)) {
                $shown[] = $filter['type'];
            }
        }

        return array_values(array_unique($shown));
    }

    /**
     * Die eingeschalteten Filter ueber mehrere Homepages: Ein Filter gilt,
     * sobald *eine* ihn anbietet. null heisst „unbekannt" - Daten aus einem
     * Abgleich vor 1.31.0 tragen die Angabe noch nicht; bis zum naechsten
     * Lauf schraenkt der Finder dann nicht ein.
     *
     * @param int[] $homepageIds
     *
     * @return list<string>|null
     */
    public static function filtersFor(array $homepageIds): ?array
    {
        $stored = self::storedData();
        $shown = [];

        foreach ($homepageIds as $homepageId) {
            $filters = $stored[(int) $homepageId]['filters'] ?? null;

            if (!is_array($filters)) {
                return null;
            }

            $shown = array_merge($shown, $filters);
        }

        return array_values(array_unique($shown));
    }

    /**
     * Die angehakten Homepages, auf denen mindestens eine der Gruppen steht.
     *
     * @param int[] $groupIds
     *
     * @return int[]
     */
    public static function homepagesContaining(array $groupIds): array
    {
        $homepages = [];

        foreach (array_keys(GroupSettings::enabledHomepages()) as $homepageId) {
            foreach (self::groupsFor((int) $homepageId) as $group) {
                if (in_array((int) ($group['id'] ?? 0), $groupIds, true)) {
                    $homepages[] = (int) $homepageId;
                    break;
                }
            }
        }

        return $homepages;
    }

    /**
     * Was nach einem gelungenen Abruf fuer eine Homepage gespeichert wird.
     *
     * Eine leere Liste ersetzt einen nicht leeren Bestand erst nach
     * EMPTY_RUNS_BEFORE_CLEAR Laeufen in Folge - bis dahin bleiben die zuletzt
     * geladenen Gruppen stehen, und `fetched` bleibt auf ihrem Stand.
     *
     * @param array{fetched?: string, empty_runs?: int, groups?: array}|null $stored
     */
    public static function nextHomepageData(?array $stored, array $groups, string $now): array
    {
        $storedGroups = is_array($stored['groups'] ?? null) ? $stored['groups'] : [];

        if ($groups !== [] || $storedGroups === []) {
            return ['fetched' => $now, 'empty_runs' => 0, 'groups' => $groups];
        }

        $emptyRuns = (int) ($stored['empty_runs'] ?? 0) + 1;

        if ($emptyRuns >= self::EMPTY_RUNS_BEFORE_CLEAR) {
            return ['fetched' => $now, 'empty_runs' => 0, 'groups' => []];
        }

        return [
            'fetched' => (string) ($stored['fetched'] ?? ''),
            'empty_runs' => $emptyRuns,
            'groups' => $storedGroups,
        ];
    }

    /**
     * @return array<int, array{fetched: string, empty_runs: int, groups: array}>
     */
    public static function storedData(): array
    {
        $data = get_option(self::DATA_OPTION, []);

        return is_array($data) ? $data : [];
    }

    /**
     * Die gespeicherten Gruppen einer Homepage, in der Reihenfolge der Antwort.
     *
     * @return list<array>
     */
    public static function groupsFor(int $homepageId): array
    {
        $groups = self::storedData()[$homepageId]['groups'] ?? [];

        return is_array($groups) ? array_values(array_filter($groups, 'is_array')) : [];
    }

    /**
     * Alle Gruppen der angehakten Homepages, nach Gruppen-ID, ohne Doppelte -
     * die Auswahl fuer „einzelne Gruppen" in Shortcode, Block und WPBakery.
     *
     * Bewusst nur, was ueber eine angehakte Homepage abgeglichen ist, und kein
     * eigener Abruf je Gruppe: Sonst entschiede WordPress, welche Gruppe
     * oeffentlich erscheint, und nicht mehr die Homepage in ChurchTools (siehe
     * plan.md, G1). Eine noch gespeicherte, inzwischen abgewaehlte Homepage
     * zaehlt nicht mit - ihre Gruppen verschwinden mit dem naechsten Lauf.
     *
     * Steht eine Gruppe auf zwei Homepages, gewinnt der Eintrag mit Bild: Auf
     * einer Homepage mit abgeschalteten Gruppenbildern fehlt `image_url`, und
     * die Gruppe haette sonst je nach Reihenfolge der Homepages mal ein Bild
     * und mal keins.
     *
     * @return array<int, array>
     */
    public static function selectableGroups(): array
    {
        $groups = [];
        foreach (array_keys(GroupSettings::enabledHomepages()) as $homepageId) {
            foreach (self::groupsFor((int) $homepageId) as $group) {
                $id = (int) ($group['id'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                if (!isset($groups[$id]) || ((string) ($groups[$id]['image_url'] ?? '') === '' && (string) ($group['image_url'] ?? '') !== '')) {
                    $groups[$id] = $group;
                }
            }
        }

        return $groups;
    }

    /**
     * Die gewaehlten Gruppen in der angegebenen Reihenfolge. Was nicht (mehr)
     * waehlbar ist, faellt still heraus - auf der Website soll keine Luecke
     * und keine Fehlermeldung stehen; den Hinweis „nicht mehr verfuegbar"
     * zeigt der Editor.
     *
     * @param int[] $ids
     *
     * @return list<array>
     */
    public static function groupsByIds(array $ids): array
    {
        $available = self::selectableGroups();
        $selected = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && isset($available[$id]) && !isset($selected[$id])) {
                $selected[$id] = $available[$id];
            }
        }

        return array_values($selected);
    }

    /**
     * „123, 456,abc" → [123, 456]. Die Angabe kommt aus einem Shortcode, also
     * von Hand getippt.
     *
     * @return int[]
     */
    public static function parseIds(string $raw): array
    {
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw) ?: []), static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    /** @return array<int, int> */
    public static function imageMap(): array
    {
        $map = get_option(self::IMAGES_OPTION, []);

        return is_array($map) ? array_map('intval', $map) : [];
    }

    /**
     * Welches Bild je Gruppe gebraucht wird. Dieselbe Gruppe kann auf zwei
     * Homepages stehen, auf der einen mit Bild, auf der anderen ohne (dort ist
     * `showGroupImages` aus) - sie bekommt ein Bild, sobald eine Homepage eins
     * zeigt. Ob es auf der Kachel erscheint, entscheidet die Homepage selbst
     * (siehe GroupListRenderer).
     *
     * @return array<int, string> Gruppen-ID => Bildadresse
     */
    public static function wantedImages(array $data): array
    {
        $wanted = [];

        foreach ($data as $homepage) {
            foreach ((array) ($homepage['groups'] ?? []) as $group) {
                $url = (string) ($group['image_url'] ?? '');

                if ($url !== '' && !isset($wanted[(int) $group['id']])) {
                    $wanted[(int) $group['id']] = $url;
                }
            }
        }

        return $wanted;
    }

    /**
     * Importiert neue und geaenderte Bilder und loescht die, die keine Gruppe
     * mehr braucht. Wie bei den Terminen wird die Aenderung am Merker des
     * Anhangs selbst erkannt, nicht an der gespeicherten Adresse - ein
     * gescheiterter Download soll beim naechsten Lauf erneut versucht werden.
     */
    private static function syncImages(array $data, ?ImageImportFailures $failures = null): void
    {
        $map = self::imageMap();
        $wanted = self::wantedImages($data);
        $next = [];

        foreach ($wanted as $groupId => $url) {
            $previous = $map[$groupId] ?? null;

            if ($previous !== null && get_post_meta($previous, self::IMAGE_META_KEY, true) === $url) {
                $next[$groupId] = $previous;
                continue;
            }

            $imported = SyncEngine::importImage($url, self::IMAGE_META_KEY, 'churchtools-group-', $failures);

            if ($imported === null) {
                // Das alte Bild bleibt stehen, bis ein neues da ist.
                if ($previous !== null) {
                    $next[$groupId] = $previous;
                }
                continue;
            }

            $next[$groupId] = $imported;

            if ($previous !== null && $previous !== $imported) {
                wp_delete_attachment($previous, true);
            }
        }

        foreach ($map as $groupId => $attachmentId) {
            if (!isset($next[$groupId])) {
                wp_delete_attachment($attachmentId, true);
            }
        }

        update_option(self::IMAGES_OPTION, $next, false);
    }

    /**
     * @return array{time: string, message: string}|null
     */
    public static function getLastError(): ?array
    {
        $error = get_option(self::ERROR_OPTION, null);

        if (!is_array($error) || !is_scalar($error['time'] ?? null) || !is_scalar($error['message'] ?? null)) {
            return null;
        }

        return ['time' => (string) $error['time'], 'message' => (string) $error['message']];
    }

    /**
     * @return array{time: string, count: int, reasons: string}|null
     */
    public static function getImageWarning(): ?array
    {
        return ImageImportFailures::read(self::IMAGE_WARNING_OPTION);
    }

    /**
     * Raeumt alles ab, was die Gruppen angelegt haben - fuer uninstall.php,
     * das die Klasse nicht laden kann, steht dieselbe Liste dort noch einmal.
     */
    public static function optionNames(): array
    {
        return [
            GroupSettings::OPTION_KEY,
            self::DATA_OPTION,
            self::IMAGES_OPTION,
            self::ERROR_OPTION,
            self::IMAGE_WARNING_OPTION,
            self::LAST_SYNC_OPTION,
            self::HOMEPAGES_FETCHED_OPTION,
        ];
    }

    /**
     * Fuer den Knopf „Jetzt synchronisieren": Ein Lauf ohne Instanz ist kein
     * Lauf, das soll dort als Meldung ankommen statt als stiller Erfolg.
     */
    public static function runNow(): void
    {
        if (Settings::getBaseUrl() === '') {
            throw new RuntimeException(__('Bitte zuerst unter „Einstellungen → Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin'));
        }

        if (!ApiKey::isUsable()) {
            throw new RuntimeException(ApiKey::unusableMessage());
        }

        if (!self::run()) {
            throw new RuntimeException(RunLock::busyMessage());
        }

        $error = self::getLastError();

        if ($error !== null) {
            throw new RuntimeException($error['message']);
        }
    }
}
