<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\NotificationService;
use App\Services\VoucherService;

final class VoucherController
{
    private VoucherService $voucherService;
    private NotificationService $notificationService;

    public function __construct(?VoucherService $voucherService = null, ?NotificationService $notificationService = null)
    {
        $this->voucherService = $voucherService ?? new VoucherService();
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function index(): array
    {
        $vouchers = $this->voucherService->list();

        return $this->success('Voucher list loaded.', ['items' => $vouchers]);
    }

    public function store(array $request): array
    {
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            $voucher = $this->voucherService->create($payload);
            $this->notificationService->notifyVoucherCreated($voucher);

            return $this->success('Voucher created successfully.', ['voucher' => $voucher], 201);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function update(array $request): array
    {
        $payload = is_array($request['body'] ?? null) ? $request['body'] : [];
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $id = (string) ($routeParams['id'] ?? '');

        try {
            $voucher = $this->voucherService->update($id, $payload);

            return $this->success('Voucher updated successfully.', ['voucher' => $voucher]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function destroy(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $id = (string) ($routeParams['id'] ?? '');

        try {
            $this->voucherService->delete($id);

            return $this->success('Voucher deleted successfully.', []);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
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
