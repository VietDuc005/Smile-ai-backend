<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Services\UserActivityService;
use App\Services\UserService;

final class UserController
{
    private UserActivityService $userActivityService;

    private UserService $userService;

    public function __construct(
        ?UserService $userService = null,
        ?UserActivityService $userActivityService = null
    ) {
        $this->userService = $userService ?? new UserService();
        $this->userActivityService = $userActivityService ?? new UserActivityService();
    }

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success('Customer list loaded successfully.', $this->userService->listCustomers($query));
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function block(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $userId = (string) ($routeParams['id'] ?? '');
        $reason = (string) ($body['reason'] ?? 'Violated policy');

        try {
            $user = $this->userService->blockCustomer($userId, $reason);
            $this->safeLog(
                (string) ($user['id'] ?? ''),
                'user.blocked',
                'Account blocked by admin.',
                [
                    'reason' => $reason,
                    'admin_id' => $authUser['id'] ?? null,
                ]
            );

            return $this->success('Customer blocked successfully.', [
                'user' => $user,
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ForbiddenException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function unblock(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $userId = (string) ($routeParams['id'] ?? '');

        try {
            $user = $this->userService->unblockCustomer($userId);
            $this->safeLog(
                (string) ($user['id'] ?? ''),
                'user.unblocked',
                'Account unblocked by admin.',
                [
                    'admin_id' => $authUser['id'] ?? null,
                ]
            );

            return $this->success('Customer unblocked successfully.', [
                'user' => $user,
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ForbiddenException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
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

    private function safeLog(string $userId, string $action, string $description, array $metadata = []): void
    {
        try {
            $this->userActivityService->log($userId, $action, $description, $metadata);
        } catch (\Throwable) {
            // Audit trail should not roll back admin state changes at controller level.
        }
    }
}
