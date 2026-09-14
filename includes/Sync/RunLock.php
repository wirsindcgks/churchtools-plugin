<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

/**
 * Eine Sperre, damit ein Abgleich nicht zweimal gleichzeitig laeuft.
 *
 * Anlass (Sicherheits-Review 2026-09-14): WP-Cron, „Jetzt synchronisieren"
 * und der Sofortlauf nach dem Speichern koennen sich ueberlappen. Dann kann
 * die Aufraeumung verwaister Bilder des einen Laufs einen Anhang loeschen, den
 * der andere gerade importiert, aber noch nicht in seine Zeile geschrieben
 * hat - und zwei Laeufe zaehlen denselben leeren Abruf doppelt.
 *
 * Bewusst *nicht* ueber add_option(): WordPress schreibt dort mit
 * `INSERT … ON DUPLICATE KEY UPDATE` (wp-includes/option.php) - der zweite
 * Lauf ueberschriebe die Sperre des ersten und bekaeme sie ebenfalls. Hier
 * entscheidet der eindeutige Schluessel `option_name` selbst: `INSERT IGNORE`
 * legt die Zeile an oder aendert nichts, und die Zahl der betroffenen Zeilen
 * sagt, wer gewonnen hat. Eine Sperre, deren Lauf abgebrochen ist (PHP-Timeout,
 * Absturz), wird nach STALE_AFTER per Vergleich-und-Tausch uebernommen, und
 * freigegeben wird nur mit dem eigenen Token - ein uebernommener Lauf kann die
 * Sperre seines Nachfolgers nicht loeschen.
 */
final class RunLock
{
    private const OPTION_PREFIX = 'ctp_lock_';

    /** Laenger als jeder echte Lauf, kuerzer als das kleinste Sync-Intervall. */
    public const STALE_AFTER = 15 * 60;

    /**
     * Fuehrt $work unter der Sperre $name aus.
     *
     * @return bool false, wenn schon ein Lauf die Sperre haelt - dann ist
     *              $work nicht ausgefuehrt worden
     */
    public static function run(string $name, callable $work): bool
    {
        $token = self::acquire($name);

        if ($token === null) {
            return false;
        }

        try {
            $work();
        } finally {
            self::release($name, $token);
        }

        return true;
    }

    /** Der Token der gewonnenen Sperre, oder null, wenn ein anderer Lauf sie haelt. */
    public static function acquire(string $name, ?int $now = null): ?string
    {
        global $wpdb;

        $now ??= time();
        $option = self::OPTION_PREFIX . $name;
        $token = $now . ':' . bin2hex(random_bytes(8));

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $wpdb->options,
            $option,
            $token
        ));

        if ((int) $inserted === 1) {
            return $token;
        }

        $held = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option));

        if ($held === null) {
            // Zwischen INSERT und SELECT freigegeben - einmal nachfassen.
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $wpdb->options,
                $option,
                $token
            ));

            return (int) $inserted === 1 ? $token : null;
        }

        $since = (int) strtok((string) $held, ':');

        if ($since > 0 && $now - $since < self::STALE_AFTER) {
            return null;
        }

        $taken = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
            $wpdb->options,
            $token,
            $option,
            (string) $held
        ));

        return (int) $taken === 1 ? $token : null;
    }

    public static function release(string $name, string $token): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
            $wpdb->options,
            self::OPTION_PREFIX . $name,
            $token
        ));
    }

    public static function isHeld(string $name, ?int $now = null): bool
    {
        global $wpdb;

        $held = $wpdb->get_var($wpdb->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $wpdb->options,
            self::OPTION_PREFIX . $name
        ));

        if ($held === null) {
            return false;
        }

        $since = (int) strtok((string) $held, ':');

        return $since > 0 && ($now ?? time()) - $since < self::STALE_AFTER;
    }

    /** Siehe run(): Ein zweiter Lauf wartet nicht, er meldet sich. */
    public static function busyMessage(): string
    {
        return __('Gerade läuft bereits eine Synchronisation. Bitte in ein paar Minuten erneut versuchen.', 'churchtools-plugin');
    }
}
