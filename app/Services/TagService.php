<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\ProductTag;
use App\Models\Tag;
use PDO;
use PDOException;

final class TagService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function list(): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, slug, name, guide_title, guide_content, created_at, updated_at FROM ' . Tag::TABLE . ' ORDER BY name ASC'
        );
        $statement->execute();
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->hydrateTag($row);
        }

        return ['items' => $items];
    }

    public function create(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $guideTitle = $this->nullableString($payload['guide_title'] ?? null);
        $guideContent = $this->nullableString($payload['guide_content'] ?? null);

        if ($name === '') {
            throw new ValidationException('Tag name is required.');
        }

        $slug = $this->generateUniqueSlug($name);
        $id = $this->uuidV4();

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'INSERT INTO ' . Tag::TABLE . ' (id, slug, name, guide_title, guide_content) VALUES (:id, :slug, :name, :guide_title, :guide_content)'
            );
            $stmt->execute([
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'guide_title' => $guideTitle,
                'guide_content' => $guideContent,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000' || str_contains((string) $exception->getMessage(), 'Duplicate')) {
                throw new ValidationException('A tag with a similar name already exists.');
            }

            throw new InfrastructureException('Unable to create tag.', 0, $exception);
        }

        return $this->requireTag($id);
    }

    public function update(string $tagId, array $payload): array
    {
        $tag = $this->requireTag($tagId);
        $name = trim((string) ($payload['name'] ?? $tag['name']));
        $guideTitle = array_key_exists('guide_title', $payload)
            ? $this->nullableString($payload['guide_title'])
            : $tag['guide_title'];
        $guideContent = array_key_exists('guide_content', $payload)
            ? $this->nullableString($payload['guide_content'])
            : $tag['guide_content'];

        if ($name === '') {
            throw new ValidationException('Tag name is required.');
        }

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'UPDATE ' . Tag::TABLE . ' SET name = :name, guide_title = :guide_title, guide_content = :guide_content, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute([
                'name' => $name,
                'guide_title' => $guideTitle,
                'guide_content' => $guideContent,
                'id' => $tag['id'],
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to update tag.', 0, $exception);
        }

        return $this->requireTag($tag['id']);
    }

    public function delete(string $tagId): void
    {
        $tag = $this->requireTag($tagId);

        try {
            $stmt = $this->databaseService->connection()->prepare(
                'DELETE FROM ' . Tag::TABLE . ' WHERE id = :id'
            );
            $stmt->execute(['id' => $tag['id']]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to delete tag.', 0, $exception);
        }
    }

    public function syncProductTags(string $productId, array $tagIds, ?PDO $connection = null): void
    {
        $db = $connection ?? $this->databaseService->connection();
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $tagIds),
            static fn (string $id): bool => $id !== ''
        )));

        try {
            $deleteStmt = $db->prepare('DELETE FROM ' . ProductTag::TABLE . ' WHERE product_id = :product_id');
            $deleteStmt->execute(['product_id' => $productId]);

            if ($normalizedIds !== []) {
                $insertStmt = $db->prepare(
                    'INSERT IGNORE INTO ' . ProductTag::TABLE . ' (product_id, tag_id) VALUES (:product_id, :tag_id)'
                );

                foreach ($normalizedIds as $tagId) {
                    $insertStmt->execute(['product_id' => $productId, 'tag_id' => $tagId]);
                }
            }
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to sync product tags.', 0, $exception);
        }
    }

    public function loadTagsForProductIds(array $productIds, ?PDO $connection = null): array
    {
        $normalizedIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $productIds),
            static fn (string $id): bool => $id !== ''
        ));

        if ($normalizedIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($normalizedIds) as $index => $id) {
            $key = 'pid_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $inClause = implode(', ', $placeholders);
        $statement = ($connection ?? $this->databaseService->connection())->prepare(
            'SELECT pt.product_id, t.id, t.slug, t.name, t.guide_title, t.guide_content
             FROM ' . ProductTag::TABLE . ' pt
             INNER JOIN ' . Tag::TABLE . ' t ON t.id = pt.tag_id
             WHERE pt.product_id IN (' . $inClause . ')
             ORDER BY t.name ASC'
        );
        $statement->execute($params);
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (string) ($row['product_id'] ?? '');

            if ($productId === '') {
                continue;
            }

            $grouped[$productId][] = $this->hydrateTag($row);
        }

        return $grouped;
    }

    private function requireTag(string $tagId): array
    {
        $stmt = $this->databaseService->connection()->prepare(
            'SELECT id, slug, name, guide_title, guide_content, created_at, updated_at FROM ' . Tag::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => trim($tagId)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Tag was not found.');
        }

        return $this->hydrateTag($row);
    }

    private function hydrateTag(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'guide_title' => $row['guide_title'] ?? null,
            'guide_content' => $row['guide_content'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function generateUniqueSlug(string $name): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $base = strtolower(trim(is_string($transliterated) ? $transliterated : $name));
        $base = (string) preg_replace('/[^a-z0-9\s-]/', '', $base);
        $base = trim((string) preg_replace('/[\s-]+/', '-', $base), '-');

        if ($base === '') {
            $base = 'tag-' . substr($this->uuidV4(), 0, 8);
        }

        $slug = $base;
        $attempt = 1;

        while (true) {
            $stmt = $this->databaseService->connection()->prepare(
                'SELECT COUNT(*) AS cnt FROM ' . Tag::TABLE . ' WHERE slug = :slug'
            );
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch();
            $count = is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;

            if ($count === 0) {
                break;
            }

            $slug = $base . '-' . $attempt;
            $attempt++;
        }

        return $slug;
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
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
