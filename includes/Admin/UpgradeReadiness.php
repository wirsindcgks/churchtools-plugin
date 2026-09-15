<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Security\Crypto;

/**
 * Ist diese Website bereit fuer 2.0.0? Die Ankuendigung in 1.29.0 sagt nicht
 * nur, *dass* sich etwas aendert, sondern prueft, was davon *hier* zu tun ist
 * (plan.md, „Weg zu 2.0.0").
 *
 * Drei Arten von Befund:
 * - `ok`: nichts zu tun.
 * - `action`: vor dem Update auf 2.0 zu erledigen, sonst geht etwas verloren
 *   oder das Plugin laeuft nicht.
 * - `hint`: kein Hindernis, aber nach dem Update zu pruefen.
 */
final class UpgradeReadiness
{
    public const NEXT_MAJOR = '2.0.0';

    public const NEW_NAME = 'Connect ChurchTools';

    public const MIN_PHP = '8.3';

    public const MIN_WP = '6.6';

    /** Die Vorlagen, die ein Theme unter churchtools-plugin/ ueberschreiben kann. */
    public const OVERRIDABLE_TEMPLATES = [
        'event-list.php',
        'event-grid.php',
        'event-upcoming.php',
        'event-detail.php',
        'group-grid.php',
        'group-featured.php',
    ];

    /**
     * @param string|null  $phpVersion  Zum Testen; sonst PHP_VERSION.
     * @param string|null  $wpVersion   Zum Testen; sonst die laufende WordPress-Version.
     * @param list<string>|null $overrides Zum Testen; sonst die im Theme gefundenen Vorlagen.
     *
     * @return list<array{key: string, status: 'ok'|'action'|'hint', label: string, detail: string}>
     */
    public static function checks(?string $phpVersion = null, ?string $wpVersion = null, ?array $overrides = null): array
    {
        $phpVersion ??= PHP_VERSION;
        $wpVersion ??= (string) get_bloginfo('version');
        $overrides ??= self::themeOverrides();

        $checks = [];

        $phpOk = version_compare($phpVersion, self::MIN_PHP, '>=');
        $checks[] = [
            'key' => 'php',
            'status' => $phpOk ? 'ok' : 'action',
            /* translators: 1: required PHP version, 2: PHP version of this site */
            'label' => sprintf(__('PHP %1$s oder neuer (hier: %2$s)', 'churchtools-plugin'), self::MIN_PHP, $phpVersion),
            'detail' => $phpOk ? '' : __('Beim Hoster die PHP-Version anheben. Ohne das lässt sich 2.0 nicht aktivieren.', 'churchtools-plugin'),
        ];

        $wpOk = version_compare($wpVersion, self::MIN_WP, '>=');
        $checks[] = [
            'key' => 'wordpress',
            'status' => $wpOk ? 'ok' : 'action',
            /* translators: 1: required WordPress version, 2: WordPress version of this site */
            'label' => sprintf(__('WordPress %1$s oder neuer (hier: %2$s)', 'churchtools-plugin'), self::MIN_WP, $wpVersion),
            'detail' => $wpOk ? '' : __('WordPress aktualisieren.', 'churchtools-plugin'),
        ];

        // Seit 1.27.0 schreibt jedes Update einen Key der alten Verschluesselung
        // neu. Steht danach noch einer da, liess er sich nicht lesen - und 2.0
        // liest die alte Form gar nicht mehr.
        $legacyKey = !ApiKey::isFromConfig() && Crypto::isLegacy(ApiKey::storedCiphertext());
        $checks[] = [
            'key' => 'api_key',
            'status' => $legacyKey ? 'action' : 'ok',
            'label' => __('API-Key in der aktuellen Verschlüsselung gespeichert', 'churchtools-plugin'),
            'detail' => $legacyKey ? __('Den API-Key unter „Einstellungen → Verbindung“ neu eintragen und speichern.', 'churchtools-plugin') : '',
        ];

        // Die Bruecke aus 1.12.0: Wer damals „nur exklusiv gebuchte Raeume"
        // angekreuzt und den Reiter seitdem nie gespeichert hat, haengt noch
        // am alten Schluessel. 2.0 kennt ihn nicht mehr.
        $settings = get_option('ctp_settings', []);
        $legacyRooms = is_array($settings)
            && (string) ($settings['rooms_mode'] ?? '') === ''
            && array_key_exists('rooms_exclusive', $settings);
        $checks[] = [
            'key' => 'rooms_mode',
            'status' => $legacyRooms ? 'action' : 'ok',
            'label' => __('Räume-Einstellung im aktuellen Format', 'churchtools-plugin'),
            'detail' => $legacyRooms ? __('Unter „Events → Räume“ einmal speichern – die Auswahl bleibt, sie wird nur im neuen Format abgelegt.', 'churchtools-plugin') : '',
        ];

        $checks[] = [
            'key' => 'templates',
            'status' => $overrides === [] ? 'ok' : 'hint',
            'label' => __('Eigene Vorlagen im Theme', 'churchtools-plugin'),
            'detail' => $overrides === []
                ? ''
                : sprintf(
                    /* translators: %s: comma-separated list of template file names */
                    __('Das Theme überschreibt %s. Nach dem Update auf 2.0 mit den mitgelieferten Vorlagen vergleichen – ab 2.0 gilt für sie die Kompatibilitätszusage.', 'churchtools-plugin'),
                    implode(', ', $overrides)
                ),
        ];

        return $checks;
    }

    /** @param list<array{status: string}> $checks */
    public static function openActions(array $checks): int
    {
        return count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'action'));
    }

    /** @return list<string> */
    public static function themeOverrides(): array
    {
        $found = [];

        foreach (self::OVERRIDABLE_TEMPLATES as $file) {
            if (locate_template('churchtools-plugin/' . $file) !== '') {
                $found[] = $file;
            }
        }

        return $found;
    }
}
