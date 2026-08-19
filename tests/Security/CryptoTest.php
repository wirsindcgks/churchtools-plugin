<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Security;

use ChurchToolsPlugin\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testDecryptReversesEncrypt(): void
    {
        $plaintext = 'super-secret-churchtools-api-key';

        $this->assertSame($plaintext, Crypto::decrypt(Crypto::encrypt($plaintext)));
    }

    /**
     * Random IV per call (see Crypto::encrypt()) — a fixed ciphertext would mean
     * the same key gets re-encrypted to the same bytes every time, which is
     * exactly the property the random IV exists to avoid.
     */
    public function testEncryptIsNotDeterministic(): void
    {
        $plaintext = 'same-input-twice';

        $this->assertNotSame(Crypto::encrypt($plaintext), Crypto::encrypt($plaintext));
    }

    public function testEncryptAndDecryptOfEmptyStringStaySpecialCased(): void
    {
        $this->assertSame('', Crypto::encrypt(''));
        $this->assertSame('', Crypto::decrypt(''));
    }

    /**
     * decrypt() is also called on values from getDecryptedApiKey() with whatever
     * happens to be in the option — must fail closed (empty string), not throw or
     * emit a PHP warning, for arbitrary garbage input.
     */
    public function testDecryptOfGarbageInputReturnsEmptyString(): void
    {
        $this->assertSame('', Crypto::decrypt('not-valid-base64-or-ciphertext!!'));
    }

    public function testDecryptOfPlausibleButWrongCiphertextReturnsEmptyString(): void
    {
        $this->assertSame('', Crypto::decrypt(base64_encode('too short for iv + ciphertext')));
    }

    /**
     * Das Praefix ist der einzige Weg, auf dem SettingsPage::apiKeyToStore()
     * einen bereits verschluesselten Wert erkennt - ohne es verschluesselt
     * WordPress' doppelter Sanitizer-Aufruf beim ersten Speichern den Token
     * ein zweites Mal (siehe Crypto::PREFIX).
     */
    public function testEncryptMarksItsOwnOutput(): void
    {
        $this->assertTrue(Crypto::isCiphertext(Crypto::encrypt('super-secret-churchtools-api-key')));
        $this->assertFalse(Crypto::isCiphertext('super-secret-churchtools-api-key'));
        $this->assertFalse(Crypto::isCiphertext(''));
    }

    /**
     * Der Grund, warum isCiphertext() am Praefix haengt und nicht an einem
     * Probe-Entschluesseln: ChurchTools-Token bestehen aus Hex-Zeichen und
     * sind damit selbst gueltiges base64. Ein Fehlalarm hier hiesse, den
     * Token im Klartext in die Datenbank zu schreiben.
     */
    public function testHexTokensAreNeverMistakenForOwnCiphertext(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->assertFalse(Crypto::isCiphertext(bin2hex(random_bytes(32))));
        }
    }

    /**
     * Bestehende Installationen haben ihren Key ohne Praefix gespeichert; er
     * bleibt dort liegen, bis ihn jemand neu eintraegt, und muss bis dahin
     * weiter lesbar sein.
     */
    public function testDecryptStillReadsStoredValuesWithoutPrefix(): void
    {
        $legacy = ctp_test_legacy_encrypt('alter-gespeicherter-token');

        $this->assertFalse(Crypto::isCiphertext($legacy));
        $this->assertSame('alter-gespeicherter-token', Crypto::decrypt($legacy));
    }
}
