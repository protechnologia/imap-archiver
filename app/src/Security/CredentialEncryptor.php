<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Szyfruje/odszyfrowuje poświadczenia IMAP (hasło lub refresh_token) at-rest.
 *
 * libsodium `secretbox` (XSalsa20 + Poly1305): symetryczne, uwierzytelnione.
 * Nonce (24 B) losowany per zapis i doklejany przed szyfrogramem, całość base64.
 * Bezstanowy — bezpieczny w worker mode (FrankenPHP).
 */
final class CredentialEncryptor {
    /** Surowy klucz binarny (32 B). */
    private string $key;

    /**
     * @param string $key 32-bajtowy klucz w zapisie hex (64 znaki), z MAIL_CRYPTO_KEY.
     */
    public function __construct(string $key) {
        if ($key === '') {
            throw new \InvalidArgumentException(
                'MAIL_CRYPTO_KEY jest pusty — ustaw 32-bajtowy klucz hex (np. `openssl rand -hex 32`).'
            );
        }

        $binary = @sodium_hex2bin($key);
        if (strlen($binary) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(sprintf(
                'MAIL_CRYPTO_KEY musi być %d-bajtowym kluczem (64 znaki hex).',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ));
        }

        $this->key = $binary;
        sodium_memzero($binary);
    }

    /** Zwraca base64(nonce ‖ ciphertext) — gotowe do zapisu w kolumnie tekstowej. */
    public function encrypt(string $plaintext): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /** Odwraca encrypt(); rzuca, gdy klucz nie pasuje lub dane są uszkodzone. */
    public function decrypt(string $stored): string {
        $decoded = base64_decode($stored, true);
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($decoded === false || strlen($decoded) < $minLength) {
            throw new \RuntimeException('Nieprawidłowy szyfrogram poświadczenia (zła długość lub base64).');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Nie udało się odszyfrować poświadczenia (zły klucz lub uszkodzone dane).');
        }

        return $plaintext;
    }
}
