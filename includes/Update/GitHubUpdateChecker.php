<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Update;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * The plugin isn't on WordPress.org (see plan.md's "Infrastruktur" ToDo — GitHub is
 * the chosen distribution channel), so WP's built-in update mechanism has nothing to
 * check against on its own. This wires the third-party plugin-update-checker library
 * to the GitHub Releases of this repo instead, using release *assets* rather than
 * GitHub's raw source zipball: neither vendor/ (Composer) nor
 * blocks/event-list/build/ (the compiled Gutenberg block) are committed to the repo,
 * so a plain source archive of a tag wouldn't actually run. See
 * .github/workflows/release.yml, which builds both and uploads a working zip as a
 * release asset whenever a vX.Y.Z tag is pushed.
 */
final class GitHubUpdateChecker
{
    /**
     * Steht auch in SettingsPage::REPO_URL - dort verlinkt der Tab „Updates“
     * dieselbe Quelle, aus der hier die Releases geholt werden. Wer das
     * Plugin aus einem Fork verteilt, aendert beide.
     */
    private const REPO_URL = 'https://github.com/wirsindcgks/churchtools-plugin/';

    public static function register(): void
    {
        // Guards against a raw `git clone` without `composer install` (vendor/ isn't
        // committed, see class docblock) — degrades to "no update checking" instead
        // of fataling every request, since this runs unconditionally on
        // plugins_loaded rather than only within the admin's own settings screen.
        if (!class_exists(PucFactory::class)) {
            return;
        }

        $updateChecker = PucFactory::buildUpdateChecker(self::REPO_URL, CTP_PLUGIN_FILE, 'churchtools-plugin');

        // Kein Zugangstoken mehr. Das Repository ist seit dem 2026-08-18
        // oeffentlich und bleibt es (siehe plan.md, „Rahmendaten“) - damit
        // greift GitHubs Rate-Limit fuer nicht angemeldete Anfragen, 60 pro
        // Stunde und IP, und zwei Update-Pruefungen am Tag kommen dem nie
        // nahe. Ein Eingabefeld dafuer gab es trotzdem, und es kostete mehr,
        // als es einbrachte: ein verschluesselt gespeichertes Geheimnis, das
        // niemand braucht, plus eine Erklaerung im Backend, warum man es nicht
        // ausfuellen muss. Wer aus einem privaten Fork verteilt, aendert
        // REPO_URL hier ohnehin und kann an derselben Stelle
        // setAuthentication() ergaenzen.
        $updateChecker->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');
    }
}
