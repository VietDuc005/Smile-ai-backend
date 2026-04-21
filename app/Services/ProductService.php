<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Product;
use PDOException;

final class ProductService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function listPublic(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $conditions = ['p.is_active = :is_active'];
        $params = [
            'is_active' => 1,
        ];

        if ($search !== '') {
            $conditions[] = '(LOWER(p.name) LIKE :search OR LOWER(COALESCE(p.description, \'\')) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $conditions);
        $total = $this->countProducts($whereClause, $params);
        $statement = $this->databaseService->connection()->prepare(
            $this->baseSelect() .
            ' WHERE ' . $whereClause .
            ' ORDER BY p.created_at DESC, p.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        return [
            'items' => $this->hydrateRows($statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'search' => $search,
            ],
        ];
    }

    public function findPublicById(string $productId): array
    {
        $product = $this->findById($productId);

        if ($product === null || $product['is_active'] !== true) {
            throw new NotFoundException('Product was not found.');
        }

        return $product;
    }

    public function listAdmin(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $statusFilter = $this->normalizeOptionalBoolean($filters['is_active'] ?? null);
        $conditions = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(LOWER(p.name) LIKE :search OR LOWER(COALESCE(p.description, \'\')) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($statusFilter !== null) {
            $conditions[] = 'p.is_active = :is_active';
            $params['is_active'] = $statusFilter ? 1 : 0;
        }

        $whereClause = implode(' AND ', $conditions);
        $total = $this->countProducts($whereClause, $params);
        $statement = $this->databaseService->connection()->prepare(
            $this->baseSelect() .
            ' WHERE ' . $whereClause .
            ' ORDER BY p.created_at DESC, p.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        return [
            'items' => $this->hydrateRows($statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'search' => $search,
                'is_active' => $statusFilter,
            ],
        ];
    }

    public function create(array $payload): array
    {
        $normalized = $this->normalizePayload($payload, false);
        $id = $this->uuidV4();
        $statement = $this->databaseService->connection()->prepare(
            'INSERT INTO ' . Product::TABLE . ' (id, name, description, features, price, duration_days, image_url, requires_inventory, inventory_allocation_mode, requires_customer_email, stock_quantity, is_active) VALUES (:id, :name, :description, :features, :price, :duration_days, :image_url, :requires_inventory, :inventory_allocation_mode, :requires_customer_email, :stock_quantity, :is_active)'
        );

        try {
            $statement->execute([
                'id' => $id,
                'name' => $normalized['name'],
                'description' => $normalized['description'],
                'features' => $this->encodeFeatures($normalized['features']),
                'price' => $normalized['price'],
                'duration_days' => $normalized['duration_days'],
                'image_url' => $normalized['image_url'],
                'requires_inventory' => $normalized['requires_inventory'] ? 1 : 0,
                'inventory_allocation_mode' => $normalized['inventory_allocation_mode'],
                'requires_customer_email' => $normalized['requires_customer_email'] ? 1 : 0,
                'stock_quantity' => $normalized['stock_quantity'],
                'is_active' => $normalized['is_active'] ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to create product.', 0, $exception);
        }

        return $this->requireProduct($id);
    }

    public function update(string $productId, array $payload): array
    {
        $existingProduct = $this->requireProduct($productId);
        $normalized = $this->normalizePayload($payload, true, $existingProduct);
        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . Product::TABLE . ' SET name = :name, description = :description, features = :features, price = :price, duration_days = :duration_days, image_url = :image_url, requires_inventory = :requires_inventory, inventory_allocation_mode = :inventory_allocation_mode, requires_customer_email = :requires_customer_email, stock_quantity = :stock_quantity, is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        try {
            $statement->execute([
                'id' => $existingProduct['id'],
                'name' => $normalized['name'],
                'description' => $normalized['description'],
                'features' => $this->encodeFeatures($normalized['features']),
                'price' => $normalized['price'],
                'duration_days' => $normalized['duration_days'],
                'image_url' => $normalized['image_url'],
                'requires_inventory' => $normalized['requires_inventory'] ? 1 : 0,
                'inventory_allocation_mode' => $normalized['inventory_allocation_mode'],
                'requires_customer_email' => $normalized['requires_customer_email'] ? 1 : 0,
                'stock_quantity' => $normalized['stock_quantity'],
                'is_active' => $normalized['is_active'] ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to update product.', 0, $exception);
        }

        return $this->requireProduct($existingProduct['id']);
    }

    public function delete(string $productId): array
    {
        $product = $this->requireProduct($productId);
        $usage = $this->usageStatistics($product['id']);

        if (($usage['order_items_count'] ?? 0) > 0 || ($usage['digital_accounts_count'] ?? 0) > 0) {
            throw new ValidationException(
                'This product is already linked to orders or inventory. Set is_active=false instead of deleting it.'
            );
        }

        $statement = $this->databaseService->connection()->prepare(
            'DELETE FROM ' . Product::TABLE . ' WHERE id = :id'
        );

        try {
            $statement->execute([
                'id' => $product['id'],
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to delete product.', 0, $exception);
        }

        return [
            'deleted' => true,
            'product' => $product,
        ];
    }

    public function findById(string $productId): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            $this->baseSelect() . ' WHERE p.id = :id LIMIT 1'
        );
        $statement->execute([
            'id' => trim($productId),
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateProduct($row) : null;
    }

    private function requireProduct(string $productId): array
    {
        $product = $this->findById($productId);

        if ($product === null) {
            throw new NotFoundException('Product was not found.');
        }

        return $product;
    }

    private function countProducts(string $whereClause, array $params): int
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . Product::TABLE . ' p WHERE ' . $whereClause
        );
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    private function usageStatistics(string $productId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM Order_Items WHERE product_id = :product_id) AS order_items_count,
                (SELECT COUNT(*) FROM Digital_Accounts WHERE product_id = :product_id) AS digital_accounts_count"
        );
        $statement->execute([
            'product_id' => $productId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'order_items_count' => (int) ($row['order_items_count'] ?? 0),
            'digital_accounts_count' => (int) ($row['digital_accounts_count'] ?? 0),
        ] : [
            'order_items_count' => 0,
            'digital_accounts_count' => 0,
        ];
    }

    private function normalizePayload(array $payload, bool $partial, array $existing = []): array
    {
        $name = $this->valueFromPayload($payload, 'name', $partial, (string) ($existing['name'] ?? ''));
        $description = $this->nullableStringValue($payload, 'description', $partial, $existing['description'] ?? null);
        $features = $this->featuresValue($payload, $partial, $existing['features'] ?? []);
        $price = $this->decimalValue($payload, 'price', $partial, (float) ($existing['price'] ?? 0));
        $durationDays = $this->integerValue($payload, 'duration_days', $partial, (int) ($existing['duration_days'] ?? 0));
        $imageUrl = $this->nullableStringValue($payload, 'image_url', $partial, $existing['image_url'] ?? null);
        $requiresInventory = $this->booleanValue($payload, 'requires_inventory', $partial, (bool) ($existing['requires_inventory'] ?? true));
        $inventoryAllocationMode = $this->inventoryAllocationModeValue(
            $payload,
            $partial,
            (string) ($existing['inventory_allocation_mode'] ?? 'exclusive')
        );
        $requiresCustomerEmail = $this->booleanValue(
            $payload,
            'requires_customer_email',
            $partial,
            (bool) ($existing['requires_customer_email'] ?? false)
        );
        $stockQuantity = $this->integerValue($payload, 'stock_quantity', $partial, (int) ($existing['stock_quantity'] ?? 0));
        $isActive = $this->booleanValue($payload, 'is_active', $partial, (bool) ($existing['is_active'] ?? true));

        if ($name === '') {
            throw new ValidationException('Product name is required.');
        }

        if ($price < 0) {
            throw new ValidationException('Product price must be greater than or equal to 0.');
        }

        if ($durationDays <= 0) {
            throw new ValidationException('Product duration_days must be greater than 0.');
        }

        if ($stockQuantity < 0) {
            throw new ValidationException('Product stock_quantity must be greater than or equal to 0.');
        }

        if ($imageUrl !== null && strlen($imageUrl) > 255) {
            throw new ValidationException('Product image_url is too long.');
        }

        if ($requiresInventory !== true) {
            $inventoryAllocationMode = 'exclusive';
        }

        return [
            'name' => $name,
            'description' => $description,
            'features' => $features,
            'price' => round($price, 2),
            'duration_days' => $durationDays,
            'image_url' => $imageUrl,
            'requires_inventory' => $requiresInventory,
            'inventory_allocation_mode' => $inventoryAllocationMode,
            'requires_customer_email' => $requiresCustomerEmail,
            'stock_quantity' => $stockQuantity,
            'is_active' => $isActive,
        ];
    }

    private function valueFromPayload(array $payload, string $key, bool $partial, string $fallback): string
    {
        if (!array_key_exists($key, $payload)) {
            return $partial ? trim($fallback) : '';
        }

        return trim((string) $payload[$key]);
    }

    private function nullableStringValue(array $payload, string $key, bool $partial, mixed $fallback): ?string
    {
        if (!array_key_exists($key, $payload)) {
            $value = $partial ? $fallback : null;
        } else {
            $value = $payload[$key];
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function integerValue(array $payload, string $key, bool $partial, int $fallback): int
    {
        if (!array_key_exists($key, $payload)) {
            return $partial ? $fallback : 0;
        }

        if (!is_numeric($payload[$key])) {
            throw new ValidationException(sprintf('Field %s must be numeric.', $key));
        }

        return (int) $payload[$key];
    }

    private function decimalValue(array $payload, string $key, bool $partial, float $fallback): float
    {
        if (!array_key_exists($key, $payload)) {
            return $partial ? $fallback : 0.0;
        }

        if (!is_numeric($payload[$key])) {
            throw new ValidationException(sprintf('Field %s must be numeric.', $key));
        }

        return (float) $payload[$key];
    }

    private function booleanValue(array $payload, string $key, bool $partial, bool $fallback): bool
    {
        if (!array_key_exists($key, $payload)) {
            return $fallback;
        }

        $normalized = $this->normalizeOptionalBoolean($payload[$key]);

        if ($normalized === null) {
            throw new ValidationException(sprintf('Field %s must be boolean.', $key));
        }

        return $normalized;
    }

    private function inventoryAllocationModeValue(array $payload, bool $partial, string $fallback): string
    {
        if (!array_key_exists('inventory_allocation_mode', $payload)) {
            return $partial ? $fallback : 'exclusive';
        }

        $value = strtolower(trim((string) $payload['inventory_allocation_mode']));

        if (!in_array($value, ['exclusive', 'shared'], true)) {
            throw new ValidationException('Field inventory_allocation_mode must be either exclusive or shared.');
        }

        return $value;
    }

    private function featuresValue(array $payload, bool $partial, array $fallback): array
    {
        if (!array_key_exists('features', $payload)) {
            return $partial ? $fallback : [];
        }

        return $this->normalizeFeatures($payload['features']);
    }

    private function normalizeFeatures(mixed $features): array
    {
        if ($features === null) {
            return [];
        }

        if (is_string($features)) {
            $trimmed = trim($features);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $this->sanitizeFeatureList($decoded);
            }

            if (str_contains($trimmed, PHP_EOL)) {
                return $this->sanitizeFeatureList(preg_split('/\r\n|\r|\n/', $trimmed) ?: []);
            }

            if (str_contains($trimmed, ',')) {
                return $this->sanitizeFeatureList(array_map('trim', explode(',', $trimmed)));
            }

            return [$trimmed];
        }

        if (is_array($features)) {
            return $this->sanitizeFeatureList($features);
        }

        throw new ValidationException('Field features must be an array or string.');
    }

    private function sanitizeFeatureList(array $features): array
    {
        $normalized = [];

        foreach ($features as $feature) {
            $value = trim((string) $feature);

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function encodeFeatures(array $features): ?string
    {
        if ($features === []) {
            return null;
        }

        $encoded = json_encode(array_values($features), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function hydrateRows(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->hydrateProduct($row);
        }

        return $items;
    }

    private function hydrateProduct(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'features' => $this->decodeFeatures($row['features'] ?? null),
            'price' => (float) ($row['price'] ?? 0),
            'duration_days' => (int) ($row['duration_days'] ?? 0),
            'image_url' => $row['image_url'] ?? null,
            'requires_inventory' => $this->databaseBoolean($row['requires_inventory'] ?? true),
            'inventory_allocation_mode' => (string) ($row['inventory_allocation_mode'] ?? 'exclusive'),
            'requires_customer_email' => $this->databaseBoolean($row['requires_customer_email'] ?? false),
            'stock_quantity' => max(0, (int) ($row['stock_quantity'] ?? 0)),
            'is_active' => $this->databaseBoolean($row['is_active'] ?? false),
            'available_inventory' => (int) ($row['available_inventory'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function decodeFeatures(mixed $features): array
    {
        if (!is_string($features) || trim($features) === '') {
            return [];
        }

        $decoded = json_decode($features, true);

        return is_array($decoded) ? $this->sanitizeFeatureList($decoded) : [];
    }

    private function normalizeOptionalBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'active' => true,
            '0', 'false', 'no', 'inactive' => false,
            default => null,
        };
    }

    private function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 't', 'yes'], true);
    }

    private function baseSelect(): string
    {
        return 'SELECT p.id, p.name, p.description, p.features, p.price, p.duration_days, p.image_url, p.requires_inventory, p.inventory_allocation_mode, p.requires_customer_email, p.stock_quantity, p.is_active, p.created_at, p.updated_at, ' .
            "CASE WHEN COALESCE(p.requires_inventory, 1) = 1 " .
            "THEN (SELECT COALESCE(SUM(CASE " .
            "WHEN COALESCE(p.inventory_allocation_mode, 'exclusive') = 'shared' " .
            "THEN CASE " .
            "WHEN da.status = 'banned' THEN 0 " .
            "WHEN da.expires_at IS NOT NULL AND da.expires_at < DATE_ADD(CURRENT_TIMESTAMP, INTERVAL GREATEST(COALESCE(p.duration_days, 0), 30) DAY) THEN 0 " .
            "ELSE GREATEST(COALESCE(da.seat_capacity, 1) - COALESCE(da.seat_used, 0), 0) END " .
            "ELSE CASE " .
            "WHEN da.status = 'available' " .
            "AND (da.expires_at IS NULL OR da.expires_at >= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL GREATEST(COALESCE(p.duration_days, 0), 30) DAY)) " .
            "THEN 1 ELSE 0 END " .
            "END), 0) FROM Digital_Accounts da WHERE da.product_id = p.id) " .
            "ELSE GREATEST(COALESCE(p.stock_quantity, 0), 0) END AS available_inventory " .
            'FROM ' . Product::TABLE . ' p';
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
