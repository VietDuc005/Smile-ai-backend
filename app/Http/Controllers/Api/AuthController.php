<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ForbiddenException;
use App\Exceptions\InfrastructureException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Services\AuthService;

final class AuthController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    public function requestOtp(array $payload): array
    {
        try {
            $result = $this->authService->requestOtp((string) ($payload['email'] ?? ''));

            return $this->success('OTP sent successfully.', $result);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ForbiddenException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function verifyOtp(array $payload): array
    {
        try {
            $result = $this->authService->verifyOtp(
                (string) ($payload['email'] ?? ''),
                (string) ($payload['otp'] ?? '')
            );

            return $this->success('Authenticated successfully.', $result);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (UnauthorizedException $exception) {
            return $this->error($exception->getMessage(), 401);
        } catch (ForbiddenException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function me(array $request): array
    {
        $user = $request['auth_user'] ?? null;

        if (!is_array($user)) {
            return $this->error('Unauthorized.', 401);
        }

        return $this->success('Authenticated user profile.', [
            'user' => $user,
        ]);
    }

    private function success(string $message, array $data, int $statusCode = 200): array
    {
        return [
            'status_code' => $statusCode,
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function error(string $message, int $statusCode): array
    {
        return [
            'status_code' => $statusCode,
            'success' => false,
            'message' => $message,
        ];
    }
}
