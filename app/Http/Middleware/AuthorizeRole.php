<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

final class AuthorizeRole
{
    public function handle(array $request, Closure $next, array|string $roles = ['user', 'admin']): mixed
    {
        $user = $request['auth_user'] ?? null;

        if (!is_array($user)) {
            return $this->response('Unauthorized.', 401);
        }

        $allowedRoles = is_array($roles) ? $roles : [$roles];
        $currentRole = (string) ($user['role'] ?? 'guest');

        if (!in_array($currentRole, $allowedRoles, true)) {
            return $this->response('Forbidden.', 403, [
                'required_roles' => $allowedRoles,
                'current_role' => $currentRole,
            ]);
        }

        return $next($request);
    }

    private function response(string $message, int $statusCode, array $meta = []): array
    {
        return [
            'status_code' => $statusCode,
            'success' => false,
            'message' => $message,
            'meta' => $meta,
        ];
    }
}
