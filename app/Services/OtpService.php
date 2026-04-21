<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;

final class OtpService
{
    private EnvService $envService;

    private RedisService $redisService;

    // Fallback storage dir khi Redis không khả dụng (vd: XAMPP không có phpredis).
    private const FALLBACK_DIR = 'otp_sessions';

    public function __construct(?RedisService $redisService = null, ?EnvService $envService = null)
    {
        $this->envService = $envService ?? new EnvService();
        $this->redisService = $redisService ?? new RedisService($this->envService);
    }

    public function generate(): string
    {
        $length = $this->length();
        $maximum = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $maximum), $length, '0', STR_PAD_LEFT);
    }

    public function cacheKey(string $email): string
    {
        return 'otp:' . hash('sha256', $this->normalizeEmail($email));
    }

    public function ttl(): int
    {
        return max(60, $this->envService->int('OTP_TTL', 300));
    }

    public function createForEmail(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $otp = $this->generate();

        $this->store($normalizedEmail, $otp);

        return [
            'email' => $normalizedEmail,
            'otp' => $otp,
            'ttl' => $this->ttl(),
        ];
    }

    public function store(string $email, string $otp): void
    {
        try {
            $this->redisService->setWithTtl($this->cacheKey($email), $this->ttl(), $otp);
            return;
        } catch (\Throwable) {
            // Redis không khả dụng (phpredis chưa cài hoặc Redis server chưa chạy).
            // Fallback sang file-based storage.
        }

        try {
            $this->filePut($this->cacheKey($email), $otp, $this->ttl());
        } catch (\Throwable $e) {
            throw new InfrastructureException('Unable to store OTP (Redis and file fallback both failed).', 0, $e);
        }
    }

    public function assertValid(string $email, string $inputOtp): void
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedOtp   = $this->normalizeOtp($inputOtp);

        $expectedOtp = $this->getFromAnyBackend($this->cacheKey($normalizedEmail));

        if ($expectedOtp === null) {
            throw new UnauthorizedException('OTP has expired or was not requested.');
        }

        if (!hash_equals($expectedOtp, $normalizedOtp)) {
            throw new UnauthorizedException('OTP is invalid.');
        }

        $this->deleteFromAllBackends($this->cacheKey($normalizedEmail));
    }

    public function verify(string $email, string $inputOtp): bool
    {
        $normalizedOtp = $this->normalizeOtp($inputOtp);
        $expectedOtp   = $this->getFromAnyBackend($this->cacheKey($email));

        if ($expectedOtp === null) {
            return false;
        }

        return hash_equals($expectedOtp, $normalizedOtp);
    }

    public function normalizeEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '') {
            throw new ValidationException('Email is required.');
        }

        if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Email format is invalid.');
        }

        return $normalizedEmail;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function length(): int
    {
        $length = $this->envService->int('OTP_LENGTH', 6);

        return max(4, min(8, $length));
    }

    private function normalizeOtp(string $otp): string
    {
        $normalizedOtp = trim($otp);

        if ($normalizedOtp === '') {
            throw new ValidationException('OTP is required.');
        }

        if (preg_match('/^\d{' . $this->length() . '}$/', $normalizedOtp) !== 1) {
            throw new ValidationException(sprintf('OTP must contain exactly %d digits.', $this->length()));
        }

        return $normalizedOtp;
    }

    private function getFromAnyBackend(string $key): ?string
    {
        // Thử Redis trước.
        try {
            $value = $this->redisService->get($key);
            if ($value !== null) {
                return $value;
            }
        } catch (\Throwable) {
            // Redis không khả dụng, thử file fallback.
        }

        return $this->fileGet($key);
    }

    private function deleteFromAllBackends(string $key): void
    {
        try {
            $this->redisService->delete($key);
        } catch (\Throwable) {
            // Bỏ qua nếu Redis không khả dụng.
        }

        $this->fileDelete($key);
    }

    // ── File-based fallback (dùng khi Redis không có) ──────────────────────────

    private function fallbackDir(): string
    {
        // __DIR__ = app/Services → 3 levels up = project root
        $dir = rtrim(__DIR__ . '/../../storage/app/' . self::FALLBACK_DIR, '/\\');

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create OTP fallback dir: {$dir}");
        }

        return $dir;
    }

    private function fallbackPath(string $key): string
    {
        // key có thể chứa "otp:sha256hash", slug thành tên file an toàn.
        $safe = preg_replace('/[^a-f0-9]/', '_', $key);
        return $this->fallbackDir() . '/' . $safe . '.json';
    }

    private function filePut(string $key, string $value, int $ttl): void
    {
        $payload = json_encode([
            'v'   => $value,
            'exp' => time() + $ttl,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->fallbackPath($key), $payload, LOCK_EX);
    }

    private function fileGet(string $key): ?string
    {
        $path = $this->fallbackPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($payload['v'], $payload['exp'])) {
            return null;
        }

        if (time() > (int) $payload['exp']) {
            // TTL hết hạn — dọn file.
            @unlink($path);
            return null;
        }

        return (string) $payload['v'];
    }

    private function fileDelete(string $key): void
    {
        $path = $this->fallbackPath($key);

        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
