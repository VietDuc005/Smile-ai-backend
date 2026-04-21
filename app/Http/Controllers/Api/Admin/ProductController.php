<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\ProductService;

final class ProductController
{
    private ProductService $productService;

    public function __construct(?ProductService $productService = null)
    {
        $this->productService = $productService ?? new ProductService();
    }

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success(
                'Admin product list loaded successfully.',
                $this->productService->listAdmin($query)
            );
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function store(array $request): array
    {
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Product created successfully.', [
                'product' => $this->productService->create($payload),
            ], 201);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function update(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];
        $productId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Product updated successfully.', [
                'product' => $this->productService->update($productId, $payload),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function destroy(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $productId = (string) ($routeParams['id'] ?? '');

        try {
            $result = $this->productService->delete($productId);

            return $this->success('Product deleted successfully.', $result);
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
