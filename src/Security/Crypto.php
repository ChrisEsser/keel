<?php

declare(strict_types=1);

namespace Keel\Security;

// Reversible encryption for secrets that must be recovered at runtime (e.g. TOTP secrets).
// Not for passwords or tokens -- those stay one-way (password_hash/hash).
class Crypto
{
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $encoded): ?string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        return $plaintext === false ? null : $plaintext;
    }

    private static function key(): string
    {
        $encoded = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        if ($encoded === '') {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is not set -- run scripts/generate-app-key.php and add the result to config/.env.');
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is invalid -- regenerate it with scripts/generate-app-key.php.');
        }

        return $key;
    }
}
