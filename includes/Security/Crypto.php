<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Security;

final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * Kennzeichnet einen Ciphertext dieses Plugins, damit ein zweiter
     * Verschluesselungsdurchlauf ueber denselben Wert auffaellt.
     *
     * Anlass ist WordPress selbst: Beim allerersten Schreiben einer Option
     * laeuft ihr Sanitizer zweimal - update_option() sanitisiert, stellt fest,
     * dass es die Option noch gar nicht gibt, und reicht an add_option()
     * weiter, das erneut sanitisiert (wp-includes/option.php). Der zweite
     * Durchlauf sieht damit die Ausgabe des ersten, und ohne dieses Praefix
     * kann SettingsPage::sanitizeSettings() nicht erkennen, dass der API-Key
     * darin bereits verschluesselt ist. Der Token lag danach doppelt
     * verschluesselt in der Datenbank und entschluesselte sich zu base64-Text
     * statt zum Token - ChurchTools antwortete auf jede Anfrage mit
     * "401: No valid token", waehrend der Verbindungstest gruen blieb, weil
     * der den getippten Wert nimmt und nicht den gespeicherten.
     *
     * Werte aus der Zeit davor tragen es nicht; decrypt() liest beide Formen.
     */
    private const PREFIX = 'ctp1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);

        return self::PREFIX . base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        if (self::isCiphertext($encoded)) {
            $encoded = substr($encoded, strlen(self::PREFIX));
        }

        $data = base64_decode($encoded, true);
        if ($data === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Ob dieser Wert von encrypt() stammt.
     *
     * Bewusst ausschliesslich am Praefix erkannt und nicht an einem
     * Probe-Entschluesseln: Ein ChurchTools-Token besteht aus Hex-Zeichen und
     * ist damit selbst gueltiges base64, das mit rund 1:256 zufaellig eine
     * gueltige PKCS7-Fuellung ergibt. Wer daraufhin "ist schon verschluesselt"
     * antwortet, legt diesen Token im Klartext in die Datenbank - ein
     * Fehlalarm hier ist teurer als das doppelte Verschluesseln, das diese
     * Pruefung verhindern soll.
     */
    public static function isCiphertext(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        // Derived from the site's own AUTH_KEY salt, so the encrypted value is only
        // decryptable on this WordPress install (e.g. not after copying the DB elsewhere).
        $secret = defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : wp_salt('auth');

        return hash('sha256', $secret, true);
    }
}
