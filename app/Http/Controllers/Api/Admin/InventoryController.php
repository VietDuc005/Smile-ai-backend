<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\InventoryService;

final class InventoryController
{
    private InventoryService $inventoryService;

    public function __construct(?InventoryService $inventoryService = null)
    {
        $this->inventoryService = $inventoryService ?? new InventoryService();
    }

    public function import(array $request): array
    {
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Inventory imported successfully.', $this->inventoryService->importAccounts($payload), 201);
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
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $productId = (string) ($routeParams['productId'] ?? '');

        try {
            return $this->success('Inventory list loaded successfully.', $this->inventoryService->listByProduct($productId, $query));
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function updateStatus(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $accountId = (string) ($routeParams['accountId'] ?? '');
        $status = (string) ($body['status'] ?? '');

        try {
            return $this->success('Inventory status updated successfully.', [
                'account' => $this->inventoryService->updateStatus($accountId, $status),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function updateAccount(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $accountId = (string) ($routeParams['accountId'] ?? '');

        try {
            return $this->success('Inventory account updated successfully.', [
                'account' => $this->inventoryService->updateAccount($accountId, $body),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function setTotpSecret(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $accountId = (string) ($routeParams['accountId'] ?? '');
        $rawSecret = array_key_exists('totp_secret', $body)
            ? (trim((string) ($body['totp_secret'] ?? '')) === '' ? null : trim((string) $body['totp_secret']))
            : null;

        try {
            return $this->success('TOTP secret updated successfully.', [
                'account' => $this->inventoryService->setTotpSecret($accountId, $rawSecret),
            ]);
        } catch (\App\Exceptions\NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (\App\Exceptions\ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\App\Exceptions\InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function release(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $accountId = (string) ($routeParams['accountId'] ?? '');

        try {
            return $this->success('Account released back to available stock.', [
                'account' => $this->inventoryService->release($accountId),
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
