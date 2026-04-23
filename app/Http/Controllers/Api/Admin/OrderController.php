<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

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

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success('Admin order list loaded successfully.', $this->orderService->listForAdmin($query));
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $orderId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Admin order detail loaded successfully.', [
                'order' => $this->orderService->findForAdmin($orderId),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function match(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $orderId = (string) ($routeParams['id'] ?? '');
        $note = trim((string) ($body['note'] ?? ''));

        try {
            return $this->success('Order matched successfully.', [
                'order' => $this->orderService->forceMatchForAdmin($orderId, [
                    'admin_note' => $note !== '' ? $note : null,
                ]),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function provision(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $orderId = (string) ($routeParams['id'] ?? '');
        $itemId = (string) ($routeParams['itemId'] ?? '');

        try {
            return $this->success('Account provisioned successfully.', [
                'order' => $this->orderService->manualProvisionItem($orderId, $itemId, $body),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function cancel(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $orderId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Order cancelled successfully.', [
                'order' => $this->orderService->cancelForAdmin($orderId),
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
