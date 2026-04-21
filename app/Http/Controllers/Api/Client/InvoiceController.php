<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Services\InvoiceService;

final class InvoiceController
{
    private InvoiceService $invoiceService;

    public function __construct(?InvoiceService $invoiceService = null)
    {
        $this->invoiceService = $invoiceService ?? new InvoiceService();
    }

    public function show(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $orderId = trim((string) ($routeParams['id'] ?? ''));
        $userId = trim((string) ($authUser['id'] ?? ''));

        try {
            $invoice = $this->invoiceService->buildForOrder($orderId, $userId);

            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'Invoice loaded successfully.',
                'data' => [
                    'invoice' => $invoice,
                ],
            ];
        } catch (NotFoundException $exception) {
            return [
                'status_code' => 404,
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (InfrastructureException $exception) {
            return [
                'status_code' => 500,
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'status_code' => 500,
                'success' => false,
                'message' => 'Unable to generate invoice.',
            ];
        }
    }
}
