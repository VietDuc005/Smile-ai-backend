<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Services\NotificationService;

final class AlertController
{
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $expiringDays = max(1, min(30, (int) ($query['expiring_days'] ?? 7)));
        $lowStockThreshold = max(0, min(50, (int) ($query['low_stock_threshold'] ?? 3)));

        try {
            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'Admin alerts loaded.',
                'data' => $this->notificationService->adminAlerts($expiringDays, $lowStockThreshold),
            ];
        } catch (InfrastructureException $exception) {
            return [
                'status_code' => 500,
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function sendRenewalReminders(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $expiringDays = max(1, min(30, (int) ($body['expiring_days'] ?? 7)));

        try {
            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'Renewal reminders sent.',
                'data' => [
                    'count' => $this->notificationService->notifyRenewalReminders($expiringDays),
                    'expiring_days' => $expiringDays,
                ],
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
