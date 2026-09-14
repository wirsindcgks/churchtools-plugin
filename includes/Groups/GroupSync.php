<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Groups;

use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Security\ApiKey;
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
        add_action(self::HOOK, [self::class, 'run']);
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
        $baseUrl = SettingsPage::getBaseUrl();

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
        self::syncImages($data);

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
     * Mit dem Schutz aus SettingsPage::refreshCalendars(): Eine leere Antwort
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
            'image_url' => is_string($information['imageUrl'] ?? null) ? $information['imageUrl'] : '',
            'weekday' => is_array($information['weekday'] ?? null)
                ? trim((string) ($information['weekday']['nameTranslated'] ?? ''))
                : '',
            'meeting_time' => trim((string) ($information['meetingTime'] ?? '')),
            'max_members' => $maxMembers > 0 ? $maxMembers : null,
            'free_places' => $maxMembers > 0 ? max(0, $maxMembers - $taken) : null,
            'waitinglist' => !empty($group['allowWaitinglist']),
            'url' => trailingslashit($baseUrl) . 'publicgroup/' . $id,
        ];
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
    private static function syncImages(array $data): void
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

            $imported = SyncEngine::importImage($url, self::IMAGE_META_KEY, 'churchtools-group-');

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
        if (SettingsPage::getBaseUrl() === '') {
            throw new RuntimeException(__('Bitte zuerst unter „Einstellungen → Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin'));
        }

        if (!ApiKey::isUsable()) {
            throw new RuntimeException(ApiKey::unusableMessage());
        }

        if (!self::run()) {
            throw new RuntimeException(SettingsPage::syncRunningMessage());
        }

        $error = self::getLastError();

        if ($error !== null) {
            throw new RuntimeException($error['message']);
        }
    }
}
