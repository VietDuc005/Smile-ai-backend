<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Exceptions\ValidationException;
use App\Services\DashboardService;

final class DashboardController
{
    private DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? new DashboardService();
    }

    public function summary(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return [
                'status_code' => 200,
                'success' => true,
                'message' => 'Dashboard summary loaded successfully.',
                'data' => $this->dashboardService->summary($query),
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
        } catch (\Throwable $exception) {
            return [
                'status_code' => 500,
                'success' => false,
                'message' => 'Unable to load dashboard summary.',
            ];
        }
    }
}
