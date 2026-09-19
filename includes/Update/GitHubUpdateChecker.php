<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Update;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * The plugin isn't on WordPress.org (see plan.md's "Infrastruktur" ToDo — GitHub is
 * the chosen distribution channel), so WP's built-in update mechanism has nothing to
 * check against on its own. This wires the third-party plugin-update-checker library
 * to a small metadata file in the repo that points at the release package: neither
 * vendor/ (Composer) nor blocks/event-list/build/ (the compiled Gutenberg script) are
 * committed, so a plain source archive of a tag wouldn't actually run. What gets
 * distributed instead is built by .github/workflows/release.yml on every vX.Y.Z tag
 * and attached as a release asset.
 *
 * Vorher fragte diese Klasse die GitHub-API (Releases, Tags, Branches). Nicht
 * angemeldet erlaubt die 60 Anfragen pro Stunde und IP - und auf geteiltem Hosting
 * ist das nicht die IP dieser einen Seite, sondern die aller Seiten auf dem Server.
 * Auf der Live-Seite beantwortete GitHub am 19.08.2026 jede Update-Pruefung mit HTTP 429,
 * quer ueber alle drei Endpunkte; das Backend konnte nur noch melden, dass es nichts
 * ueber Updates sagen kann. raw.githubusercontent.com liefert Dateien ueber ein CDN
 * aus und kennt dieses Limit nicht: eine Datei, eine Anfrage, kein Zugangstoken.
 *
 * update.json entsteht mit bin/make-update-json.php aus Plugin-Header und
 * CHANGELOG.md und wird mit dem Release-Commit abgeschickt (die Adresse des Assets
 * ergibt sich aus der Version). tests/Release/VersionConsistencyTest.php und ein
 * Schritt im Release-Workflow halten sie an der ausgelieferten Version fest.
 */
final class GitHubUpdateChecker
{
    /**
     * Der Zweig, aus dem die Metadatendatei gelesen wird, ist der
     * Standardzweig dieses Repos - das Repo selbst steht auch in
     * SettingsPage::REPO_URL, wo der Tab „Updates“ dieselbe Quelle verlinkt.
     * Wer das Plugin aus einem Fork verteilt, aendert beide.
     */
    private const METADATA_URL = 'https://raw.githubusercontent.com/wirsindcgks/churchtools-plugin/main/update.json';

    /**
     * Der Name, unter dem WordPress dieses Plugin in Update- und
     * Infoabfragen fuehrt.
     */
    private const SLUG = 'churchtools-plugin';

    /**
     * Der Pruefer der Bibliothek, damit „Nach Updates suchen“ ihn direkt
     * fragen kann (siehe checkNow()).
     *
     * Bewusst ohne Typ: Die Klasse liegt in einem versionierten Namensraum der
     * Bibliothek (v5p7 heute, morgen eine andere), und das Alias v5 gibt es
     * nur fuer die Fabrik.
     */
    private static ?object $checker = null;

    public static function register(): void
    {
        // Guards against a raw `git clone` without `composer install` (vendor/ isn't
        // committed, see class docblock) — degrades to "no update checking" instead
        // of fataling every request, since this runs unconditionally on
        // plugins_loaded rather than only within the admin's own settings screen.
        if (!class_exists(PucFactory::class)) {
            return;
        }

        // Kein VCS-Prueferzweig mehr: raw.githubusercontent.com steht nicht in
        // der Hostliste der Bibliothek (PucFactory::getVcsService()), sie baut
        // fuer diese Adresse also den reinen JSON-Metadaten-Pruefer - genau
        // den, der hier gebraucht wird.
        self::$checker = PucFactory::buildUpdateChecker(self::METADATA_URL, CTP_PLUGIN_FILE, self::SLUG);

        // Die Bibliothek haengt ihr Ergebnis mit Standardprioritaet ein, diese
        // Nachbesserung muss danach laufen.
        add_filter('site_transient_update_plugins', [self::class, 'normalizeTested'], 20);
        add_filter('plugins_api', [self::class, 'normalizeTestedInInfo'], 20);
    }

    /**
     * Zieht `tested` auf die laufende WordPress-Version hoch, solange beide im
     * selben Zweig liegen.
     *
     * Der Satz „Kompatibilitaet mit WordPress X: Ja (laut Autor)" auf Dashboard
     * -> Aktualisierungen entsteht aus genau diesem Feld des angebotenen
     * Updates (wp-admin/update-core.php). Fehlt es, steht dort „Nicht
     * getestet" - neben lauter Plugins von wordpress.org, die „Ja" melden, und
     * das liest sich wie eine Warnung. update.json traegt das Feld deshalb aus
     * der readme.txt („Tested up to"), die Bibliothek reicht es durch.
     *
     * Allein reicht das aber nicht: Verglichen wird mit
     * version_compare($tested, $laufende_version, '>='), und die laufende
     * Version hat drei Stellen - „7.1" gilt auf 7.1.1 als *nicht* getestet.
     * api.wordpress.org umgeht das, indem es das Feld auf die aktuelle
     * Punktversion hochzieht; Akismet und Yoast melden beide 7.1.1, obwohl ihre
     * readme.txt nur den Zweig nennt. Eine feste Datei im Repo kann das nicht,
     * also passiert es hier beim Einhaengen.
     *
     * @param mixed $updates Die Liste verfuegbarer Updates - vor dem ersten
     *                       Abruf false, nicht zwingend ein Objekt.
     * @return mixed Dieselbe Liste.
     */
    public static function normalizeTested($updates)
    {
        $file = plugin_basename(CTP_PLUGIN_FILE);

        if (!is_object($updates) || !isset($updates->response[$file]->tested)) {
            return $updates;
        }

        $updates->response[$file]->tested = self::testedInRunningBranch(
            (string) $updates->response[$file]->tested,
            (string) get_bloginfo('version')
        );

        return $updates;
    }

    /**
     * Dasselbe fuer das Fenster hinter „Details anzeigen".
     *
     * Dort haengt am selben Feld nicht die Kompatibilitaetszeile, sondern eine
     * gelbe Warnung („Dieses Plugin wurde nicht mit deiner aktuellen Version
     * von WordPress getestet", wp-admin/includes/plugin-install.php) - und die
     * steht genau in dem Fenster, das man vor dem Update oeffnet. Ohne diese
     * zweite Stelle waere die Kompatibilitaetszeile gruen und die Warnung
     * daneben trotzdem da.
     *
     * @param mixed $result Die Antwort der Info-Abfrage - false, solange sie
     *                      niemand beantwortet hat.
     * @return mixed Dieselbe Antwort.
     */
    public static function normalizeTestedInInfo($result)
    {
        if (!is_object($result) || ($result->slug ?? '') !== self::SLUG || !isset($result->tested)) {
            return $result;
        }

        $result->tested = self::testedInRunningBranch(
            (string) $result->tested,
            (string) get_bloginfo('version')
        );

        return $result;
    }

    /**
     * Die Regel dahinter, ohne WordPress: „7.1" wird auf 7.1.1 zu „7.1.1",
     * bleibt auf 7.2 aber „7.1" - getestet ist getestet, und was nicht
     * getestet ist, soll auch nicht so aussehen.
     *
     * @param string $tested  Was die readme.txt behauptet.
     * @param string $running Die laufende WordPress-Version, samt Zusaetzen wie
     *                        „-RC1", die hier nichts zu suchen haben.
     * @return string Der Wert, den WordPress vergleichen soll.
     */
    public static function testedInRunningBranch(string $tested, string $running): string
    {
        $running = (string) preg_replace('/-.*$/', '', $running);

        if ($tested === '' || $running === '') {
            return $tested;
        }

        // Der Punkt am Ende beider Seiten verhindert, dass „7.1" auch auf
        // 7.10.1 passt.
        return str_starts_with($running . '.', $tested . '.') ? $running : $tested;
    }

    /**
     * Fragt genau diese eine Quelle ab, fuer den Knopf „Nach Updates suchen“.
     *
     * Vorher stand dort delete_site_transient('update_plugins') plus
     * wp_update_plugins() - und das ist etwas ganz anderes, als es aussieht:
     * WordPress fragt damit api.wordpress.org nach *allen* installierten
     * Plugins und wartet auf die Antwort. Auf einer Seite mit vielen Plugins
     * und einem Server unter Last drehte der Knopf deshalb endlos (auf
     * der Live-Seite am 20.08.2026 so erlebt), obwohl die eine Datei, um die es
     * geht, in Bruchteilen einer Sekunde da ist.
     *
     * Der Zwischenspeicher von WordPress wird dabei nicht mehr geleert: Die
     * Bibliothek haengt ihr Ergebnis ohnehin bei jedem Lesen in die Liste der
     * verfuegbaren Updates ein, die Plugin-Seite zeigt es also genauso.
     *
     * @return array{version: string|null, checked: int}|null null, wenn die
     *         Bibliothek gar nicht geladen ist (siehe register()).
     */
    public static function checkNow(): ?array
    {
        if (self::$checker === null) {
            return null;
        }

        self::$checker->checkForUpdates();

        $state = self::$checker->getUpdateState();
        $update = $state->getUpdate();

        return [
            'version' => isset($update->version) ? (string) $update->version : null,
            'checked' => (int) $state->getLastCheck(),
        ];
    }
}
