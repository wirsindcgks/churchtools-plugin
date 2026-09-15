<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Groups;

use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\RunLock;
use PHPUnit\Framework\TestCase;

/**
 * Die Antwortformen hier sind an der echten Instanz abgelesen (2026-09-14,
 * ChurchTools 3.136.2, mit und ohne Key - beide gleich), mit ausgedachten
 * Namen und Hashes.
 */
final class GroupSyncTest extends TestCase
{
    private const BASE = 'https://musterkirche.church.tools';

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_http();
        ctp_test_reset_deleted_attachments();
        ctp_test_reset_post_meta();
        ctp_test_set_current_time('2026-09-14 12:00:00');
        ctp_test_install_wpdb();
    }

    /**
     * Die Liste nennt den Hash nicht als Feld: Er steht am Ende von `apiUrl`.
     * Die ID heisst `domainIdentifier` und ist eine Zeichenkette.
     */
    public function testMergeHomepagesReadsHashFromApiUrlAndKeepsTheTick(): void
    {
        $merged = GroupSync::mergeHomepages(
            [9 => ['name' => 'Alt', 'hash' => 'alt', 'enabled' => true]],
            [
                $this->listEntry('9', 'Kleingruppen', 'AbC123'),
                $this->listEntry('6', 'Mitarbeit', 'XyZ789'),
            ]
        );

        $this->assertSame([
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => false],
        ], $merged);
    }

    /**
     * Der Hash landet im Pfad des naechsten Aufrufs. Was dort nicht hingehoert,
     * faellt schon beim Einlesen heraus.
     */
    public function testMergeHomepagesDropsEntriesWithoutUsableHashOrId(): void
    {
        $merged = GroupSync::mergeHomepages([], [
            ['domainIdentifier' => '3', 'title' => 'Ohne Adresse'],
            $this->listEntry('4', 'Punkt', 'ab.cd'),
            $this->listEntry('0', 'Ohne ID', 'AbC123'),
            'kein Array',
        ]);

        $this->assertSame([], $merged);
    }

    public function testMergeHomepagesSortsByName(): void
    {
        $merged = GroupSync::mergeHomepages([], [
            $this->listEntry('1', 'team', 'a1'),
            $this->listEntry('2', 'Abteilung', 'b2'),
        ]);

        $this->assertSame([2, 1], array_keys($merged));
    }

    public function testNormalizeGroupTakesTheFieldsOfARealGroup(): void
    {
        $group = GroupSync::normalizeGroup($this->group(44, 'Seniorenarbeit', [
            'maxMemberCount' => 60,
            'currentMemberCount' => 8,
            'requestedSeatsCount' => 0,
        ]), self::BASE);

        $this->assertSame([
            'id' => 44,
            'name' => 'Seniorenarbeit',
            'note' => 'Treff am Mittwoch',
            'image_url' => 'https://musterkirche.church.tools/images/5123/abc?w=1600&h=1600&fit=max',
            'weekday' => 'Mittwoch',
            'weekday_sort' => 2,
            'meeting_time' => '9:30',
            'target_group' => 'Jeder',
            'target_group_key' => 'everyone',
            'target_group_sort' => 1,
            'category' => '',
            'category_sort' => null,
            'max_members' => 60,
            'free_places' => 52,
            'waitinglist' => false,
            'url' => 'https://musterkirche.church.tools/publicgroup/44',
        ], $group);
    }

    /**
     * Ohne Parameter liefert ChurchTools ein Vorschaubild mit 150x150 Pixeln.
     * `fit=max` haelt das Seitenverhaeltnis und vergroessert nicht.
     */
    public function testImagesAreRequestedLargeInTheirOwnAspectRatio(): void
    {
        $this->assertSame(
            'https://musterkirche.church.tools/images/5123/abc?w=1600&h=1600&fit=max',
            GroupSync::sizedImageUrl('https://musterkirche.church.tools/images/5123/abc')
        );
        $this->assertSame('', GroupSync::sizedImageUrl(''));
        $this->assertSame('', GroupSync::sizedImageUrl('  '));
    }

    /** Eine vorhandene Abfrage bleibt stehen; die spaeteren Parameter gewinnen. */
    public function testAnExistingQueryIsKept(): void
    {
        $this->assertSame(
            'https://musterkirche.church.tools/images/5123/abc?v=2&w=1600&h=1600&fit=max',
            GroupSync::sizedImageUrl('https://musterkirche.church.tools/images/5123/abc?v=2')
        );
    }

    /**
     * Bilder aus der Zeit vor der Umstellung stehen mit der Adresse des
     * Vorschaubilds in der Mediathek. Die neue Adresse weicht davon ab, und
     * genau das laesst syncImages() sie einmal neu holen.
     */
    public function testImagesImportedAsThumbnailsAreFetchedAgain(): void
    {
        $group = GroupSync::normalizeGroup($this->group(44, 'Seniorenarbeit'), self::BASE);
        $wanted = GroupSync::wantedImages([9 => ['groups' => [$group]]]);

        $this->assertNotSame('https://musterkirche.church.tools/images/5123/abc', $wanted[44]);
    }

    /** Offene Anfragen belegen einen Platz - eine Annahme, die zur vorsichtigen Seite irrt. */
    public function testFreePlacesCountOpenRequestsAndNeverGoNegative(): void
    {
        $withRequest = GroupSync::normalizeGroup($this->group(1, 'A', [
            'maxMemberCount' => 12,
            'currentMemberCount' => 9,
            'requestedSeatsCount' => 1,
        ]), self::BASE);
        $overbooked = GroupSync::normalizeGroup($this->group(2, 'B', [
            'maxMemberCount' => 5,
            'currentMemberCount' => 7,
        ]), self::BASE);

        $this->assertSame(2, $withRequest['free_places']);
        $this->assertSame(0, $overbooked['free_places']);
    }

    /** Ohne Hoechstzahl gibt es keine Platzangabe, auch kein „0 frei". */
    public function testNoMaximumMeansNoPlaces(): void
    {
        $group = GroupSync::normalizeGroup($this->group(83, 'Beamer', ['maxMemberCount' => null]), self::BASE);

        $this->assertNull($group['max_members']);
        $this->assertNull($group['free_places']);
    }

    /**
     * Wo die Homepage keine Gruppenbilder zeigt, laesst ChurchTools `imageUrl`
     * ganz weg; `weekday` ist dort, wo nichts gepflegt ist, `null`.
     */
    public function testNormalizeGroupToleratesMissingOptionalFields(): void
    {
        $group = GroupSync::normalizeGroup([
            'id' => 253,
            'name' => 'Interessengruppen',
            'information' => ['weekday' => null, 'meetingTime' => null, 'note' => null],
        ], self::BASE);

        $this->assertSame('', $group['image_url']);
        $this->assertSame('', $group['weekday']);
        $this->assertSame('', $group['meeting_time']);
        $this->assertSame('', $group['note']);
        $this->assertSame('', $group['target_group']);
    }

    /**
     * Mit Key traegt eine Gruppe laut Spec Angaben ueber den API-Benutzer
     * (`signUpPersons`: Ehepartner, Kinder, gleiche E-Mail-Adresse) und ihre
     * Leiter als Personenobjekte. Nichts davon darf in die gespeicherte Kopie -
     * und damit auf die Website.
     */
    public function testNormalizeGroupKeepsNoPersonalData(): void
    {
        $person = ['title' => 'Erika Mustermann', 'domainIdentifier' => '4711', 'imageUrl' => 'https://x/p.jpg', 'infos' => ['erika@example.org']];
        $raw = $this->group(44, 'Seniorenarbeit', [
            'signUpPersons' => [['person' => $person, 'status' => 'IN_GROUP']],
            'canSignUp' => false,
            'signUpConditions' => ['groupIsPublic' => true],
        ]);
        $raw['information']['leader'] = [$person];

        $group = GroupSync::normalizeGroup($raw, self::BASE);

        $this->assertSame(
            ['id', 'name', 'note', 'image_url', 'weekday', 'weekday_sort', 'meeting_time', 'target_group', 'target_group_key', 'target_group_sort', 'category', 'category_sort', 'max_members', 'free_places', 'waitinglist', 'url'],
            array_keys($group)
        );
        $this->assertStringNotContainsString('Erika', (string) wp_json_encode($group));
        $this->assertStringNotContainsString('4711', (string) wp_json_encode($group));
    }

    /**
     * Gruppenfinder: Kategorien tragen `name` statt `nameTranslated` (selbst
     * angelegte Stammdaten), Sonntag hat die ID 0 und den sortKey 6.
     */
    public function testNormalizeGroupTakesCategoryAndSortKeysForTheFinder(): void
    {
        $raw = $this->group(44, 'Lobpreis');
        $raw['information']['groupCategory'] = ['id' => 2, 'name' => 'Musik', 'color' => 'blue', 'sortKey' => 5];
        $raw['information']['weekday'] = ['id' => 0, 'name' => 'sunday', 'nameTranslated' => 'Sonntag', 'sortKey' => 6];

        $group = GroupSync::normalizeGroup($raw, self::BASE);

        $this->assertSame('Musik', $group['category']);
        $this->assertSame(5, $group['category_sort']);
        $this->assertSame(6, $group['weekday_sort']);
    }

    /** Nur Filter, die die Homepage in ChurchTools einschaltet. */
    public function testShownFiltersFollowTheHomepage(): void
    {
        $this->assertSame(['weekday', 'groupcategory'], GroupSync::shownFilters(['filters' => [
            ['type' => 'weekday', 'show' => true, 'options' => []],
            ['type' => 'targetgroups', 'show' => false, 'options' => []],
            ['type' => 'groupcategory', 'show' => true, 'options' => []],
            ['show' => true],
        ]]));
        $this->assertSame([], GroupSync::shownFilters([]));
    }

    public function testNormalizeGroupSkipsGroupsWithoutIdOrName(): void
    {
        $this->assertNull(GroupSync::normalizeGroup(['id' => 5, 'name' => '  '], self::BASE));
        $this->assertNull(GroupSync::normalizeGroup(['name' => 'Ohne ID'], self::BASE));
    }

    public function testANonEmptyAnswerReplacesTheStoredGroups(): void
    {
        $next = GroupSync::nextHomepageData(
            ['fetched' => '2026-09-01 00:00:00', 'empty_runs' => 2, 'groups' => [['id' => 1]]],
            [['id' => 2]],
            '2026-09-14 12:00:00'
        );

        $this->assertSame(['fetched' => '2026-09-14 12:00:00', 'empty_runs' => 0, 'groups' => [['id' => 2]]], $next);
    }

    /**
     * Eine Homepage, die gestern Gruppen hatte und heute keine, sieht aus wie
     * eine voruebergehend entzogene Freigabe. Erst der dritte leere Lauf in
     * Folge gilt.
     */
    public function testAnEmptyAnswerClearsStoredGroupsOnlyOnTheThirdRunInARow(): void
    {
        $stored = ['fetched' => '2026-09-01 00:00:00', 'empty_runs' => 0, 'groups' => [['id' => 1]]];

        $first = GroupSync::nextHomepageData($stored, [], '2026-09-12 12:00:00');
        $second = GroupSync::nextHomepageData($first, [], '2026-09-13 12:00:00');
        $third = GroupSync::nextHomepageData($second, [], '2026-09-14 12:00:00');

        $this->assertSame([['id' => 1]], $first['groups']);
        $this->assertSame('2026-09-01 00:00:00', $first['fetched'], 'Solange der Bestand gilt, bleibt sein Zeitstempel.');
        $this->assertSame([['id' => 1]], $second['groups']);
        $this->assertSame([], $third['groups']);
        $this->assertSame(0, $third['empty_runs']);
    }

    /** Ohne Bestand ist eine leere Homepage einfach leer - fuenf von elf sind es. */
    public function testAnEmptyAnswerWithoutStoredGroupsIsTakenAsIs(): void
    {
        $this->assertSame(
            ['fetched' => '2026-09-14 12:00:00', 'empty_runs' => 0, 'groups' => []],
            GroupSync::nextHomepageData(null, [], '2026-09-14 12:00:00')
        );
    }

    /**
     * Dieselbe Gruppe auf zwei Homepages, auf einer davon mit abgeschalteten
     * Gruppenbildern: Sie braucht trotzdem genau ein Bild.
     */
    public function testWantedImagesTakesTheImageFromWhicheverHomepageShowsIt(): void
    {
        $wanted = GroupSync::wantedImages([
            1 => ['groups' => [['id' => 269, 'image_url' => '']]],
            9 => ['groups' => [['id' => 269, 'image_url' => 'https://x/269'], ['id' => 514, 'image_url' => '']]],
        ]);

        $this->assertSame([269 => 'https://x/269'], $wanted);
    }

    /**
     * Unter dem Merker der Terminbilder haelte EventRepository::orphanedAttachmentIds()
     * jedes Gruppenbild fuer verwaist - der naechste Termin-Sync loeschte es.
     */
    public function testGroupImagesUseTheirOwnSourceMetaKey(): void
    {
        $this->assertNotSame('_ctp_source_image_url', GroupSync::IMAGE_META_KEY);
    }

    public function testRunStoresTheGroupsOfEnabledHomepagesAndAsksWithTheKey(): void
    {
        $this->configure([
            9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            6 => ['name' => 'Mitarbeit', 'hash' => 'XyZ789', 'enabled' => false],
        ]);
        ctp_test_queue_http([
            $this->listEntry('9', 'Kleingruppen', 'AbC123'),
            $this->listEntry('6', 'Mitarbeit', 'XyZ789'),
        ]);
        ctp_test_queue_http(['id' => 9, 'groups' => [$this->group(514, 'Offener Hauskreis', [], false)]]);

        GroupSync::run();

        $calls = ctp_test_http_calls();
        $this->assertCount(2, $calls, 'Die abgewaehlte Homepage wird nicht abgefragt.');
        $this->assertSame(self::BASE . '/api/grouphomepages/AbC123', $calls[1]['url']);

        foreach ($calls as $call) {
            $this->assertSame('Login gruppen-token', $call['args']['headers']['Authorization']);
        }

        $this->assertSame([9], array_keys(GroupSync::storedData()));
        $this->assertSame('Offener Hauskreis', GroupSync::groupsFor(9)[0]['name']);
        $this->assertNull(GroupSync::getLastError());
        $this->assertSame('2026-09-14 12:00:00', get_option(GroupSync::LAST_SYNC_OPTION));
    }

    /** Ein ausgefallener Abruf nimmt der Website nicht die Gruppen. */
    public function testAFailedHomepageKeepsItsStoredGroupsAndRecordsTheError(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [
            9 => ['fetched' => '2026-09-13 12:00:00', 'empty_runs' => 0, 'groups' => [['id' => 1, 'name' => 'Bestand']]],
        ]);
        ctp_test_queue_http([$this->listEntry('9', 'Kleingruppen', 'AbC123')]);
        ctp_test_queue_raw_http('Service Unavailable', 503);

        GroupSync::run();

        $this->assertSame('Bestand', GroupSync::groupsFor(9)[0]['name']);
        $this->assertStringContainsString('Kleingruppen', GroupSync::getLastError()['message']);
        $this->assertFalse(get_option(GroupSync::LAST_SYNC_OPTION));
    }

    /**
     * Wer eine Homepage abwaehlt, soll ihre Bilder nicht in der Mediathek
     * behalten - sofern keine andere angehakte Homepage dieselbe Gruppe zeigt.
     */
    public function testImagesOfGroupsThatAreGoneAreDeleted(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => false]]);
        ctp_test_set_option(GroupSync::IMAGES_OPTION, [44 => 301]);
        ctp_test_queue_http([$this->listEntry('9', 'Kleingruppen', 'AbC123')]);

        GroupSync::run();

        $this->assertSame([301], ctp_test_deleted_attachments());
        $this->assertSame([], GroupSync::imageMap());
        $this->assertSame([], GroupSync::storedData());
    }

    /** Ein unveraendertes Bild wird nicht neu geholt (kein Download, kein Loeschen). */
    public function testAnUnchangedImageIsKept(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true]]);
        ctp_test_set_option(GroupSync::IMAGES_OPTION, [44 => 301]);
        ctp_test_set_post_meta(301, GroupSync::IMAGE_META_KEY, 'https://musterkirche.church.tools/images/5123/abc?w=1600&h=1600&fit=max');
        ctp_test_queue_http([$this->listEntry('9', 'Kleingruppen', 'AbC123')]);
        ctp_test_queue_http(['groups' => [$this->group(44, 'Seniorenarbeit')]]);

        GroupSync::run();

        $this->assertSame([44 => 301], GroupSync::imageMap());
        $this->assertSame([], ctp_test_deleted_attachments());
    }

    /**
     * Eine leere Homepage-Liste bei nicht leerem Bestand loeschte sonst jeden
     * Haken - dieselbe Luecke wie bei der Raumliste in 1.20.1.
     */
    public function testAnEmptyHomepageListKeepsTheStoredSelection(): void
    {
        $homepages = [9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true]];
        $this->configure($homepages);
        ctp_test_queue_http([]);
        ctp_test_queue_http(['groups' => []]);

        GroupSync::run();

        $this->assertSame($homepages, GroupSettings::get()['homepages']);
        $this->assertNotNull(GroupSync::getLastError());
    }

    /** Ohne Key fragt der Gruppen-Sync nicht - und sagt das, sobald es etwas abzugleichen gaebe. */
    public function testWithoutAKeyNothingIsAskedAndTheErrorSaysWhy(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true]], '');

        GroupSync::run();

        $this->assertSame([], ctp_test_http_calls());
        $this->assertStringContainsString('API-Key', GroupSync::getLastError()['message']);
    }

    /** Eine Installation ohne angehakte Homepage bekommt keine Gruppen-Meldung. */
    public function testWithoutAKeyAndWithoutHomepagesItStaysQuiet(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => false]], '');

        GroupSync::run();

        $this->assertNull(GroupSync::getLastError());
    }

    /** Haelt ein anderer Lauf die Sperre, tut dieser nichts und meldet es. */
    public function testASecondRunWhileTheFirstHoldsTheLockDoesNothing(): void
    {
        $this->configure([9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true]]);
        $token = RunLock::acquire(GroupSync::LOCK);

        $this->assertFalse(GroupSync::run());
        $this->assertSame([], ctp_test_http_calls());

        RunLock::release(GroupSync::LOCK, (string) $token);
    }

    /**
     * Waehlbar ist nur, was eine *angehakte* Homepage zeigt - eine noch
     * gespeicherte, abgewaehlte zaehlt nicht. Doppelte Gruppen einmal, und
     * dann mit Bild, wenn eine der Homepages eins zeigt.
     */
    public function testSelectableGroupsComeFromEnabledHomepagesOnlyAndPreferTheImage(): void
    {
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
            1 => ['name' => 'Ohne Bilder', 'hash' => 'a1', 'enabled' => true],
            2 => ['name' => 'Mit Bildern', 'hash' => 'b2', 'enabled' => true],
            3 => ['name' => 'Abgewaehlt', 'hash' => 'c3', 'enabled' => false],
        ]]);
        ctp_test_set_option(GroupSync::DATA_OPTION, [
            1 => ['groups' => [['id' => 269, 'name' => 'Hauskreis', 'image_url' => '']]],
            2 => ['groups' => [['id' => 269, 'name' => 'Hauskreis', 'image_url' => 'https://x/269'], ['id' => 514, 'name' => 'Chor', 'image_url' => '']]],
            3 => ['groups' => [['id' => 83, 'name' => 'Nicht mehr oeffentlich', 'image_url' => '']]],
        ]);

        $groups = GroupSync::selectableGroups();

        $this->assertSame([269, 514], array_keys($groups));
        $this->assertSame('https://x/269', $groups[269]['image_url']);
        $this->assertSame([514, 269], array_column(GroupSync::groupsByIds([514, 83, 269, 514]), 'id'), 'Reihenfolge wie angegeben, Unbekanntes und Doppeltes faellt heraus.');
    }

    public function testParseIdsToleratesHandTypedInput(): void
    {
        $this->assertSame([514, 269], GroupSync::parseIds(' 514, 269,abc,,-3 514 '));
        $this->assertSame([], GroupSync::parseIds(''));
    }

    private function configure(array $homepages, string $apiKey = 'gruppen-token'): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt($apiKey)]);
        ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => $homepages, 'sync_interval' => 'daily']);
    }

    private function listEntry(string $id, string $title, string $hash): array
    {
        return [
            'title' => $title,
            'domainType' => 'grouphomepage',
            'domainIdentifier' => $id,
            'apiUrl' => self::BASE . '/api/grouphomepages/' . $hash,
            'frontendUrl' => self::BASE . '/grouphomepage/' . $hash,
        ];
    }

    private function group(int $id, string $name, array $overrides = [], bool $withImage = true): array
    {
        $information = [
            'meetingTime' => '9:30',
            'weekday' => ['id' => 3, 'name' => 'wednesday', 'nameTranslated' => 'Mittwoch', 'sortKey' => 2],
            'note' => 'Treff am Mittwoch',
            'targetGroup' => ['id' => 1, 'name' => 'everyone', 'nameTranslated' => 'Jeder', 'sortKey' => 1],
            'groupPlaces' => [],
        ];

        if ($withImage) {
            $information['imageUrl'] = self::BASE . '/images/5123/abc';
        }

        return array_merge([
            'id' => $id,
            'name' => $name,
            'information' => $information,
            'allowWaitinglist' => false,
            'maxMemberCount' => null,
            'currentMemberCount' => 0,
            'requestedSeatsCount' => 0,
            'canSignUp' => true,
        ], $overrides);
    }
}
