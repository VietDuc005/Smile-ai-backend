<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;

final class TotpService
{
    private const STEP = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private EncryptionService $encryptionService;

    public function __construct(?EncryptionService $encryptionService = null)
    {
        $this->encryptionService = $encryptionService ?? new EncryptionService();
    }

    public function currentCode(string $encryptedSecret): string
    {
        $rawSecret = $this->encryptionService->decrypt($encryptedSecret);

        return $this->generate($rawSecret);
    }

    public function secondsRemaining(): int
    {
        return self::STEP - (time() % self::STEP);
    }

    public function currentWindow(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::STEP);
    }

    public function encryptSecret(string $rawBase32Secret): string
    {
        $normalized = strtoupper(str_replace([' ', '-'], '', $rawBase32Secret));

        if (!$this->isValidBase32($normalized)) {
            throw new ValidationException('TOTP secret must be a valid Base32 string (A-Z, 2-7).');
        }

        return $this->encryptionService->encrypt($normalized);
    }

    public function isValidBase32(string $input): bool
    {
        $normalized = strtoupper(str_replace([' ', '-', '='], '', $input));

        return $normalized !== '' && preg_match('/^[A-Z2-7]+$/', $normalized) === 1;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function generate(string $base32Secret): string
    {
        $counter = (int) floor(time() / self::STEP);
        $key = $this->base32Decode($base32Secret);
        // 64-bit big-endian counter
        $msg = pack('J', $counter);
        $hmac = hash_hmac('sha1', $msg, $key, true);
        // Dynamic truncation (RFC 4226 §5.4)
        $offset = ord($hmac[19]) & 0x0F;
        $code = (
            ((ord($hmac[$offset])     & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8)  |
            ((ord($hmac[$offset + 3]) & 0xFF))
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $input): string
    {
        $input = strtoupper(str_replace([' ', '-', '='], '', $input));
        $bits = '';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $input[$i]);

            if ($pos === false) {
                continue;
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($bits, 8);
        $output = '';

        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) {
                break;
            }

            $output .= chr((int) bindec($byte));
        }

        return $output;
    }
}
