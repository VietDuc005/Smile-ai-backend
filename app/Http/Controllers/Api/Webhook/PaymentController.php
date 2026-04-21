<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Exceptions\InfrastructureException;
use App\Exceptions\ValidationException;
use App\Services\PaymentWebhookService;

final class PaymentController
{
    private PaymentWebhookService $paymentWebhookService;

    public function __construct(?PaymentWebhookService $paymentWebhookService = null)
    {
        $this->paymentWebhookService = $paymentWebhookService ?? new PaymentWebhookService();
    }

    public function receive(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'Payment webhook processed.',
                'data' => $this->paymentWebhookService->handle($body, $headers, $query),
            ];
        } catch (ValidationException $exception) {
            return [
                'status_code' => 422,
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (InfrastructureException $exception) {
            return [
                'status_code' => 500,
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
