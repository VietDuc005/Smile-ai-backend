<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

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

    public function profile(array $request): array
    {
        $authUser = $request['auth_user'] ?? null;

        if (!is_array($authUser) || !isset($authUser['id'])) {
            return $this->error('Unauthorized.', 401);
        }

        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $activityLimit = (int) ($query['activity_limit'] ?? 20);

        try {
            $profile = $this->userService->profileSummary((string) $authUser['id']);
            $profile['activity_history'] = $this->userActivityService->historyForUser(
                (string) $authUser['id'],
                $activityLimit
            );

            return $this->success('User profile loaded successfully.', $profile);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
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
