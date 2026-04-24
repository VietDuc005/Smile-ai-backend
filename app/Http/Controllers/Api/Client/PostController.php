<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
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
        $limit = (int) ($query['limit'] ?? 12);

        try {
            return $this->success('Posts loaded.', $this->postService->listPublished($page, $limit));
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function show(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $slug        = (string) ($routeParams['slug'] ?? '');

        try {
            return $this->success('Post loaded.', ['post' => $this->postService->findBySlugPublished($slug)]);
        } catch (NotFoundException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function recordView(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $slug        = (string) ($routeParams['slug'] ?? '');

        try {
            $this->postService->incrementView($slug);

            return $this->success('View recorded.', []);
        } catch (InfrastructureException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
    }

    public function like(array $request): array
    {
        $routeParams = is_array($request['route_params'] ?? null) ? $request['route_params'] : [];
        $slug        = (string) ($routeParams['slug'] ?? '');
        $ip          = (string) ($request['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

        try {
            return $this->success('Like updated.', $this->postService->toggleLike($slug, $ip));
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
