<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Update;

use ChurchToolsPlugin\Admin\SettingsPage;
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

        // The repo is public (see plan.md's "Rahmendaten" — made public on
        // 2026-08-18 precisely so update checks work without one), so the token is
        // optional here:
        // it only raises GitHub's unauthenticated rate limit of 60 requests per hour
        // per IP, which two update checks a day never come near. It stays supported
        // for anyone distributing from a private fork — there a missing token means
        // the library can't reach the Releases API at all and fails the check
        // quietly, reporting the plugin as up to date.
        $token = SettingsPage::getDecryptedGitHubToken();
        if ($token !== '') {
            $updateChecker->setAuthentication($token);
        }

        $updateChecker->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');
    }
}
