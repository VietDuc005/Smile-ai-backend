<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\TagService;

final class TagController
{
    private TagService $tagService;

    public function __construct(?TagService $tagService = null)
    {
        $this->tagService = $tagService ?? new TagService();
    }

    public function index(array $request): array
    {
        try {
            return $this->success('Tags loaded successfully.', $this->tagService->list());
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function store(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Tag created successfully.', ['tag' => $this->tagService->create($body)], 201);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function update(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $tagId = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Tag updated successfully.', ['tag' => $this->tagService->update($tagId, $body)]);
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
        $tagId = (string) ($routeParams['id'] ?? '');

        try {
            $this->tagService->delete($tagId);

            return $this->success('Tag deleted successfully.', ['deleted' => true]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
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
