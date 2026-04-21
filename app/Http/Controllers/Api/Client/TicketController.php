<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\TicketService;

final class TicketController
{
    private TicketService $ticketService;

    public function __construct(?TicketService $ticketService = null)
    {
        $this->ticketService = $ticketService ?? new TicketService();
    }

    public function store(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Support ticket created successfully.', [
                'ticket' => $this->ticketService->createForUser($authUser, $body),
            ], 201);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function index(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success('Support ticket list loaded successfully.', $this->ticketService->listForUser(
                (string) ($authUser['id'] ?? ''),
                $query
            ));
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Support ticket detail loaded successfully.', [
                'ticket' => $this->ticketService->findForUser($ticketId, (string) ($authUser['id'] ?? '')),
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
