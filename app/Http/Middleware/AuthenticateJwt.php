<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Exceptions\InfrastructureException;
use App\Exceptions\UnauthorizedException;
use App\Services\AuthService;
use Closure;

final class AuthenticateJwt
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    public function handle(array $request, Closure $next): mixed
    {
        $token = $this->extractBearerToken($request['headers'] ?? []);

        if ($token === null) {
            return $this->response('Unauthorized. Missing bearer token.', 401);
        }

        try {
            $request['auth_user'] = $this->authService->userFromAccessToken($token);

            return $next($request);
        } catch (UnauthorizedException $exception) {
            return $this->response($exception->getMessage(), 401);
        } catch (ForbiddenException $exception) {
            return $this->response($exception->getMessage(), 403);
        } catch (InfrastructureException $exception) {
            return $this->response($exception->getMessage(), 500);
        }
    }

    private function extractBearerToken(array $headers): ?string
    {
        $authorization = null;

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== 'authorization') {
                continue;
            }

            $authorization = is_array($value) ? (string) reset($value) : (string) $value;
            break;
        }

        if ($authorization === null || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function response(string $message, int $statusCode): array
    {
        return [
            'status_code' => $statusCode,
            'success' => false,
            'message' => $message,
        ];
    }
}
