<?php

declare(strict_types=1);

namespace App\Services;

class EncryptionService
{
    private string $key;

    public function __construct()
    {
        $key = (string) ConfigService::get('security.encryption_key', '');
        if ($key === '') {
            throw new \RuntimeException('security.encryption_key is not configured in config.ini');
        }
        $this->key = hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(16);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 33) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($raw, 0, 16);
        $tag = substr($raw, 16, 16);
        $ciphertext = substr($raw, 32);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }
}
