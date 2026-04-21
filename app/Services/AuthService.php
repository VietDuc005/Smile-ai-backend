<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnauthorizedException;
use App\Jobs\SendOtpEmailJob;

final class AuthService
{
    private JwtService $jwtService;

    private OtpService $otpService;

    private UserService $userService;

    private UserActivityService $userActivityService;

    public function __construct(
        ?OtpService $otpService = null,
        ?UserService $userService = null,
        ?JwtService $jwtService = null,
        ?UserActivityService $userActivityService = null
    ) {
        $this->otpService = $otpService ?? new OtpService();
        $this->userService = $userService ?? new UserService();
        $this->jwtService = $jwtService ?? new JwtService();
        $this->userActivityService = $userActivityService ?? new UserActivityService();
    }

    public function requestOtp(string $email): array
    {
        $normalizedEmail = $this->otpService->normalizeEmail($email);
        $existingUser = $this->userService->findByEmail($normalizedEmail);

        if ($existingUser !== null) {
            $this->userService->ensureActive($existingUser);
        }

        $otpPayload = $this->otpService->createForEmail($normalizedEmail);

        if ($existingUser !== null) {
            $this->safeLog(
                (string) ($existingUser['id'] ?? ''),
                'auth.otp_requested',
                'Requested an OTP sign-in code.'
            );
        }

        // Đăng ký gửi email SAU khi HTTP response đã được flush về client.
        // Tránh SMTP blocking (5-15s) làm axios timeout trước khi nhận được response.
        $job = new SendOtpEmailJob($otpPayload['email'], $otpPayload['otp'], $otpPayload['ttl']);
        register_shutdown_function(static function () use ($job): void {
            try {
                $job->handle();
            } catch (\Throwable) {
                // OTP đã lưu vào storage — user vẫn nhận được OTP nếu email đến muộn hơn.
            }
        });

        return [
            'email' => $otpPayload['email'],
            'otp_expires_in' => $otpPayload['ttl'],
            'delivery' => 'queued',
        ];
    }

    public function verifyOtp(string $email, string $otp): array
    {
        $normalizedEmail = $this->otpService->normalizeEmail($email);
        $this->otpService->assertValid($normalizedEmail, $otp);

        $existingUser = $this->userService->findByEmail($normalizedEmail);
        if ($existingUser !== null) {
            $this->userService->ensureActive($existingUser);
        }

        $isNewUser = $existingUser === null;
        $user = $existingUser ?? $this->userService->createFromEmail($normalizedEmail);
        $this->userService->ensureActive($user);
        $this->userService->touchLastLogin((string) ($user['id'] ?? ''));
        $user = $this->userService->findById((string) ($user['id'] ?? '')) ?? $user;

        if ($isNewUser) {
            $this->safeLog(
                (string) ($user['id'] ?? ''),
                'auth.account_created',
                'Account created automatically after OTP verification.',
                [
                    'email' => $user['email'] ?? '',
                ]
            );
        }

        $this->safeLog(
            (string) ($user['id'] ?? ''),
            'auth.login_success',
            'Logged in successfully via OTP.'
        );

        $token = $this->jwtService->issue($user);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => $this->jwtService->ttl(),
            'is_new_user' => $isNewUser,
            'user' => $user,
        ];
    }

    public function userFromAccessToken(string $token): array
    {
        $payload = $this->jwtService->decode($token);
        $userId = (string) ($payload['sub'] ?? '');

        if ($userId === '') {
            throw new UnauthorizedException('Token subject is missing.');
        }

        $user = $this->userService->findById($userId);

        if ($user === null) {
            throw new UnauthorizedException('User linked to token was not found.');
        }

        $this->userService->ensureActive($user);

        return $user;
    }

    private function safeLog(string $userId, string $action, string $description, array $metadata = []): void
    {
        try {
            $this->userActivityService->log($userId, $action, $description, $metadata);
        } catch (\Throwable) {
            // Activity history should not break critical auth flows.
        }
    }
}
