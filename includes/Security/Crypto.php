<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Security;

final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
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

    private static function key(): string
    {
        // Derived from the site's own AUTH_KEY salt, so the encrypted value is only
        // decryptable on this WordPress install (e.g. not after copying the DB elsewhere).
        $secret = defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : wp_salt('auth');

        return hash('sha256', $secret, true);
    }
}
