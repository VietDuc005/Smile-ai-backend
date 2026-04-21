<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\OrderService;

final class OrderController
{
    private OrderService $orderService;

    public function __construct(?OrderService $orderService = null)
    {
        $this->orderService = $orderService ?? new OrderService();
    }

    public function store(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Order created successfully.', [
                'order' => $this->orderService->createForUser($authUser, $payload),
            ], 201);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function index(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success('Order history loaded successfully.', $this->orderService->listForUser(
                (string) ($authUser['id'] ?? ''),
                $query
            ));
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $orderId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Order detail loaded successfully.', [
                'order' => $this->orderService->findForUser($orderId, (string) ($authUser['id'] ?? '')),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
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
