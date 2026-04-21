<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

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

    public function availability(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $productId = (string) ($routeParams['productId'] ?? $routeParams['id'] ?? '');

        try {
            return $this->success('Inventory availability loaded successfully.', [
                'availability' => $this->inventoryService->availabilityByProduct($productId),
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
