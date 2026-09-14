<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Security;

/**
 * Verschluesselt das eine Geheimnis dieses Plugins, den ChurchTools-API-Key,
 * fuer die Ablage in der Datenbank.
 *
 * Seit dem Sicherheits-Review vom 2026-09-14 mit libsodium
 * (`crypto_secretbox`, XSalsa20-Poly1305): verschluesselt *und*
 * authentifiziert - ein veraenderter Ciphertext entschluesselt sich nicht zu
 * irgendetwas, sondern gar nicht. Die Fassung davor (AES-256-CBC ohne MAC)
 * bleibt lesbar, geschrieben wird nur noch die neue (siehe ApiKey::migrate()).
 * PHP bringt sodium seit 7.2 mit; wo die Erweiterung fehlt (die lokale
 * XAMPP-Testumgebung ist so ein Fall), stellt WordPress dieselben Funktionen
 * ueber sodium_compat bereit.
 *
 * Zur Einordnung, was das schuetzt: Der Schluessel kommt aus `AUTH_KEY` in
 * wp-config.php. Fliesst nur die Datenbank ab (ein Backup, eine SQL-Injection
 * in einem anderen Plugin), bleibt der Key geheim; wer auch wp-config.php hat,
 * hat beides. Wer das nicht will, legt den Key gar nicht erst in die
 * Datenbank, sondern in die Konstante CTP_API_KEY (siehe ApiKey).
 */
final class Crypto
{
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
     * `ctp2:` ist die sodium-Fassung, `ctp1:` die alte mit AES-256-CBC. Werte
     * aus der Zeit vor `ctp1:` tragen gar kein Praefix; decrypt() liest alle
     * drei Formen.
     */
    private const PREFIX = 'ctp2:';

    private const LEGACY_PREFIX = 'ctp1:';

    private const LEGACY_CIPHER = 'aes-256-cbc';

    /**
     * Kontext der Schluesselableitung. `AUTH_KEY` benutzt WordPress selbst
     * (fuer die Anmelde-Cookies); ein Hash darueber ohne eigenen Kontext, wie
     * in der alten Fassung, waere derselbe Schluessel, den jedes andere Plugin
     * mit derselben Idee ableitet.
     */
    private const KDF_CONTEXT = 'churchtools-plugin/api-key/v2';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $box);
    }

    /**
     * Der Klartext, oder '' - bei einem fremden, veraenderten oder mit einem
     * anderen `AUTH_KEY` verschluesselten Wert.
     */
    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        if (str_starts_with($encoded, self::PREFIX)) {
            return self::decryptCurrent(substr($encoded, strlen(self::PREFIX)));
        }

        if (str_starts_with($encoded, self::LEGACY_PREFIX)) {
            $encoded = substr($encoded, strlen(self::LEGACY_PREFIX));
        }

        return self::decryptLegacy($encoded);
    }

    /**
     * Ob dieser Wert von encrypt() stammt - aus dieser oder der alten Fassung.
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
        return str_starts_with($value, self::PREFIX) || str_starts_with($value, self::LEGACY_PREFIX);
    }

    /** Ob dieser Wert noch in einer alten Fassung vorliegt und neu verschluesselt werden sollte. */
    public static function isLegacy(string $value): bool
    {
        return $value !== '' && !str_starts_with($value, self::PREFIX);
    }

    private static function decryptCurrent(string $encoded): string
    {
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return '';
        }

        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = sodium_crypto_secretbox_open($box, $nonce, self::key());
        } catch (\SodiumException) {
            return '';
        }

        return $plaintext === false ? '' : $plaintext;
    }

    private static function decryptLegacy(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::LEGACY_CIPHER);
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);

        if (strlen($iv) !== $ivLength || $ciphertext === '') {
            return '';
        }

        $plaintext = openssl_decrypt($ciphertext, self::LEGACY_CIPHER, self::legacyKey(), OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? '' : $plaintext;
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', self::secret(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KDF_CONTEXT);
    }

    /** Die Ableitung der alten Fassung, nur noch zum Lesen. */
    private static function legacyKey(): string
    {
        return hash('sha256', self::secret(), true);
    }

    private static function secret(): string
    {
        // Derived from the site's own AUTH_KEY salt, so the encrypted value is only
        // decryptable on this WordPress install (e.g. not after copying the DB elsewhere).
        return defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : wp_salt('auth');
    }
}
