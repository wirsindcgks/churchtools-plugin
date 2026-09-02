<?php

/**
 * Plugin Name:       ChurchTools Events
 * Plugin URI:        https://github.com/wirsindcgks/churchtools-plugin
 * Description:       Synchronisiert Kalender-Events aus der ChurchTools API, speichert sie lokal und zeigt sie per Shortcode, Gutenberg-Block oder WPBakery-Element an.
 * Version:           1.15.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            wirsindcgks
 * Author URI:        https://github.com/wirsindcgks
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       churchtools-plugin
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace ChurchToolsPlugin;

if (!defined('ABSPATH')) {
    exit;
}

// Must stay in lockstep with the "Version:" header above, the readme.txt
// "Stable tag" and the topmost CHANGELOG.md release — this constant is what
// cache-busts assets/css/*.css and assets/js/*.js on update and what the
// Übersicht tab reports as the installed version. Drifting apart once meant
// browsers kept serving a stale stylesheet after an update (it happened: the
// constant sat at 0.2.0 while the header already read 0.5.0), so
// tests/VersionConsistencyTest.php now asserts all four agree.
define('CTP_VERSION', '1.15.0');
define('CTP_PLUGIN_FILE', __FILE__);
define('CTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CTP_PLUGIN_URL', plugin_dir_url(__FILE__));

(static function (): void {
    $autoload = CTP_PLUGIN_DIR . 'vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
})();

register_activation_hook(__FILE__, [Db\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [Db\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->init();
});
