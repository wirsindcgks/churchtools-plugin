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
}
