<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\UnauthorizedException;
use JsonException;

final class JwtService
{
    private EnvService $envService;

    public function __construct(?EnvService $envService = null)
    {
        $this->envService = $envService ?? new EnvService();
    }

    public function issue(array $user): string
    {
        $now = time();
        $payload = [
            'iss' => (string) $this->envService->get('JWT_ISSUER', $this->envService->get('APP_URL', 'smile-ai-backend')),
            'sub' => (string) ($user['id'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? 'user'),
            'iat' => $now,
            'exp' => $now + $this->ttl(),
        ];

        return $this->encode($payload);
    }

    public function decode(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new UnauthorizedException('Token format is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $signedContent = $encodedHeader . '.' . $encodedPayload;
        $expectedSignature = $this->sign($signedContent);

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new UnauthorizedException('Token signature is invalid.');
        }

        try {
            $header = json_decode($this->base64UrlDecode($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnauthorizedException('Token payload is invalid.', 0, $exception);
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            throw new UnauthorizedException('Token algorithm is not supported.');
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw new UnauthorizedException('Token has expired.');
        }

        return $payload;
    }

    public function ttl(): int
    {
        return max(300, $this->envService->int('JWT_TTL', 86400));
    }

    private function encode(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        try {
            $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
            $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InfrastructureException('Unable to encode JWT payload.', 0, $exception);
        }

        $signature = $this->sign($encodedHeader . '.' . $encodedPayload);

        return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
    }

    private function sign(string $content): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $content, $this->secret(), true));
    }

    private function secret(): string
    {
        $secret = (string) $this->envService->get('JWT_SECRET', '');

        if ($secret === '') {
            throw new InfrastructureException('JWT_SECRET is missing from environment.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new UnauthorizedException('Token base64 payload is invalid.');
        }

        return $decoded;
    }
}
