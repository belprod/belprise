<?php

declare(strict_types=1);

namespace Belprise\Security;

/**
 * Encrypts small secrets stored in the database (the TOTP secret) with a
 * key derived from the configuration secret: a copied database alone does
 * not reveal them. AES-256-GCM.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';

    private readonly string $key;

    public function __construct(string $configSecret, string $purpose)
    {
        $this->key = hash_hmac('sha256', 'secretbox|' . $purpose, $configSecret, true);
    }

    public function seal(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }
}
