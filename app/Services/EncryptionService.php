<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;

final class EncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    private EnvService $envService;

    public function __construct(?EnvService $envService = null)
    {
        $this->envService = $envService ?? new EnvService();
    }

    public function encrypt(string $plainText): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new InfrastructureException('OpenSSL extension is required for inventory encryption.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false || $ivLength <= 0) {
            throw new InfrastructureException('Unable to determine encryption IV length.');
        }

        $iv = random_bytes($ivLength);
        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($cipherText)) {
            throw new InfrastructureException('Unable to encrypt inventory secret.');
        }

        return base64_encode($iv) . ':' . base64_encode($cipherText);
    }

    public function decrypt(string $payload): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new InfrastructureException('OpenSSL extension is required for inventory encryption.');
        }

        if (!str_contains($payload, ':')) {
            return $payload;
        }

        $parts = explode(':', $payload, 2);

        if (count($parts) !== 2) {
            throw new InfrastructureException('Inventory secret payload is malformed.');
        }

        [$encodedIv, $encodedCipherText] = $parts;
        $iv = base64_decode($encodedIv, true);
        $cipherText = base64_decode($encodedCipherText, true);

        if ($iv === false || $cipherText === false) {
            throw new InfrastructureException('Inventory secret payload cannot be decoded.');
        }

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($plainText)) {
            throw new InfrastructureException('Unable to decrypt inventory secret.');
        }

        return $plainText;
    }

    private function key(): string
    {
        $secret = trim((string) $this->envService->get('ACCOUNT_CIPHER_KEY', ''));

        if ($secret === '') {
            throw new InfrastructureException('ACCOUNT_CIPHER_KEY is missing from environment.');
        }

        return hash('sha256', $secret, true);
    }
}
