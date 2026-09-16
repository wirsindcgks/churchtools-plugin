<?php

declare(strict_types=1);

namespace ChurchToolsPlugin;

use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Sync\SyncEngine;

/**
 * Ein Protokoll fuer das, was sonst still bleibt.
 *
 * Anlass (2026-09-15): ChurchTools beantwortete den Bilddownload zwei Wochen
 * lang mit 401, SyncEngine::importImage() gab dabei still `null` zurueck, und
 * der Lauf galt als gelungen - der Ausfall blieb unbemerkt, bis ihn ein
 * Nutzer meldete. Die Sofortmassnahme (ImageImportFailures) zeigt seitdem,
 * *was* gerade fehlt; sie sagt nicht, seit wann, wie oft, oder ob ein Lauf
 * ueberhaupt stattgefunden hat. Das ist die Luecke, die dieses Protokoll
 * schliesst - festgehalten als Pflicht fuer 2.0.0 (plan.md, „Weg zu 2.0.0").
 *
 * Drei Stufen, vier Bereiche, eine eigene Tabelle statt einer Option (siehe
 * Db\LogRepository - eine Option wuechse mit jedem Eintrag und wuerde bei
 * jedem Schreiben komplett neu gespeichert). Jeder Schreibvorgang feuert
 * zusaetzlich den Hook `ctp_log` fuer Betreiber mit eigenem Logger - Teil der
 * Kompatibilitaetszusage (docs/COMPATIBILITY.md) - und schreibt bei
 * `WP_DEBUG_LOG` zusaetzlich in dessen error_log.
 *
 * Der Datenschutz sitzt an dieser einen Stelle, nicht an jeder Aufrufstelle:
 * sanitizeContext() nimmt Personenverweise nach derselben Regel wie
 * SyncEngine::withoutPersonReferences() heraus, verschluesselte Key-Werte
 * (`ctp1:`/`ctp2:`-Praefix) werden nie geschrieben - weder als eigener
 * Kontext-Wert (sanitizeValue()) noch eingebettet in der freien Meldung
 * (sanitizeMessage()) -, und eine Adresse im Kontext verliert ihren
 * Abfrageteil - der kann ein Token tragen (siehe `fileUrl` in
 * ChurchToolsPlugin\Sync\SyncEngine::importImage()).
 */
final class Log
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    public const AREA_EVENTS = 'events';
    public const AREA_GROUPS = 'groups';
    public const AREA_IMAGES = 'images';
    public const AREA_MIGRATION = 'migration';

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $area, string $message, array $context = []): void
    {
        self::write(self::LEVEL_ERROR, $area, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $area, string $message, array $context = []): void
    {
        self::write(self::LEVEL_WARNING, $area, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $area, string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $area, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $area, string $message, array $context): void
    {
        $message = self::sanitizeMessage($message);
        $context = self::sanitizeContext($context);

        (new LogRepository())->insert($level, $area, $message, $context);

        /**
         * Fuer Betreiber mit eigenem Logger - Teil der Kompatibilitaetszusage
         * (docs/COMPATIBILITY.md), der erste Hook, den dieses Plugin ueberhaupt
         * anbietet.
         *
         * @param string $level   error|warning|info
         * @param string $area    events|groups|images|migration
         * @param string $message
         * @param array<string, mixed> $context bereits bereinigt, siehe sanitizeContext()
         */
        do_action('ctp_log', $level, $area, $message, $context);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- kein Debug-Rest, sondern das ausdrueckliche Opt-in eines Betreibers ueber WP_DEBUG_LOG.
            error_log(sprintf('[churchtools-plugin] %s/%s: %s', $level, $area, $message));
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        // Dieselbe Regel wie fuer die gespeicherte Rohantwort (raw_data): ein
        // Kontext ist frei geformt, und ein Aufrufer, der versehentlich eine
        // ganze ChurchTools-Huelle mitgibt, soll trotzdem keine Personenverweise
        // ins Protokoll tragen.
        $context = SyncEngine::withoutPersonReferences($context);

        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                // Ausgelassen statt geschwaerzt: Ein Feldname wie "api_key"
                // sagt schon selbst genug, ein "[redacted]" daneben waere nur
                // Rauschen.
                continue;
            }

            $sanitized[$key] = is_string($value) ? self::sanitizeValue($value) : $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/api[_-]?key|authoriz|token|secret|password/i', $key);
    }

    /**
     * Wie sanitizeValue(), aber fuer einen ganzen Satz statt eines einzelnen
     * Werts: Ein verschluesselter Key kann mitten in einer zusammengesetzten
     * Fehlermeldung stehen ("... : ctp2:xyz"), nicht nur als ihr Anfang -
     * str_starts_with() aus sanitizeValue() wuerde das nicht finden.
     */
    private static function sanitizeMessage(string $message): string
    {
        return (string) preg_replace('/\bctp[12]:\S*/', '[redacted]', $message);
    }

    /**
     * Ein Feldname allein schuetzt nicht - ein verschluesselter Key oder eine
     * Adresse mit Token koennen unter jedem Namen im Kontext landen.
     */
    private static function sanitizeValue(string $value): string
    {
        if (str_starts_with($value, 'ctp1:') || str_starts_with($value, 'ctp2:')) {
            return '[redacted]';
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $queryPosition = strpos($value, '?');

            return $queryPosition === false ? $value : substr($value, 0, $queryPosition);
        }

        return $value;
    }
}
