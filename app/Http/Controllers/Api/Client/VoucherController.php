<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\ValidationException;
use App\Services\VoucherService;

final class VoucherController
{
    private VoucherService $voucherService;

    public function __construct(?VoucherService $voucherService = null)
    {
        $this->voucherService = $voucherService ?? new VoucherService();
    }

    public function validate(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $code = trim((string) ($body['code'] ?? ''));
        $amount = max(0, (float) ($body['amount'] ?? 0));

        if ($code === '') {
            return $this->error('Field code is required.', 422);
        }

        try {
            $result = $this->voucherService->validateForOrder($code, $amount);

            return $this->success('Voucher hợp lệ.', [
                'code' => $result['voucher']['code'],
                'discount_type' => $result['voucher']['discount_type'],
                'discount_value' => $result['voucher']['discount_value'],
                'discount_amount' => $result['discount_amount'],
            ]);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
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
