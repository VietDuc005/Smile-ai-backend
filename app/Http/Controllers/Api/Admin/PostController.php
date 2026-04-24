<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\PostService;

final class PostController
{
    private PostService $postService;

    public function __construct(?PostService $postService = null)
    {
        $this->postService = $postService ?? new PostService();
    }

    public function index(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $page  = (int) ($query['page'] ?? 1);
        $limit = (int) ($query['limit'] ?? 20);

        try {
            return $this->success('Posts loaded successfully.', $this->postService->listAll($page, $limit));
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $postId      = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Post loaded successfully.', ['post' => $this->postService->findById($postId)]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function store(array $request): array
    {
        $body = is_array($request['body'] ?? null) ? $request['body'] : [];

        try {
            return $this->success('Post created successfully.', ['post' => $this->postService->create($body)], 201);
        } catch (ValidationException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function update(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $body        = is_array($request['body'] ?? null) ? $request['body'] : [];
        $postId      = (string) ($routeParams['id'] ?? '');

        try {
            return $this->success('Post updated successfully.', ['post' => $this->postService->update($postId, $body)]);
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
        $postId      = (string) ($routeParams['id'] ?? '');

        try {
            $this->postService->delete($postId);

            return $this->success('Post deleted successfully.', ['deleted' => true]);
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
            'success'     => true,
            'message'     => $message,
            'data'        => $data,
        ];
    }

    private function error(string $message, int $statusCode): array
    {
        return [
            'status_code' => $statusCode,
            'success'     => false,
            'message'     => $message,
        ];
    }
}
