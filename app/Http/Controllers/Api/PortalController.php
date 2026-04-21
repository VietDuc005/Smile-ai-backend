<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\PortalService;

final class PortalController
{
    private PortalService $portalService;

    public function __construct(?PortalService $portalService = null)
    {
        $this->portalService = $portalService ?? new PortalService();
    }

    public function getCode(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $orderKey = trim((string) ($body['order_key'] ?? ''));
        $ipAddress = (string) ($request['server']['REMOTE_ADDR'] ?? '127.0.0.1');

        try {
            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'TOTP code generated.',
                'data' => $this->portalService->getCode($orderKey, $ipAddress),
            ];
        } catch (NotFoundException $e) {
            return ['status_code' => 404, 'success' => false, 'message' => $e->getMessage()];
        } catch (ValidationException $e) {
            return ['status_code' => 422, 'success' => false, 'message' => $e->getMessage()];
        }
    }
}
