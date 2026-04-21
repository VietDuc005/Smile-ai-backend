<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DigitalAccount;
use App\Models\Product;
use DateTimeImmutable;
use PDO;
use PDOException;

final class InventoryService
{
    private const LOW_EXPIRY_DAYS = 30;

    private const LOW_EXPIRY_PREVIEW_LIMIT = 8;

    private DatabaseService $databaseService;

    private EncryptionService $encryptionService;

    private ProductService $productService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?EncryptionService $encryptionService = null,
        ?ProductService $productService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->productService = $productService ?? new ProductService();
    }

    public function importAccounts(array $payload): array
    {
        $productId = trim((string) ($payload['product_id'] ?? ''));

        if ($productId === '') {
            throw new ValidationException('Field product_id is required.');
        }

        $product = $this->productService->findById($productId) ?? throw new NotFoundException('Product was not found.');

        if (($product['requires_inventory'] ?? true) !== true) {
            throw new ValidationException('This product uses quantity-based stock and does not accept account inventory imports.');
        }

        $defaultSeatCapacity = $this->normalizeDefaultSeatCapacity($payload['default_seat_capacity'] ?? null);
        $defaultExpiryDays = $this->normalizeDefaultExpiryDays($payload['default_expiry_days'] ?? null);
        $sharedMode = $this->shouldUseSharedAllocation($product, $payload['accounts'] ?? null, $defaultSeatCapacity);
        $accounts = $this->normalizeAccounts($payload['accounts'] ?? null, $sharedMode, $defaultSeatCapacity, $defaultExpiryDays);

        if ($accounts === []) {
            throw new ValidationException('At least one account is required for import.');
        }

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            if ($sharedMode && $this->productUsesSharedAllocation($product) !== true) {
                $this->promoteProductToSharedAllocation($productId, $connection);
            }

            $statement = $connection->prepare(
                'INSERT INTO ' . DigitalAccount::TABLE . ' (id, product_id, username, password, status, expires_at, seat_capacity, seat_used) VALUES (:id, :product_id, :username, :password, :status, :expires_at, :seat_capacity, :seat_used)'
            );
            $imported = [];

            foreach ($accounts as $account) {
                $id = $this->uuidV4();
                $status = $this->normalizeStatus($account['status'] ?? 'available');
                $encryptedPassword = $this->encryptionService->encrypt($account['password']);

                $statement->execute([
                    'id' => $id,
                    'product_id' => $productId,
                    'username' => $account['username'],
                    'password' => $encryptedPassword,
                    'status' => $status,
                    'expires_at' => $account['expires_at'],
                    'seat_capacity' => $account['seat_capacity'],
                    'seat_used' => $account['seat_used'],
                ]);

                $imported[] = [
                    'id' => $id,
                    'username' => $account['username'],
                    'status' => $status,
                    'expires_at' => $account['expires_at'],
                    'seat_capacity' => $account['seat_capacity'],
                    'seat_used' => $account['seat_used'],
                ];
            }

            $connection->commit();

            return [
                'product_id' => $productId,
                'imported_count' => count($imported),
                'imported_accounts' => $imported,
                'availability' => $this->availabilityByProduct($productId),
            ];
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to import inventory accounts.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }
    }

    public function listByProduct(string $productId, array $filters = []): array
    {
        $normalizedProductId = trim($productId);

        if ($normalizedProductId === '') {
            throw new ValidationException('Product id is required.');
        }

        $product = $this->productService->findById($normalizedProductId) ?? throw new NotFoundException('Product was not found.');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $includePassword = $this->normalizeBoolean($filters['include_password'] ?? false);
        $conditions = [
            'product_id = :product_id',
            'NOT (expires_at IS NOT NULL AND expires_at < :low_expiry_until)',
        ];
        $params = [
            'product_id' => $normalizedProductId,
            'low_expiry_until' => $this->lowExpiryThreshold(),
        ];

        if ($statusFilter !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $this->normalizeStatus($statusFilter);
        }

        if ($search !== '') {
            $conditions[] = 'LOWER(username) LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $conditions);
        $requiredUntil = $this->requiredUntilForProduct($product);
        $lowExpiryUntil = $this->lowExpiryThreshold();
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . DigitalAccount::TABLE . ' WHERE ' . $whereClause
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT id, product_id, username, password, status, expires_at, seat_capacity, seat_used,
                    GREATEST(COALESCE(seat_capacity, 1) - COALESCE(seat_used, 0), 0) AS remaining_seats,
                    CASE WHEN expires_at IS NULL OR expires_at >= :required_until THEN 1 ELSE 0 END AS has_sufficient_duration,
                    CASE WHEN expires_at IS NOT NULL AND expires_at < :low_expiry_until_preview THEN 1 ELSE 0 END AS is_low_expiry,
                    CASE WHEN expires_at IS NULL THEN NULL ELSE GREATEST(TIMESTAMPDIFF(DAY, CURRENT_TIMESTAMP, expires_at), 0) END AS days_remaining,
                    added_at, updated_at FROM ' . DigitalAccount::TABLE .
            ' WHERE ' . $whereClause .
            ' ORDER BY added_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute(array_merge($params, [
            'required_until' => $requiredUntil,
            'low_expiry_until_preview' => $lowExpiryUntil,
        ]));

        $items = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->hydrateAccount($row, $includePassword);
        }

        return [
            'product_id' => $normalizedProductId,
            'items' => $items,
            'low_expiry_items' => $this->listLowExpiryAccounts($normalizedProductId, $product, $includePassword),
            'summary' => $this->summaryByProduct($normalizedProductId),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'status' => $statusFilter !== '' ? $statusFilter : null,
                'search' => $search,
                'include_password' => $includePassword,
            ],
        ];
    }

    public function updateStatus(string $accountId, string $status): array
    {
        $account = $this->requireAccount($accountId);
        $normalizedStatus = $this->normalizeStatus($status);

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . DigitalAccount::TABLE . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        try {
            $statement->execute([
                'status' => $normalizedStatus,
                'id' => $account['id'],
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to update inventory status.', 0, $exception);
        }

        return $this->requireAccount($account['id']);
    }

    public function setTotpSecret(string $accountId, ?string $rawBase32Secret): array
    {
        $account = $this->requireAccount($accountId);
        $totpService = new TotpService($this->encryptionService);
        $encryptedSecret = null;

        if ($rawBase32Secret !== null && trim($rawBase32Secret) !== '') {
            $encryptedSecret = $totpService->encryptSecret(trim($rawBase32Secret));
        }

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . DigitalAccount::TABLE . ' SET totp_secret = :totp_secret, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        try {
            $statement->execute([
                'totp_secret' => $encryptedSecret,
                'id' => $account['id'],
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to update TOTP secret.', 0, $exception);
        }

        return $this->requireAccount($account['id']);
    }

    public function updateAccount(string $accountId, array $payload): array
    {
        $account = $this->requireAccount($accountId);
        $product = $this->productService->findById((string) ($account['product_id'] ?? '')) ?? throw new NotFoundException('Product was not found.');

        $seatCapacity = $this->normalizeSeatCapacity($payload['seat_capacity'] ?? null);
        $seatUsed = max(0, (int) ($account['seat_used'] ?? 0));

        if ($seatCapacity < $seatUsed) {
            throw new ValidationException(sprintf(
                'seat_capacity must be greater than or equal to current seat_used (%d).',
                $seatUsed
            ));
        }

        $nextStatus = (string) ($account['status'] ?? 'available');

        if ($nextStatus !== 'banned') {
            $nextStatus = $seatUsed >= $seatCapacity ? 'sold' : 'available';
        }

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            if ($seatCapacity > 1 && $this->productUsesSharedAllocation($product) !== true) {
                $this->promoteProductToSharedAllocation((string) ($account['product_id'] ?? ''), $connection);
            }

            $statement = $connection->prepare(
                'UPDATE ' . DigitalAccount::TABLE . ' SET seat_capacity = :seat_capacity, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'seat_capacity' => $seatCapacity,
                'status' => $nextStatus,
                'id' => $account['id'],
            ]);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to update inventory account.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        return $this->requireAccount($account['id']);
    }

    public function release(string $accountId): array
    {
        return $this->updateStatus($accountId, 'available');
    }

    public function availabilityByProduct(string $productId): array
    {
        $normalizedProductId = trim($productId);

        if ($normalizedProductId === '') {
            throw new ValidationException('Product id is required.');
        }

        $product = $this->productService->findById($normalizedProductId);

        if ($product === null) {
            throw new NotFoundException('Product was not found.');
        }

        if (($product['requires_inventory'] ?? true) !== true) {
            $availableCount = max(0, (int) ($product['stock_quantity'] ?? 0));

            return [
                'product_id' => $normalizedProductId,
                'product_name' => $product['name'] ?? null,
                'inventory_allocation_mode' => 'manual',
                'total_accounts' => $availableCount,
                'available_accounts' => $availableCount,
                'sold_accounts' => 0,
                'banned_accounts' => 0,
                'total_seats' => $availableCount,
                'used_seats' => 0,
                'available_seats' => $availableCount,
                'insufficient_duration_accounts' => 0,
                'low_expiry_accounts' => 0,
                'has_available_stock' => $availableCount > 0,
            ];
        }

        $sharedMode = $this->productUsesSharedAllocation($product);
        $requiredUntil = $this->requiredUntilForProduct($product);
        $lowExpiryUntil = $this->lowExpiryThreshold();
        $statement = $this->databaseService->connection()->prepare(
            "SELECT
                COUNT(*) AS total_accounts,
                COALESCE(SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END), 0) AS sold_accounts,
                COALESCE(SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END), 0) AS banned_accounts
                , COALESCE(SUM(COALESCE(seat_capacity, 1)), 0) AS total_seats
                , COALESCE(SUM(LEAST(COALESCE(seat_used, 0), COALESCE(seat_capacity, 1))), 0) AS used_seats
                , COALESCE(SUM(CASE
                    WHEN status = 'banned' THEN 0
                    WHEN expires_at IS NOT NULL AND expires_at < :required_until_available THEN 0
                    WHEN :shared_mode = 1 THEN GREATEST(COALESCE(seat_capacity, 1) - COALESCE(seat_used, 0), 0)
                    WHEN status = 'available' THEN 1
                    ELSE 0
                END), 0) AS available_units
                , COALESCE(SUM(CASE
                    WHEN status <> 'banned' AND expires_at IS NOT NULL AND expires_at < :required_until_insufficient THEN 1
                    ELSE 0
                END), 0) AS insufficient_duration_accounts
                , COALESCE(SUM(CASE
                    WHEN expires_at IS NOT NULL AND expires_at < :low_expiry_until THEN 1
                    ELSE 0
                END), 0) AS low_expiry_accounts
             FROM " . DigitalAccount::TABLE . '
             WHERE product_id = :product_id'
        );
        $statement->execute([
            'product_id' => $normalizedProductId,
            'required_until_available' => $requiredUntil,
            'required_until_insufficient' => $requiredUntil,
            'low_expiry_until' => $lowExpiryUntil,
            'shared_mode' => $sharedMode ? 1 : 0,
        ]);
        $row = $statement->fetch();
        $row = is_array($row) ? $row : [];
        $availableCount = (int) ($row['available_units'] ?? 0);

        return [
            'product_id' => $normalizedProductId,
            'product_name' => $product['name'] ?? null,
            'inventory_allocation_mode' => $sharedMode ? 'shared' : 'exclusive',
            'total_accounts' => (int) ($row['total_accounts'] ?? 0),
            'available_accounts' => $availableCount,
            'sold_accounts' => (int) ($row['sold_accounts'] ?? 0),
            'banned_accounts' => (int) ($row['banned_accounts'] ?? 0),
            'total_seats' => (int) ($row['total_seats'] ?? 0),
            'used_seats' => (int) ($row['used_seats'] ?? 0),
            'available_seats' => $availableCount,
            'insufficient_duration_accounts' => (int) ($row['insufficient_duration_accounts'] ?? 0),
            'low_expiry_accounts' => (int) ($row['low_expiry_accounts'] ?? 0),
            'has_available_stock' => $availableCount > 0,
        ];
    }

    public function ensureAvailableStock(string $productId, int $requiredQuantity = 1): void
    {
        if ($requiredQuantity <= 0) {
            throw new ValidationException('Required stock quantity must be greater than 0.');
        }

        $availability = $this->availabilityByProduct($productId);

        if (($availability['available_accounts'] ?? 0) >= $requiredQuantity) {
            return;
        }

        throw new ValidationException(sprintf(
            'This product does not have enough stock. Required: %d, available: %d.',
            $requiredQuantity,
            (int) ($availability['available_accounts'] ?? 0)
        ));
    }

    public function decrementManualStock(string $productId, int $quantity, ?PDO $connection = null): void
    {
        $normalizedProductId = trim($productId);

        if ($normalizedProductId === '') {
            throw new ValidationException('Product id is required.');
        }

        if ($quantity <= 0) {
            throw new ValidationException('Required stock quantity must be greater than 0.');
        }

        $db = $connection ?? $this->databaseService->connection();
        $statement = $db->prepare(
            'UPDATE Products SET stock_quantity = stock_quantity - :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND COALESCE(requires_inventory, 1) = 0 AND stock_quantity >= :quantity_check'
        );
        $statement->execute([
            'id' => $normalizedProductId,
            'quantity' => $quantity,
            'quantity_check' => $quantity,
        ]);

        if ($statement->rowCount() > 0) {
            return;
        }

        $product = $this->productService->findById($normalizedProductId);

        if ($product === null) {
            throw new NotFoundException('Product was not found.');
        }

        if (($product['requires_inventory'] ?? true) === true) {
            throw new ValidationException('This product uses account inventory management.');
        }

        throw new ValidationException(sprintf(
            'This product does not have enough stock. Required: %d, available: %d.',
            $quantity,
            max(0, (int) ($product['stock_quantity'] ?? 0))
        ));
    }

    private function summaryByProduct(string $productId): array
    {
        return $this->availabilityByProduct($productId);
    }

    private function requireAccount(string $accountId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, product_id, username, password, status, expires_at, seat_capacity, seat_used, added_at, updated_at FROM ' . DigitalAccount::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $statement->execute([
            'id' => trim($accountId),
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Digital account was not found.');
        }

        $product = $this->productService->findById((string) ($row['product_id'] ?? ''));
        $threshold = $product !== null ? $this->requiredUntilForProduct($product) : null;
        $lowExpiryUntil = $this->lowExpiryThreshold();

        return $this->hydrateAccount(
            array_merge($row, [
                'remaining_seats' => max(0, (int) ($row['seat_capacity'] ?? 1) - (int) ($row['seat_used'] ?? 0)),
                'has_sufficient_duration' => $this->hasSufficientDurationValue($row['expires_at'] ?? null, $threshold),
                'is_low_expiry' => $this->isLowExpiryValue($row['expires_at'] ?? null, $lowExpiryUntil),
                'days_remaining' => $this->daysRemainingValue($row['expires_at'] ?? null),
            ]),
            false
        );
    }

    private function normalizeAccounts(mixed $accounts, bool $sharedMode, int $defaultSeatCapacity, ?int $defaultExpiryDays): array
    {
        if (is_string($accounts)) {
            $accounts = $this->parseAccountsFromString($accounts);
        }

        if (!is_array($accounts)) {
            throw new ValidationException('Field accounts must be an array or multiline string.');
        }

        $normalized = [];

        foreach ($accounts as $account) {
            if (is_string($account)) {
                $account = $this->parseAccountLine($account);
            }

            if (!is_array($account)) {
                throw new ValidationException('Each inventory row must contain username and password.');
            }

            $username = trim((string) ($account['username'] ?? ''));
            $password = (string) ($account['password'] ?? '');
            $status = $account['status'] ?? 'available';
            $expiresAt = $this->normalizeNullableDateTime($account['expires_at'] ?? null);
            if ($expiresAt === null && $defaultExpiryDays !== null) {
                $expiresAt = $this->expiresAtFromDays($defaultExpiryDays);
            }
            $rawSeatCapacity = $account['seat_capacity'] ?? null;
            $seatCapacity = $rawSeatCapacity === null || $rawSeatCapacity === ''
                ? $defaultSeatCapacity
                : max(1, (int) $rawSeatCapacity);
            $seatUsed = max(0, (int) ($account['seat_used'] ?? 0));

            if ($username === '') {
                throw new ValidationException('Inventory username is required.');
            }

            if ($password === '') {
                throw new ValidationException('Inventory password is required.');
            }

            if ($expiresAt === null) {
                throw new ValidationException('Inventory expires_at or default_expiry_days is required.');
            }

            if ($seatUsed > $seatCapacity) {
                throw new ValidationException('Inventory seat_used cannot be greater than seat_capacity.');
            }

            if ($sharedMode !== true) {
                $seatCapacity = 1;
                $seatUsed = $this->normalizeStatus((string) $status) === 'sold' ? 1 : 0;
            }

            $normalizedStatus = $this->normalizeStatus((string) $status);

            if ($sharedMode === true && $normalizedStatus !== 'banned') {
                $normalizedStatus = $seatUsed >= $seatCapacity ? 'sold' : 'available';
            }

            $normalized[] = [
                'username' => $username,
                'password' => $password,
                'status' => $normalizedStatus,
                'expires_at' => $expiresAt,
                'seat_capacity' => $seatCapacity,
                'seat_used' => $seatUsed,
            ];
        }

        return $normalized;
    }

    private function parseAccountsFromString(string $accounts): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($accounts)) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $items[] = $this->parseAccountLine($trimmed);
        }

        return $items;
    }

    private function parseAccountLine(string $line): array
    {
        foreach (['|', ',', ';', "\t", ':'] as $separator) {
            if (!str_contains($line, $separator)) {
                continue;
            }

            $parts = array_map(
                static fn (string $value): string => trim($value),
                explode($separator, $line, 6)
            );

            if (count($parts) >= 2) {
                $third = $parts[2] ?? null;
                $fourth = $parts[3] ?? null;
                $fifth = $parts[4] ?? null;
                $sixth = $parts[5] ?? null;
                $thirdIsStatus = $third !== null && in_array(strtolower($third), DigitalAccount::statuses(), true);
                $thirdIsSeatCapacity = $third !== null && $thirdIsStatus !== true && is_numeric($third);
                $expiresAt = null;
                $seatCapacity = null;
                $seatUsed = 0;
                $status = 'available';

                if ($thirdIsStatus) {
                    $status = $third;
                } elseif ($thirdIsSeatCapacity) {
                    $seatCapacity = (int) $third;

                    if ($fourth !== null && $fourth !== '') {
                        if (in_array(strtolower($fourth), DigitalAccount::statuses(), true)) {
                            $status = $fourth;
                        } elseif (is_numeric($fourth)) {
                            $seatUsed = (int) $fourth;
                        }
                    }

                    if ($fifth !== null && $fifth !== '') {
                        if (in_array(strtolower($fifth), DigitalAccount::statuses(), true)) {
                            $status = $fifth;
                        } elseif (is_numeric($fifth)) {
                            $seatUsed = (int) $fifth;
                        }
                    }
                } else {
                    $expiresAt = $third;

                    if ($fourth !== null && $fourth !== '') {
                        $seatCapacity = (int) $fourth;
                    }

                    if ($fifth !== null && $fifth !== '') {
                        if (in_array(strtolower($fifth), DigitalAccount::statuses(), true)) {
                            $status = $fifth;
                        } elseif (is_numeric($fifth)) {
                            $seatUsed = (int) $fifth;
                        }
                    }

                    if ($sixth !== null && $sixth !== '') {
                        $status = $sixth;
                    }
                }

                return [
                    'username' => (string) $parts[0],
                    'password' => (string) $parts[1],
                    'expires_at' => $expiresAt,
                    'seat_capacity' => $seatCapacity,
                    'seat_used' => $seatUsed,
                    'status' => $status,
                ];
            }
        }

        throw new ValidationException('Inventory line format is invalid. Use username|password, username|password|status or username|password|expires_at|seat_capacity|seat_used|status.');
    }

    private function normalizeDefaultSeatCapacity(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 1;
        }

        if (!is_numeric($value)) {
            throw new ValidationException('Field default_seat_capacity must be numeric.');
        }

        $normalized = (int) $value;

        if ($normalized <= 0) {
            throw new ValidationException('Field default_seat_capacity must be greater than 0.');
        }

        return $normalized;
    }

    private function shouldUseSharedAllocation(array $product, mixed $accounts, int $defaultSeatCapacity): bool
    {
        if ($this->productUsesSharedAllocation($product)) {
            return true;
        }

        if (($product['requires_inventory'] ?? true) !== true) {
            return false;
        }

        if ($defaultSeatCapacity > 1) {
            return true;
        }

        if (is_string($accounts)) {
            $accounts = $this->parseAccountsFromString($accounts);
        }

        if (!is_array($accounts)) {
            return false;
        }

        foreach ($accounts as $account) {
            if (is_string($account)) {
                $account = $this->parseAccountLine($account);
            }

            if (!is_array($account)) {
                continue;
            }

            $seatCapacity = $account['seat_capacity'] ?? null;
            $seatUsed = $account['seat_used'] ?? null;

            if (is_numeric($seatCapacity) && (int) $seatCapacity > 1) {
                return true;
            }

            if (is_numeric($seatUsed) && (int) $seatUsed > 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSeatCapacity(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            throw new ValidationException('Field seat_capacity is required.');
        }

        if (!is_numeric($value)) {
            throw new ValidationException('Field seat_capacity must be numeric.');
        }

        $normalized = (int) $value;

        if ($normalized <= 0) {
            throw new ValidationException('Field seat_capacity must be greater than 0.');
        }

        return $normalized;
    }

    private function normalizeDefaultExpiryDays(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new ValidationException('Field default_expiry_days must be numeric.');
        }

        $normalized = (int) $value;

        if ($normalized <= 0) {
            throw new ValidationException('Field default_expiry_days must be greater than 0.');
        }

        return $normalized;
    }

    private function normalizeStatus(string $status): string
    {
        $normalizedStatus = strtolower(trim($status));

        if (!in_array($normalizedStatus, DigitalAccount::statuses(), true)) {
            throw new ValidationException('Inventory status must be one of: available, sold, banned.');
        }

        return $normalizedStatus;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes'], true);
    }

    private function normalizeNullableDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($normalized))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new ValidationException('Inventory expires_at must be a valid datetime.');
        }
    }

    private function expiresAtFromDays(int $days): string
    {
        return (new DateTimeImmutable('now'))->modify(sprintf('+%d days', max(0, $days)))->format('Y-m-d H:i:s');
    }

    private function requiredUntilForProduct(array $product): string
    {
        $durationDays = max(self::LOW_EXPIRY_DAYS, (int) ($product['duration_days'] ?? 0));

        return (new DateTimeImmutable('now'))->modify(sprintf('+%d days', $durationDays))->format('Y-m-d H:i:s');
    }

    private function lowExpiryThreshold(): string
    {
        return (new DateTimeImmutable('now'))->modify(sprintf('+%d days', self::LOW_EXPIRY_DAYS))->format('Y-m-d H:i:s');
    }

    private function hasSufficientDurationValue(mixed $expiresAt, ?string $threshold): bool
    {
        if ($threshold === null || $expiresAt === null || trim((string) $expiresAt) === '') {
            return true;
        }

        return strtotime((string) $expiresAt) >= strtotime($threshold);
    }

    private function isLowExpiryValue(mixed $expiresAt, string $threshold): bool
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return false;
        }

        return strtotime((string) $expiresAt) < strtotime($threshold);
    }

    private function daysRemainingValue(mixed $expiresAt): ?int
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return null;
        }

        $seconds = strtotime((string) $expiresAt) - time();

        return max(0, (int) floor($seconds / 86400));
    }

    private function productUsesSharedAllocation(array $product): bool
    {
        return ($product['requires_inventory'] ?? true) === true
            && strtolower(trim((string) ($product['inventory_allocation_mode'] ?? 'exclusive'))) === 'shared';
    }

    private function promoteProductToSharedAllocation(string $productId, ?PDO $connection = null): void
    {
        try {
            $statement = ($connection ?? $this->databaseService->connection())->prepare(
                'UPDATE ' . Product::TABLE . " SET inventory_allocation_mode = 'shared', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND COALESCE(requires_inventory, 1) = 1"
            );
            $statement->execute([
                'id' => trim($productId),
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to switch product inventory mode to shared.', 0, $exception);
        }
    }

    private function listLowExpiryAccounts(string $productId, array $product, bool $includePassword): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, product_id, username, password, status, expires_at, seat_capacity, seat_used,
                    GREATEST(COALESCE(seat_capacity, 1) - COALESCE(seat_used, 0), 0) AS remaining_seats,
                    CASE WHEN expires_at IS NULL OR expires_at >= :required_until THEN 1 ELSE 0 END AS has_sufficient_duration,
                    CASE WHEN expires_at IS NOT NULL AND expires_at < :low_expiry_until THEN 1 ELSE 0 END AS is_low_expiry,
                    CASE WHEN expires_at IS NULL THEN NULL ELSE GREATEST(TIMESTAMPDIFF(DAY, CURRENT_TIMESTAMP, expires_at), 0) END AS days_remaining,
                    added_at, updated_at
             FROM ' . DigitalAccount::TABLE . '
             WHERE product_id = :product_id
               AND expires_at IS NOT NULL
               AND expires_at < :low_expiry_until_filter
             ORDER BY expires_at ASC, added_at DESC
             LIMIT ' . self::LOW_EXPIRY_PREVIEW_LIMIT
        );
        $statement->execute([
            'product_id' => $productId,
            'required_until' => $this->requiredUntilForProduct($product),
            'low_expiry_until' => $this->lowExpiryThreshold(),
            'low_expiry_until_filter' => $this->lowExpiryThreshold(),
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->hydrateAccount($row, $includePassword);
        }

        return $items;
    }

    private function hydrateAccount(array $row, bool $includePassword): array
    {
        $encryptedPassword = (string) ($row['password'] ?? '');
        $decryptedPassword = null;
        $maskedSource = $encryptedPassword;
        $seatCapacity = max(1, (int) ($row['seat_capacity'] ?? 1));
        $seatUsed = max(0, min($seatCapacity, (int) ($row['seat_used'] ?? 0)));
        $remainingSeats = max(0, (int) ($row['remaining_seats'] ?? ($seatCapacity - $seatUsed)));

        if ($encryptedPassword !== '') {
            try {
                $maskedSource = $this->encryptionService->decrypt($encryptedPassword);

                if ($includePassword) {
                    $decryptedPassword = $maskedSource;
                }
            } catch (\Throwable) {
                if ($includePassword) {
                    throw new InfrastructureException('Unable to decrypt stored inventory password.');
                }
            }
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'password_masked' => $this->maskSecret($maskedSource),
            'password' => $includePassword ? $decryptedPassword : null,
            'status' => (string) ($row['status'] ?? 'available'),
            'expires_at' => $row['expires_at'] ?? null,
            'seat_capacity' => $seatCapacity,
            'seat_used' => $seatUsed,
            'remaining_seats' => $remainingSeats,
            'has_sufficient_duration' => $this->normalizeBoolean($row['has_sufficient_duration'] ?? true),
            'is_low_expiry' => $this->normalizeBoolean($row['is_low_expiry'] ?? false),
            'days_remaining' => array_key_exists('days_remaining', $row) && $row['days_remaining'] !== null
                ? max(0, (int) $row['days_remaining'])
                : $this->daysRemainingValue($row['expires_at'] ?? null),
            'added_at' => $row['added_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function maskSecret(string $secret): string
    {
        $length = strlen($secret);

        if ($length <= 4) {
            return str_repeat('*', max(4, $length));
        }

        return substr($secret, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($secret, -2);
    }

    private function rollBack(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
