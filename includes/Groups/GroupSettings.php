<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Groups;

/**
 * Die Einstellungen des Reiters „Gruppen": welche Gruppen-Homepages
 * uebernommen werden und wie oft.
 *
 * Eine eigene Option statt zweier weiterer Schluessel in `ctp_settings`, aus
 * zwei Gruenden. SettingsPage::sanitizeSettings() gibt eine feste
 * Schluesselliste zurueck, jeder neue Schluessel muesste dort nachgetragen
 * werden, sonst verschwindet er beim naechsten Speichern irgendeines anderen
 * Reiters still. Und die Gruppen haben einen eigenen Takt: Installer haengt an
 * `update_option_ctp_group_settings`, ohne bei jedem Speichern des Design-Tabs
 * nachsehen zu muessen, ob sich hier etwas geaendert hat.
 */
final class GroupSettings
{
    public const OPTION_KEY = 'ctp_group_settings';

    /** Die Optionsgruppe fuer settings_fields() im Formular des Reiters. */
    public const OPTION_GROUP = 'churchtools-plugin-groups';

    /**
     * Seltener als bei den Terminen, und mit „Woechentlich" als viertem Wert
     * (Nutzerwunsch 2026-09-14: „stuendlich ist fuer unseren Anwendungsfall
     * aktuell zu haeufig"). Eine Gruppe aendert Treffpunkt oder Beschreibung
     * selten; was sich oefter bewegt, sind die freien Plaetze, und die zeigt
     * die Anmeldung in ChurchTools ohnehin mit dem echten Stand an.
     *
     * `weekly` bringt WordPress seit 5.4 selbst mit, das Plugin verlangt 6.4.
     */
    public const INTERVALS = ['hourly', 'twicedaily', 'daily', 'weekly'];

    public const DEFAULT_INTERVAL = 'daily';

    public static function defaults(): array
    {
        return [
            /**
             * Nach ChurchTools-ID der Homepage:
             * [ 'name' => string, 'hash' => string, 'enabled' => bool ]
             */
            'homepages' => [],
            'sync_interval' => self::DEFAULT_INTERVAL,
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args(is_array($stored) ? $stored : [], self::defaults());

        // In der Option kann etwas anderes liegen als das, was dieses Plugin
        // schreibt - ein teilweise eingespieltes Backup, ein fremdes Skript.
        // Am 2026-09-14 in der Testumgebung selbst erzeugt: ein update_option()
        // ohne registrierten Sanitizer legte Eintraege ohne Name und Hash ab,
        // und der Reiter haette jeden davon mit einer PHP-Warnung gezeigt.
        $homepages = [];

        foreach (is_array($settings['homepages']) ? $settings['homepages'] : [] as $id => $homepage) {
            if ((int) $id <= 0 || !is_array($homepage) || !is_string($homepage['hash'] ?? null)) {
                continue;
            }

            $homepages[(int) $id] = [
                'name' => is_string($homepage['name'] ?? null) ? $homepage['name'] : '',
                'hash' => $homepage['hash'],
                'enabled' => !empty($homepage['enabled']),
            ];
        }

        $settings['homepages'] = $homepages;

        if (!in_array($settings['sync_interval'], self::INTERVALS, true)) {
            $settings['sync_interval'] = self::DEFAULT_INTERVAL;
        }

        return $settings;
    }

    /**
     * Wie SettingsPage::sanitizeCalendars(): Aus dem Formular kommt nur der
     * Haken. Name und Hash stehen nicht im Formular und werden nie von dort
     * uebernommen - der Hash landet im Pfad eines API-Aufrufs, und was dort
     * steht, soll nur aus einer ChurchTools-Antwort stammen koennen. Eine ID,
     * die nicht in der gespeicherten Liste steht, faellt heraus.
     *
     * Ein fehlender Schluessel heisst „nicht abgeschickt", nicht „leeren" -
     * dieselbe Regel wie in sanitizeSettings(). Wichtig beim ersten Speichern:
     * Da laeuft dieser Sanitizer zweimal, der zweite Durchlauf sieht die
     * Ausgabe des ersten. `enabled` ist dann schon ein bool und bleibt es.
     */
    public static function sanitize(?array $input): array
    {
        $input ??= [];
        $existing = self::get();

        $interval = $existing['sync_interval'];
        if (array_key_exists('sync_interval', $input) && in_array($input['sync_interval'], self::INTERVALS, true)) {
            $interval = $input['sync_interval'];
        }

        $homepages = $existing['homepages'];
        if (array_key_exists('homepages', $input) && is_array($input['homepages'])) {
            foreach ($homepages as $id => $homepage) {
                if (!array_key_exists($id, $input['homepages'])) {
                    continue;
                }

                $homepages[$id]['enabled'] = !empty($input['homepages'][$id]['enabled']);
            }
        }

        return [
            'homepages' => $homepages,
            'sync_interval' => $interval,
        ];
    }

    /**
     * Schreibt eine frisch aus ChurchTools gebaute Liste am Sanitizer vorbei -
     * aus demselben Grund wie CalendarList::refresh(): Der Sanitizer
     * haengt an jedem update_option() dieser Option und liesse beim ersten
     * Abruf keine einzige Homepage durch, weil noch keine „bekannt" ist.
     */
    public static function saveHomepages(array $homepages): void
    {
        $settings = self::get();
        $settings['homepages'] = $homepages;

        remove_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitize']);
        update_option(self::OPTION_KEY, $settings);
        add_filter('sanitize_option_' . self::OPTION_KEY, [self::class, 'sanitize']);
    }

    /**
     * @return array<int, array{name: string, hash: string, enabled: bool}>
     */
    public static function enabledHomepages(?array $settings = null): array
    {
        $settings ??= self::get();

        return array_filter(
            $settings['homepages'],
            static fn ($homepage): bool => is_array($homepage) && !empty($homepage['enabled'])
        );
    }

    /**
     * Die Homepage zu einer Angabe aus Shortcode, Block oder WPBakery - als ID
     * oder als Name, wie beim Attribut `calendar` der Termine. Gesucht wird nur
     * unter den angehakten Homepages: Eine abgewaehlte hat keine Daten mehr,
     * und ein Shortcode soll nicht die Hintertuer zu ihr sein.
     *
     * Ohne Angabe gilt die einzige angehakte Homepage, falls es genau eine
     * gibt. Bei mehreren waere jede Wahl geraten.
     */
    public static function resolveHomepageId(string $ref, ?array $settings = null): ?int
    {
        $enabled = self::enabledHomepages($settings);
        $ref = trim($ref);

        if ($ref === '') {
            return count($enabled) === 1 ? (int) array_key_first($enabled) : null;
        }

        // Erst als ID, dann als Name - nicht entweder oder: Eine Homepage darf
        // „2026" heissen, und die Zahl allein saehe sonst nur nach einer ID.
        if (ctype_digit($ref) && array_key_exists((int) $ref, $enabled)) {
            return (int) $ref;
        }

        foreach ($enabled as $id => $homepage) {
            if (mb_strtolower(trim((string) $homepage['name'])) === mb_strtolower($ref)) {
                return (int) $id;
            }
        }

        return null;
    }
}
