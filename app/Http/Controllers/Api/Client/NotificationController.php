<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\InfrastructureException;
use App\Services\NotificationService;

final class NotificationController
{
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function index(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $userId = trim((string) ($authUser['id'] ?? ''));

        try {
            return $this->success('Notifications loaded.', $this->notificationService->listForUser($userId));
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function markAllRead(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $userId = trim((string) ($authUser['id'] ?? ''));

        try {
            $this->notificationService->markAllReadForUser($userId);

            return $this->success('All notifications marked as read.', []);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function markRead(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $userId = trim((string) ($authUser['id'] ?? ''));
        $notificationId = trim((string) ($routeParams['id'] ?? ''));

        try {
            $this->notificationService->markReadById($notificationId, $userId);

            return $this->success('Notification marked as read.', []);
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
}
