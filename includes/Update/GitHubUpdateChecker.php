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
        self::$checker = PucFactory::buildUpdateChecker(self::METADATA_URL, CTP_PLUGIN_FILE, 'churchtools-plugin');
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
