<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Security;

use ChurchToolsPlugin\Settings;

/**
 * Woher der ChurchTools-API-Key kommt und ob er brauchbar ist - die eine
 * Stelle, die Sync, Gruppen-Sync, Backend und Hinweise danach fragen.
 *
 * Zwei Quellen, in dieser Rangfolge:
 *
 * 1. Die Konstante `CTP_API_KEY` in wp-config.php oder eine Umgebungsvariable
 *    gleichen Namens. Dann liegt das Geheimnis gar nicht in der Datenbank,
 *    und ein Datenbank-Backup traegt es nicht mit (Sicherheits-Review
 *    2026-09-14).
 * 2. Der verschluesselte Wert in `ctp_settings['api_key']` (siehe Crypto).
 *
 * Ohne Key fragt das Plugin ChurchTools gar nicht - einen anonymen Weg gibt es
 * seit dem 2026-09-14 nicht mehr (Api\Client::send()).
 */
final class ApiKey
{
    public const CONSTANT = 'CTP_API_KEY';

    private const SETTINGS_OPTION = 'ctp_settings';

    /**
     * Der Key aus der Serverkonfiguration, oder ''.
     */
    public static function fromConfig(): string
    {
        if (defined(self::CONSTANT)) {
            $value = constant(self::CONSTANT);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $env = getenv(self::CONSTANT);

        return is_string($env) ? trim($env) : '';
    }

    public static function isFromConfig(): bool
    {
        return self::fromConfig() !== '';
    }

    /** Der verschluesselt gespeicherte Wert, wie er in der Option steht. */
    public static function storedCiphertext(): string
    {
        $settings = get_option(self::SETTINGS_OPTION, []);

        return is_array($settings) && is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '';
    }

    /**
     * Ob ueberhaupt ein Key eingerichtet ist - lesbar oder nicht. Die Frage
     * hinter „fehlt" im Backend, im Unterschied zu „nicht lesbar".
     */
    public static function isConfigured(): bool
    {
        return self::isFromConfig() || self::storedCiphertext() !== '';
    }

    /**
     * Der Key im Klartext, oder '' - wenn keiner eingerichtet ist oder der
     * gespeicherte sich nicht entschluesseln laesst.
     */
    public static function current(): string
    {
        $fromConfig = self::fromConfig();

        return $fromConfig !== '' ? $fromConfig : self::decryptStored(self::storedCiphertext());
    }

    public static function isUsable(): bool
    {
        return self::current() !== '';
    }

    /**
     * Hinterlegt, aber nicht mehr lesbar - das Zeichen einer Aenderung von
     * `AUTH_KEY` (Salts rotiert, Server gewechselt, Backup in eine frische
     * Installation). Ein Key aus der Konfiguration kann das nicht sein.
     */
    public static function decryptionFailed(): bool
    {
        return !self::isFromConfig() && self::storedCiphertext() !== '' && self::current() === '';
    }

    public static function decryptionErrorMessage(): string
    {
        return __('Der gespeicherte API-Key lässt sich nicht mehr entschlüsseln (z. B. nach einer Änderung von AUTH_KEY) – bitte unter „Einstellungen → Verbindung“ neu eingeben.', 'churchtools-plugin');
    }

    /**
     * Die Meldung, wenn gar kein brauchbarer Key da ist - fuer Laeufe, die
     * sonst still nichts taeten.
     */
    public static function unusableMessage(): string
    {
        return self::decryptionFailed()
            ? self::decryptionErrorMessage()
            : __('Kein API-Key hinterlegt – bitte unter „Einstellungen → Verbindung“ eintragen.', 'churchtools-plugin');
    }

    /**
     * Schreibt einen gespeicherten Key aus einer alten Verschluesselung in der
     * aktuellen neu. Laeuft einmal je Versionssprung (Installer::maybeUpgrade()),
     * nicht bei jedem Lesen.
     *
     * Am Sanitizer vorbei (Settings::writeUnsanitized()): Er reichte einen
     * `ctp1:`-Wert unveraendert durch - der soll hier aber gerade ersetzt
     * werden.
     *
     * @return bool ob neu geschrieben wurde
     */
    public static function migrate(): bool
    {
        $ciphertext = self::storedCiphertext();

        if (!Crypto::isLegacy($ciphertext)) {
            return false;
        }

        $plaintext = self::decryptStored($ciphertext);

        // Nicht lesbar: stehen lassen. decryptionFailed() meldet es, und ein
        // Neu-Eintragen ersetzt ihn - ein geloeschter Wert saehe dagegen aus
        // wie „nie eingerichtet".
        if ($plaintext === '') {
            return false;
        }

        $settings = get_option(self::SETTINGS_OPTION, []);
        $settings['api_key'] = Crypto::encrypt($plaintext);

        Settings::writeUnsanitized($settings);

        return true;
    }

    /**
     * Der gespeicherte Token, entschluesselt - oder '', wenn dabei nichts
     * Brauchbares herauskommt.
     *
     * Packt dabei aus, was vor 0.12.4 beim allerersten Speichern doppelt
     * verschluesselt wurde (siehe Crypto::PREFIX; diese Werte tragen kein
     * Praefix). Einmal entschluesselt kommt bei ihnen der base64-Text der
     * inneren Verschluesselung heraus - druckbar und kurz genug, also fuer
     * isPlausible() ein gueltiger Token, der dann als "401: No valid token"
     * bei ChurchTools landete. Ein echter Token entschluesselt sich zu nichts,
     * deshalb entscheidet allein das Ergebnis der zweiten Runde, ob es eine zu
     * entpacken gab. migrate() schreibt beide Faelle in der neuen Form zurueck.
     */
    private static function decryptStored(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $decrypted = Crypto::decrypt($ciphertext);

        if (!Crypto::isCiphertext($ciphertext)) {
            $unwrapped = Crypto::decrypt($decrypted);

            if (self::isPlausible($unwrapped)) {
                return $unwrapped;
            }
        }

        return self::isPlausible($decrypted) ? $decrypted : '';
    }

    /**
     * Ein falscher Schluessel kann bei der alten Verschluesselung zufaellig
     * „gelingen" und Binaerdaten liefern - die gingen sonst geradewegs in den
     * Authorization-Header und scheiterten als irrefuehrender 401.
     */
    private static function isPlausible(string $token): bool
    {
        return $token !== '' && strlen($token) <= 512 && ctype_print($token);
    }
}
