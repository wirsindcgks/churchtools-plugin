<?php
/**
 * Plugin Name:       ChurchTools Events
 * Plugin URI:        https://github.com/tobiasnikola/churchtools-plugin
 * Description:       Synchronisiert Kalender-Events aus der ChurchTools API, speichert sie lokal und zeigt sie per Shortcode, Gutenberg-Block oder WPBakery-Element an.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Tobias Nikola
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

define('CTP_VERSION', '0.1.0');
define('CTP_PLUGIN_FILE', __FILE__);
define('CTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CTP_PLUGIN_URL', plugin_dir_url(__FILE__));

$ctpAutoload = CTP_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($ctpAutoload)) {
    require_once $ctpAutoload;
}
unset($ctpAutoload);

register_activation_hook(__FILE__, [Db\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [Db\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->init();
});
