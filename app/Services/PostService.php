<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Post;
use App\Models\PostLike;
use PDOException;

final class PostService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function listPublished(int $page = 1, int $limit = 12): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;

        $countStmt = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM ' . Post::TABLE . " WHERE status = 'published'"
        );
        $countStmt->execute();
        $countRow = $countStmt->fetch();
        $total = is_array($countRow) ? (int) ($countRow['cnt'] ?? 0) : 0;

        $stmt = $this->databaseService->connection()->prepare(
            'SELECT id, slug, title, excerpt, cover_image, views, likes, status, published_at, created_at, updated_at
             FROM ' . Post::TABLE . "
             WHERE status = 'published'
             ORDER BY published_at DESC, created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrateList($row);
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function listAll(int $page = 1, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $countStmt = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM ' . Post::TABLE
        );
        $countStmt->execute();
        $countRow = $countStmt->fetch();
        $total = is_array($countRow) ? (int) ($countRow['cnt'] ?? 0) : 0;

        $stmt = $this->databaseService->connection()->prepare(
            'SELECT id, slug, title, excerpt, cover_image, views, likes, status, published_at, created_at, updated_at
             FROM ' . Post::TABLE . '
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrateList($row);
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findBySlugPublished(string $slug): array
    {
        $stmt = $this->databaseService->connection()->prepare(
            'SELECT id, slug, title, excerpt, content, cover_image, views, likes, status, published_at, created_at, updated_at
             FROM ' . Post::TABLE . "
             WHERE slug = :slug AND status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['slug' => trim($slug)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Bài viết không tồn tại.');
        }

        return $this->hydrateFull($row);
    }

    public function incrementView(string $slug): void
    {
        try {
            $stmt = $this->databaseService->connection()->prepare(
                "UPDATE " . Post::TABLE . " SET views = views + 1 WHERE slug = :slug AND status = 'published'"
            );
            $stmt->execute(['slug' => trim($slug)]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Không thể ghi nhận lượt xem.', 0, $exception);
        }
    }

    public function toggleLike(string $slug, string $rawIp): array
    {
        $post = $this->findBySlugPublished($slug);
        $ipHash = hash('sha256', $rawIp);

        $checkStmt = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM ' . PostLike::TABLE . ' WHERE post_id = :post_id AND ip_hash = :ip_hash'
        );
        $checkStmt->execute(['post_id' => $post['id'], 'ip_hash' => $ipHash]);
        $checkRow = $checkStmt->fetch();
        $alreadyLiked = is_array($checkRow) && (int) ($checkRow['cnt'] ?? 0) > 0;

        try {
            if ($alreadyLiked) {
                $this->databaseService->connection()->prepare(
                    'DELETE FROM ' . PostLike::TABLE . ' WHERE post_id = :post_id AND ip_hash = :ip_hash'
                )->execute(['post_id' => $post['id'], 'ip_hash' => $ipHash]);

                $this->databaseService->connection()->prepare(
                    'UPDATE ' . Post::TABLE . ' SET likes = GREATEST(0, likes - 1) WHERE id = :id'
                )->execute(['id' => $post['id']]);
            } else {
                $this->databaseService->connection()->prepare(
                    'INSERT IGNORE INTO ' . PostLike::TABLE . ' (post_id, ip_hash) VALUES (:post_id, :ip_hash)'
                )->execute(['post_id' => $post['id'], 'ip_hash' => $ipHash]);

                $this->databaseService->connection()->prepare(
                    'UPDATE ' . Post::TABLE . ' SET likes = likes + 1 WHERE id = :id'
                )->execute(['id' => $post['id']]);
            }
        } catch (PDOException $exception) {
            throw new InfrastructureException('Không thể cập nhật lượt thích.', 0, $exception);
        }

        return [
            'liked'  => !$alreadyLiked,
            'likes'  => $this->getCurrentLikes($post['id']),
        ];
    }

    public function findById(string $id): array
    {
        return $this->requirePost($id);
    }

    public function create(array $payload): array
    {
        $title      = trim((string) ($payload['title'] ?? ''));
        $content    = trim((string) ($payload['content'] ?? ''));
        $excerpt    = $this->nullableString($payload['excerpt'] ?? null);
        $coverImage = $this->nullableString($payload['cover_image'] ?? null);
        $status     = in_array($payload['status'] ?? '', ['draft', 'published'], true)
            ? (string) $payload['status']
            : 'draft';

        if ($title === '') {
            throw new ValidationException('Tiêu đề bài viết không được để trống.');
        }

        if ($content === '') {
            throw new ValidationException('Nội dung bài viết không được để trống.');
        }

        $slug        = $this->generateUniqueSlug($title);
        $id          = $this->uuidV4();
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'INSERT INTO ' . Post::TABLE . '
                 (id, slug, title, excerpt, content, cover_image, status, published_at)
                 VALUES (:id, :slug, :title, :excerpt, :content, :cover_image, :status, :published_at)'
            );
            $stmt->execute([
                'id'          => $id,
                'slug'        => $slug,
                'title'       => $title,
                'excerpt'     => $excerpt,
                'content'     => $content,
                'cover_image' => $coverImage,
                'status'      => $status,
                'published_at' => $publishedAt,
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Không thể tạo bài viết.', 0, $exception);
        }

        return $this->requirePost($id);
    }

    public function update(string $postId, array $payload): array
    {
        $post = $this->requirePost($postId);

        $title      = array_key_exists('title', $payload)
            ? trim((string) $payload['title'])
            : $post['title'];
        $content    = array_key_exists('content', $payload)
            ? trim((string) $payload['content'])
            : $post['content'];
        $excerpt    = array_key_exists('excerpt', $payload)
            ? $this->nullableString($payload['excerpt'])
            : $post['excerpt'];
        $coverImage = array_key_exists('cover_image', $payload)
            ? $this->nullableString($payload['cover_image'])
            : $post['cover_image'];
        $status     = array_key_exists('status', $payload) && in_array($payload['status'], ['draft', 'published'], true)
            ? (string) $payload['status']
            : $post['status'];

        if ($title === '') {
            throw new ValidationException('Tiêu đề bài viết không được để trống.');
        }

        if ($content === '') {
            throw new ValidationException('Nội dung bài viết không được để trống.');
        }

        // Set published_at when publishing for the first time
        $publishedAt = $post['published_at'];
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'UPDATE ' . Post::TABLE . '
                 SET title = :title, excerpt = :excerpt, content = :content,
                     cover_image = :cover_image, status = :status, published_at = :published_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                'title'       => $title,
                'excerpt'     => $excerpt,
                'content'     => $content,
                'cover_image' => $coverImage,
                'status'      => $status,
                'published_at' => $publishedAt,
                'id'          => $post['id'],
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Không thể cập nhật bài viết.', 0, $exception);
        }

        return $this->requirePost($post['id']);
    }

    public function delete(string $postId): void
    {
        $post = $this->requirePost($postId);

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'DELETE FROM ' . Post::TABLE . ' WHERE id = :id'
            );
            $stmt->execute(['id' => $post['id']]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Không thể xóa bài viết.', 0, $exception);
        }
    }

    private function getCurrentLikes(string $postId): int
    {
        $stmt = $this->databaseService->connection()->prepare(
            'SELECT likes FROM ' . Post::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $postId]);
        $row = $stmt->fetch();

        return is_array($row) ? (int) ($row['likes'] ?? 0) : 0;
    }

    private function requirePost(string $postId): array
    {
        $stmt = $this->databaseService->connection()->prepare(
            'SELECT id, slug, title, excerpt, content, cover_image, views, likes, status, published_at, created_at, updated_at
             FROM ' . Post::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => trim($postId)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Bài viết không tồn tại.');
        }

        return $this->hydrateFull($row);
    }

    private function hydrateList(array $row): array
    {
        return [
            'id'          => (string) ($row['id'] ?? ''),
            'slug'        => (string) ($row['slug'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'excerpt'     => $row['excerpt'] ?? null,
            'cover_image' => $row['cover_image'] ?? null,
            'views'       => (int) ($row['views'] ?? 0),
            'likes'       => (int) ($row['likes'] ?? 0),
            'status'      => (string) ($row['status'] ?? 'draft'),
            'published_at' => $row['published_at'] ?? null,
            'created_at'  => $row['created_at'] ?? null,
            'updated_at'  => $row['updated_at'] ?? null,
        ];
    }

    private function hydrateFull(array $row): array
    {
        return array_merge($this->hydrateList($row), [
            'content' => (string) ($row['content'] ?? ''),
        ]);
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = $this->slugify($title);

        if ($base === '') {
            $base = 'bai-viet-' . substr($this->uuidV4(), 0, 8);
        }

        $slug    = $base;
        $attempt = 1;

        while (true) {
            $stmt = $this->databaseService->connection()->prepare(
                'SELECT COUNT(*) AS cnt FROM ' . Post::TABLE . ' WHERE slug = :slug'
            );
            $stmt->execute(['slug' => $slug]);
            $row   = $stmt->fetch();
            $count = is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;

            if ($count === 0) {
                break;
            }

            $slug = $base . '-' . $attempt;
            $attempt++;
        }

        return $slug;
    }

    private function slugify(string $text): string
    {
        $vietnamese = [
            'à','á','ả','ã','ạ','â','ầ','ấ','ẩ','ẫ','ậ','ă','ằ','ắ','ẳ','ẵ','ặ',
            'è','é','ẻ','ẽ','ẹ','ê','ề','ế','ể','ễ','ệ',
            'ì','í','ỉ','ĩ','ị',
            'ò','ó','ỏ','õ','ọ','ô','ồ','ố','ổ','ỗ','ộ','ơ','ờ','ớ','ở','ỡ','ợ',
            'ù','ú','ủ','ũ','ụ','ư','ừ','ứ','ử','ữ','ự',
            'ỳ','ý','ỷ','ỹ','ỵ','đ',
            'À','Á','Ả','Ã','Ạ','Â','Ầ','Ấ','Ẩ','Ẫ','Ậ','Ă','Ằ','Ắ','Ẳ','Ẵ','Ặ',
            'È','É','Ẻ','Ẽ','Ẹ','Ê','Ề','Ế','Ể','Ễ','Ệ',
            'Ì','Í','Ỉ','Ĩ','Ị',
            'Ò','Ó','Ỏ','Õ','Ọ','Ô','Ồ','Ố','Ổ','Ỗ','Ộ','Ơ','Ờ','Ớ','Ở','Ỡ','Ợ',
            'Ù','Ú','Ủ','Ũ','Ụ','Ư','Ừ','Ứ','Ử','Ữ','Ự',
            'Ỳ','Ý','Ỷ','Ỹ','Ỵ','Đ',
        ];
        $latin = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y','d',
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y','d',
        ];

        $result = str_replace($vietnamese, $latin, $text);
        $result = strtolower(trim($result));
        $result = (string) preg_replace('/[^a-z0-9\s-]/', '', $result);
        $result = (string) preg_replace('/[\s-]+/', '-', $result);

        return trim($result, '-');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function uuidV4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
