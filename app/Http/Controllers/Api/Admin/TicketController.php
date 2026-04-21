<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

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

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        try {
            return $this->success('Admin ticket list loaded successfully.', $this->ticketService->listForAdmin($query));
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Admin ticket detail loaded successfully.', [
                'ticket' => $this->ticketService->findForAdmin($ticketId),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function reply(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Ticket replied successfully.', [
                'ticket' => $this->ticketService->replyAsAdmin($ticketId, $authUser, $body),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function updateStatus(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Ticket status updated successfully.', [
                'ticket' => $this->ticketService->updateStatusByAdmin($ticketId, $authUser, $body),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function close(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Ticket resolved successfully.', [
                'ticket' => $this->ticketService->closeByAdmin($ticketId, $authUser, $body),
            ]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function warranty(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $authUser = is_array($request['auth_user'] ?? null) ? $request['auth_user'] : [];
        $ticketId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Warranty replacement completed successfully.', [
                'ticket' => $this->ticketService->warrantyReplaceByAdmin($ticketId, $authUser, $body),
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
