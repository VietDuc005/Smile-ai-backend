<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DigitalAccount;
use App\Models\Order;
use App\Models\OrderItem;
use PDO;
use PDOException;
use App\Services\NotificationService;

final class OrderService
{
    private DatabaseService $databaseService;

    private EncryptionService $encryptionService;

    private InventoryService $inventoryService;

    private NotificationService $notificationService;

    private PaymentService $paymentService;

    private ProductService $productService;

    private UserActivityService $userActivityService;

    private VoucherService $voucherService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?ProductService $productService = null,
        ?InventoryService $inventoryService = null,
        ?PaymentService $paymentService = null,
        ?EncryptionService $encryptionService = null,
        ?UserActivityService $userActivityService = null,
        ?NotificationService $notificationService = null,
        ?VoucherService $voucherService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->productService = $productService ?? new ProductService();
        $this->inventoryService = $inventoryService ?? new InventoryService($this->databaseService, $encryptionService, $this->productService);
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->userActivityService = $userActivityService ?? new UserActivityService($this->databaseService);
        $this->notificationService = $notificationService ?? new NotificationService($this->databaseService);
        $this->voucherService = $voucherService ?? new VoucherService($this->databaseService);
    }

    public function createForUser(array $user, array $payload): array
    {
        $userId = trim((string) ($user['id'] ?? ''));

        if ($userId === '') {
            throw new ValidationException('Authenticated user is required.');
        }

        $productId = trim((string) ($payload['product_id'] ?? ''));
        $quantity = $this->normalizeQuantity($payload['quantity'] ?? 1);

        if ($productId === '') {
            throw new ValidationException('Field product_id is required.');
        }

        $product = $this->productService->findById($productId);

        if ($product === null) {
            throw new NotFoundException('Product was not found.');
        }

        if (($product['is_active'] ?? false) !== true) {
            throw new ValidationException('This product is currently inactive.');
        }

        $customerEmail = $this->normalizeCustomerEmail($payload['customer_email'] ?? null);

        if (($product['requires_customer_email'] ?? false) === true && $customerEmail === null) {
            throw new ValidationException('This product requires customer_email.');
        }

        $this->inventoryService->ensureAvailableStock($productId, $quantity);

        $rawTotal = round(((float) ($product['price'] ?? 0)) * $quantity, 2);
        $discountAmount = 0.0;
        $voucherId = null;
        $voucherCode = trim((string) ($payload['voucher_code'] ?? ''));

        if ($voucherCode !== '') {
            $voucherResult = $this->voucherService->validateForOrder($voucherCode, $rawTotal);
            $discountAmount = $voucherResult['discount_amount'];
            $voucherId = $voucherResult['voucher_id'];
        }

        $totalAmount = round(max(0, $rawTotal - $discountAmount), 2);

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $orderId = $this->uuidV4();
            $transferSyntax = $this->generateUniqueTransferSyntax($connection);
            $paymentPreview = [
                'transfer_syntax' => $transferSyntax,
                'total_amount' => $totalAmount,
            ];
            $paymentInstructions = $this->paymentService->buildPaymentInstructions($paymentPreview);

            if ($voucherId !== null) {
                $this->voucherService->consumeVoucher($voucherId, $connection);
            }

            $orderStatement = $connection->prepare(
                'INSERT INTO ' . Order::TABLE . ' (id, user_id, customer_email, total_amount, voucher_id, discount_amount, transfer_syntax, payment_provider, payment_qr_url, payment_payload, status) VALUES (:id, :user_id, :customer_email, :total_amount, :voucher_id, :discount_amount, :transfer_syntax, :payment_provider, :payment_qr_url, :payment_payload, :status)'
            );
            $orderStatement->execute([
                'id' => $orderId,
                'user_id' => $userId,
                'customer_email' => $customerEmail,
                'total_amount' => $totalAmount,
                'voucher_id' => $voucherId,
                'discount_amount' => $discountAmount,
                'transfer_syntax' => $transferSyntax,
                'payment_provider' => $paymentInstructions['provider'],
                'payment_qr_url' => $paymentInstructions['qr_image_url'],
                'payment_payload' => $this->encodeJson($paymentInstructions['qr_payload']),
                'status' => 'pending',
            ]);

            $itemStatement = $connection->prepare(
                'INSERT INTO ' . OrderItem::TABLE . ' (id, order_id, product_id, digital_account_id, unit_price, expires_at) VALUES (:id, :order_id, :product_id, :digital_account_id, :unit_price, :expires_at)'
            );

            for ($index = 0; $index < $quantity; $index++) {
                $itemStatement->execute([
                    'id' => $this->uuidV4(),
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'digital_account_id' => null,
                    'unit_price' => (float) ($product['price'] ?? 0),
                    'expires_at' => null,
                ]);
            }

            $connection->commit();
            $order = $this->requireUserOrder($orderId, $userId, false);
            $this->safeLog($userId, 'order.created', 'Created a pending order.', [
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'customer_email' => $customerEmail,
                'total_amount' => $totalAmount,
            ]);

            return $order;
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to create order.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }
    }

    public function listForUser(string $userId, array $filters = []): array
    {
        $normalizedUserId = trim($userId);
        $includeSecrets = $this->filterBoolean($filters['include_secrets'] ?? null, false);
        $includeItems = $this->filterBoolean($filters['include_items'] ?? null, true);

        if ($normalizedUserId === '') {
            throw new ValidationException('User id is required.');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $conditions = ['o.user_id = :user_id'];
        $params = [
            'user_id' => $normalizedUserId,
        ];

        if ($status !== '') {
            $conditions[] = 'o.status = :status';
            $params['status'] = $this->normalizeStatus($status);
        }

        $whereClause = implode(' AND ', $conditions);
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . Order::TABLE . ' o WHERE ' . $whereClause
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT o.id FROM ' . Order::TABLE . ' o WHERE ' . $whereClause . ' ORDER BY o.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute($params);
        $orderIds = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $orderIds[] = (string) $row['id'];
        }

        return [
            'items' => $this->loadOrdersByIds($orderIds, $includeSecrets, $includeItems),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'status' => $status !== '' ? $status : null,
            ],
        ];
    }

    public function findForUser(string $orderId, string $userId): array
    {
        return $this->requireUserOrder($orderId, $userId, true);
    }

    public function listForAdmin(array $filters = []): array
    {
        $includeSecrets = $this->filterBoolean($filters['include_secrets'] ?? null, false);
        $includeItems = $this->filterBoolean($filters['include_items'] ?? null, true);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $conditions = ['1 = 1'];
        $params = [];

        if ($status !== '') {
            $conditions[] = 'o.status = :status';
            $params['status'] = $this->normalizeStatus($status);
        }

        if ($search !== '') {
            $conditions[] = '(LOWER(o.transfer_syntax) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search OR LOWER(COALESCE(o.customer_email, \'\')) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $conditions);
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . Order::TABLE . ' o LEFT JOIN Users u ON u.id = o.user_id WHERE ' . $whereClause
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT o.id FROM ' . Order::TABLE . ' o LEFT JOIN Users u ON u.id = o.user_id WHERE ' . $whereClause .
            ' ORDER BY o.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute($params);
        $orderIds = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $orderIds[] = (string) $row['id'];
        }

        return [
            'items' => $this->loadOrdersByIds($orderIds, $includeSecrets, $includeItems),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'status' => $status !== '' ? $status : null,
                'search' => $search,
            ],
        ];
    }

    public function findForAdmin(string $orderId): array
    {
        return $this->requireAdminOrder($orderId, null, false);
    }

    public function manualProvisionItem(string $orderId, string $itemId, array $payload): array
    {
        $username = trim((string) ($payload['username'] ?? ''));
        $rawPassword = trim((string) ($payload['password'] ?? ''));
        $rawExpiresAt = trim((string) ($payload['expires_at'] ?? ''));

        if ($username === '') {
            throw new ValidationException('Field username is required.');
        }

        if ($rawPassword === '') {
            throw new ValidationException('Field password is required.');
        }

        $connection = $this->databaseService->connection();

        // Verify order exists
        $this->requireAdminOrder(trim($orderId), $connection);

        // Find order item and verify it belongs to this order
        $itemStatement = $connection->prepare(
            'SELECT id, product_id, digital_account_id FROM ' . OrderItem::TABLE . ' WHERE id = :id AND order_id = :order_id LIMIT 1'
        );
        $itemStatement->execute(['id' => trim($itemId), 'order_id' => trim($orderId)]);
        $itemRow = $itemStatement->fetch();

        if (!is_array($itemRow)) {
            throw new NotFoundException('Order item was not found.');
        }

        $existingAccountId = $itemRow['digital_account_id'] ?? null;
        $productId = (string) ($itemRow['product_id'] ?? '');
        $encryptedPassword = $this->encryptionService->encrypt($rawPassword);

        $ts = $rawExpiresAt !== '' ? strtotime($rawExpiresAt) : false;
        $expiresAt = ($ts !== false && $ts > 0) ? date('Y-m-d H:i:s', $ts) : null;

        try {
            if ($existingAccountId !== null) {
                $stmt = $connection->prepare(
                    'UPDATE ' . DigitalAccount::TABLE . ' SET username = :username, password = :password, status = \'sold\', expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $stmt->execute([
                    'username' => $username,
                    'password' => $encryptedPassword,
                    'expires_at' => $expiresAt,
                    'id' => $existingAccountId,
                ]);
            } else {
                $newAccountId = $this->uuidV4();
                $stmt = $connection->prepare(
                    'INSERT INTO ' . DigitalAccount::TABLE . ' (id, product_id, username, password, status, expires_at, seat_capacity, seat_used) VALUES (:id, :product_id, :username, :password, \'sold\', :expires_at, 1, 1)'
                );
                $stmt->execute([
                    'id' => $newAccountId,
                    'product_id' => $productId,
                    'username' => $username,
                    'password' => $encryptedPassword,
                    'expires_at' => $expiresAt,
                ]);
                $linkStmt = $connection->prepare(
                    'UPDATE ' . OrderItem::TABLE . ' SET digital_account_id = :account_id, expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $linkStmt->execute([
                    'account_id' => $newAccountId,
                    'expires_at' => $expiresAt,
                    'id' => trim($itemId),
                ]);
            }
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to provision account for order item.', 0, $exception);
        }

        return $this->requireAdminOrder(trim($orderId));
    }

    public function cancelForAdmin(string $orderId): array
    {
        $order = $this->requireAdminOrder($orderId);

        if (($order['status'] ?? '') === 'completed') {
            throw new ValidationException('Completed orders cannot be cancelled.');
        }

        if (($order['status'] ?? '') === 'cancelled') {
            return $order;
        }

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . Order::TABLE . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => 'cancelled',
            'id' => $order['id'],
        ]);

        $this->safeLog((string) ($order['user']['id'] ?? ''), 'order.cancelled', 'Order cancelled by admin.', [
            'order_id' => $order['id'],
        ]);

        return $this->requireAdminOrder($orderId);
    }

    public function forceMatchForAdmin(string $orderId, array $metadata = []): array
    {
        $metadata['match_source'] = $metadata['match_source'] ?? 'admin_manual';

        return $this->completeMatchedOrder($orderId, $metadata);
    }

    public function completeMatchedOrder(string $orderId, array $metadata = []): array
    {
        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $order = $this->requireAdminOrder($orderId, $connection);

            if (($order['status'] ?? '') === 'cancelled') {
                throw new ValidationException('Cancelled orders cannot be matched.');
            }

            $items = $this->loadOrderItems((string) $order['id'], true, $connection);

            if ($items === []) {
                throw new ValidationException('Order does not have any items to assign.');
            }

            $unassignedItems = array_values(array_filter($items, static function (array $item): bool {
                return ($item['digital_account']['id'] ?? null) === null;
            }));

            if ($unassignedItems !== []) {
                $grouped = [];

                foreach ($unassignedItems as $item) {
                    $productId = (string) ($item['product']['id'] ?? '');
                    $grouped[$productId][] = $item;
                }

                foreach ($grouped as $productId => $groupItems) {
                    $productSnapshot = $groupItems[0]['product'] ?? [];

                    if (($productSnapshot['requires_inventory'] ?? true) === true) {
                        if ($this->productUsesSharedAllocation($productSnapshot)) {
                            $allocations = $this->fetchAvailableSharedAllocations($productId, count($groupItems), $productSnapshot, $connection);

                            if (count($allocations) < count($groupItems)) {
                                throw new ValidationException('Not enough shared seats to match this order.');
                            }

                            foreach ($groupItems as $index => $item) {
                                $allocation = $allocations[$index];
                                $expiresAt = $this->calculateExpiresAt((int) ($item['product']['duration_days'] ?? 0));
                                $this->assignAccountToOrderItem(
                                    (string) $item['id'],
                                    (string) $allocation['id'],
                                    $expiresAt,
                                    $connection
                                );
                                $this->consumeSharedSeat((string) $allocation['id'], $connection);
                            }
                            continue;
                        }

                        $availableAccounts = $this->fetchAvailableExclusiveAccounts($productId, count($groupItems), $productSnapshot, $connection);

                        if (count($availableAccounts) < count($groupItems)) {
                            throw new ValidationException('Not enough available accounts to match this order.');
                        }

                        foreach ($groupItems as $index => $item) {
                            $account = $availableAccounts[$index];
                            $expiresAt = $this->calculateExpiresAt((int) ($item['product']['duration_days'] ?? 0));
                            $this->assignAccountToOrderItem(
                                (string) $item['id'],
                                (string) $account['id'],
                                $expiresAt,
                                $connection
                            );
                            $this->markDigitalAccountSold((string) $account['id'], $connection);
                        }
                        continue;
                    }

                    $this->inventoryService->decrementManualStock($productId, count($groupItems), $connection);

                    $expiresAt = $this->calculateExpiresAt((int) ($productSnapshot['duration_days'] ?? 0));

                    foreach ($groupItems as $item) {
                        $this->setOrderItemExpiresAt((string) $item['id'], $expiresAt, $connection);
                    }
                }
            }

            $orderUpdate = $connection->prepare(
                'UPDATE ' . Order::TABLE . ' SET status = :status, paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $orderUpdate->execute([
                'status' => 'completed',
                'id' => $order['id'],
            ]);

            $connection->commit();
            $completedOrder = $this->requireAdminOrder($orderId);
            $completedOrderForNotification = $this->requireAdminOrder($orderId, null, true);
            $matchSource = trim((string) ($metadata['match_source'] ?? 'system'));
            $description = $matchSource === 'payment_webhook'
                ? 'Order completed automatically from payment webhook.'
                : 'Order completed and fulfillment processed.';
            $this->safeLog((string) ($completedOrder['user']['id'] ?? ''), 'order.completed', $description, array_merge($metadata, [
                'order_id' => $completedOrder['id'],
            ]));
            $this->safeNotify($completedOrderForNotification);

            return $completedOrder;
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to match order.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }
    }

    private function requireUserOrder(string $orderId, string $userId, bool $includeSecrets): array
    {
        $order = $this->findOrder($orderId, null, $includeSecrets);

        if ($order === null || (string) ($order['user']['id'] ?? '') !== trim($userId)) {
            throw new NotFoundException('Order was not found.');
        }

        return $order;
    }

    private function requireAdminOrder(string $orderId, ?PDO $connection = null, bool $includeSecrets = false): array
    {
        $order = $this->findOrder($orderId, $connection, $includeSecrets);

        if ($order === null) {
            throw new NotFoundException('Order was not found.');
        }

        return $order;
    }

    private function findOrder(string $orderId, ?PDO $connection = null, bool $includeSecrets = false): ?array
    {
        $statement = ($connection ?? $this->databaseService->connection())->prepare(
            'SELECT o.id, o.user_id, o.customer_email, o.total_amount, o.voucher_id, o.discount_amount, o.transfer_syntax, o.payment_provider, o.payment_qr_url, o.payment_payload, o.status, o.paid_at, o.created_at, o.updated_at, u.email AS user_email
             FROM ' . Order::TABLE . ' o
             LEFT JOIN Users u ON u.id = o.user_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => trim($orderId),
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $order = $this->hydrateOrder($row);
        $order['items'] = $this->loadOrderItems($order['id'], $includeSecrets, $connection);

        return $order;
    }

    private function loadOrderItems(string $orderId, bool $includeSecrets, ?PDO $connection = null): array
    {
        $grouped = $this->loadOrderItemsByOrderIds([$orderId], $includeSecrets, $connection);

        return $grouped[$orderId] ?? [];
    }

    private function hydrateOrder(array $row): array
    {
        $paymentPayload = $this->decodeJson($row['payment_payload'] ?? null);
        $payment = [
            'provider' => (string) ($row['payment_provider'] ?? 'vietqr'),
            'transfer_syntax' => (string) ($row['transfer_syntax'] ?? ''),
            'qr_image_url' => $row['payment_qr_url'] ?? null,
            'qr_payload' => $paymentPayload,
            'amount' => (float) ($row['total_amount'] ?? 0),
        ];

        $discountAmt = (float) ($row['discount_amount'] ?? 0);
        $totalAmt = (float) ($row['total_amount'] ?? 0);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'user' => [
                'id' => (string) ($row['user_id'] ?? ''),
                'email' => $row['user_email'] ?? null,
            ],
            'customer_email' => $row['customer_email'] ?? null,
            'total_amount' => $totalAmt,
            'discount_amount' => $discountAmt,
            'original_amount' => $totalAmt + $discountAmt,
            'voucher_id' => $row['voucher_id'] ?? null,
            'transfer_syntax' => (string) ($row['transfer_syntax'] ?? ''),
            'payment' => $payment,
            'status' => (string) ($row['status'] ?? 'pending'),
            'paid_at' => $row['paid_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'items' => [],
        ];
    }

    private function hydrateOrderItem(array $row, bool $includeSecrets): array
    {
        $features = [];

        if (is_string($row['product_features'] ?? null) && trim((string) $row['product_features']) !== '') {
            $decoded = json_decode((string) $row['product_features'], true);
            $features = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        }

        $account = [
            'id' => $row['digital_account_id'] ?? null,
            'username' => $row['account_username'] ?? null,
            'password' => null,
            'password_masked' => null,
            'status' => $row['account_status'] ?? null,
            'expires_at' => $row['account_expires_at'] ?? null,
            'seat_capacity' => max(1, (int) ($row['account_seat_capacity'] ?? 1)),
            'seat_used' => max(0, (int) ($row['account_seat_used'] ?? 0)),
            'remaining_seats' => max(0, (int) ($row['account_seat_capacity'] ?? 1) - (int) ($row['account_seat_used'] ?? 0)),
        ];

        if (is_string($row['account_password'] ?? null) && $row['account_password'] !== '') {
            try {
                $plainPassword = $this->encryptionService->decrypt((string) $row['account_password']);
                $account['password_masked'] = $this->maskSecret($plainPassword);
                $account['password'] = $includeSecrets ? $plainPassword : null;
            } catch (\Throwable) {
                $account['password_masked'] = '****';
                $account['password'] = null;
            }
        }

        $account['has_totp'] = $this->dbBoolean($row['has_totp'] ?? false);

        if ($account['id'] === null) {
            $account = [
                'id' => null,
                'username' => null,
                'password' => null,
                'password_masked' => null,
                'status' => null,
                'expires_at' => null,
                'seat_capacity' => null,
                'seat_used' => null,
                'remaining_seats' => null,
                'has_totp' => false,
            ];
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'expires_at' => $row['expires_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'product' => [
                'id' => (string) ($row['product_id'] ?? ''),
                'name' => (string) ($row['product_name'] ?? ''),
                'description' => $row['product_description'] ?? null,
                'features' => $features,
                'duration_days' => (int) ($row['duration_days'] ?? 0),
                'image_url' => $row['image_url'] ?? null,
                'requires_inventory' => $this->dbBoolean($row['requires_inventory'] ?? true),
                'inventory_allocation_mode' => (string) ($row['inventory_allocation_mode'] ?? 'exclusive'),
                'requires_customer_email' => $this->dbBoolean($row['requires_customer_email'] ?? false),
                'stock_quantity' => max(0, (int) ($row['stock_quantity'] ?? 0)),
                'is_active' => $this->dbBoolean($row['is_active'] ?? false),
            ],
            'digital_account' => $account,
        ];
    }

    private function assignAccountToOrderItem(string $orderItemId, string $digitalAccountId, string $expiresAt, PDO $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE ' . OrderItem::TABLE . ' SET digital_account_id = :digital_account_id, expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'digital_account_id' => $digitalAccountId,
            'expires_at' => $expiresAt,
            'id' => $orderItemId,
        ]);
    }

    private function setOrderItemExpiresAt(string $orderItemId, string $expiresAt, PDO $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE ' . OrderItem::TABLE . ' SET expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'expires_at' => $expiresAt,
            'id' => $orderItemId,
        ]);
    }

    private function markDigitalAccountSold(string $accountId, PDO $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE ' . DigitalAccount::TABLE . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => 'sold',
            'id' => $accountId,
        ]);
    }

    private function fetchAvailableExclusiveAccounts(string $productId, int $limit, array $product, PDO $connection): array
    {
        $requiredUntil = $this->requiredUntilForProduct($product);
        $statement = $connection->prepare(
            'SELECT id, username, password, status FROM ' . DigitalAccount::TABLE . " WHERE product_id = :product_id AND status = 'available' AND (expires_at IS NULL OR expires_at >= :required_until) ORDER BY added_at ASC LIMIT " . $limit
        );
        $statement->execute([
            'product_id' => $productId,
            'required_until' => $requiredUntil,
        ]);
        $accounts = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $accounts[] = $row;
        }

        return $accounts;
    }

    private function fetchAvailableSharedAllocations(string $productId, int $requiredQuantity, array $product, PDO $connection): array
    {
        $requiredUntil = $this->requiredUntilForProduct($product);
        $statement = $connection->prepare(
            'SELECT id, username, password, status, expires_at, seat_capacity, seat_used
             FROM ' . DigitalAccount::TABLE . "
             WHERE product_id = :product_id
               AND status <> 'banned'
               AND COALESCE(seat_capacity, 1) > COALESCE(seat_used, 0)
               AND (expires_at IS NULL OR expires_at >= :required_until)
             ORDER BY added_at ASC, id ASC"
        );
        $statement->execute([
            'product_id' => $productId,
            'required_until' => $requiredUntil,
        ]);
        $allocations = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $remainingSeats = max(0, (int) ($row['seat_capacity'] ?? 1) - (int) ($row['seat_used'] ?? 0));

            for ($index = 0; $index < $remainingSeats; $index++) {
                $allocations[] = $row;

                if (count($allocations) >= $requiredQuantity) {
                    return $allocations;
                }
            }
        }

        return $allocations;
    }

    private function consumeSharedSeat(string $accountId, PDO $connection): void
    {
        $statement = $connection->prepare(
            "UPDATE " . DigitalAccount::TABLE . "
             SET seat_used = LEAST(COALESCE(seat_capacity, 1), COALESCE(seat_used, 0) + 1),
                 status = CASE
                     WHEN LEAST(COALESCE(seat_capacity, 1), COALESCE(seat_used, 0) + 1) >= COALESCE(seat_capacity, 1) THEN 'sold'
                     ELSE 'available'
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status <> 'banned'
               AND COALESCE(seat_capacity, 1) > COALESCE(seat_used, 0)"
        );
        $statement->execute([
            'id' => $accountId,
        ]);

        if ($statement->rowCount() > 0) {
            return;
        }

        throw new ValidationException('Shared account no longer has available seats.');
    }

    private function generateUniqueTransferSyntax(PDO $connection): string
    {
        $attempts = 0;

        while ($attempts < 20) {
            $candidate = $this->paymentService->buildTransferSyntax((string) random_int(1000, 999999));
            $statement = $connection->prepare(
                'SELECT COUNT(*) AS total FROM ' . Order::TABLE . ' WHERE transfer_syntax = :transfer_syntax'
            );
            $statement->execute([
                'transfer_syntax' => $candidate,
            ]);
            $row = $statement->fetch();
            $exists = is_array($row) ? (int) ($row['total'] ?? 0) > 0 : false;

            if (!$exists) {
                return $candidate;
            }

            $attempts++;
        }

        throw new InfrastructureException('Unable to generate a unique transfer syntax.');
    }

    private function normalizeQuantity(mixed $quantity): int
    {
        if (!is_numeric($quantity)) {
            throw new ValidationException('Field quantity must be numeric.');
        }

        $normalizedQuantity = (int) $quantity;

        if ($normalizedQuantity <= 0) {
            throw new ValidationException('Field quantity must be greater than 0.');
        }

        if ($normalizedQuantity > 100) {
            throw new ValidationException('Field quantity is too large.');
        }

        return $normalizedQuantity;
    }

    private function normalizeStatus(string $status): string
    {
        $normalizedStatus = strtolower(trim($status));

        if (!in_array($normalizedStatus, Order::statuses(), true)) {
            throw new ValidationException('Order status is invalid.');
        }

        return $normalizedStatus;
    }

    private function calculateExpiresAt(int $durationDays): string
    {
        $days = max(1, $durationDays);
        $date = new \DateTimeImmutable('now');

        return $date->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    private function requiredUntilForProduct(array $product): string
    {
        return $this->calculateExpiresAt(max(30, (int) ($product['duration_days'] ?? 0)));
    }

    private function productUsesSharedAllocation(array $product): bool
    {
        return ($product['requires_inventory'] ?? true) === true
            && strtolower(trim((string) ($product['inventory_allocation_mode'] ?? 'exclusive'))) === 'shared';
    }

    private function normalizeCustomerEmail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Field customer_email must be a valid email address.');
        }

        return strtolower($normalized);
    }

    private function encodeJson(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function decodeJson(mixed $payload): array
    {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function dbBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes'], true);
    }

    private function maskSecret(string $secret): string
    {
        $length = strlen($secret);

        if ($length <= 4) {
            return str_repeat('*', max(4, $length));
        }

        return substr($secret, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($secret, -2);
    }

    private function loadOrdersByIds(array $orderIds, bool $includeSecrets, bool $includeItems, ?PDO $connection = null): array
    {
        $normalizedIds = array_values(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $orderIds)));

        if ($normalizedIds === []) {
            return [];
        }

        [$inClause, $params] = $this->buildInClauseParams('order_id', $normalizedIds);
        $statement = ($connection ?? $this->databaseService->connection())->prepare(
            'SELECT o.id, o.user_id, o.customer_email, o.total_amount, o.voucher_id, o.discount_amount, o.transfer_syntax, o.payment_provider, o.payment_qr_url, o.payment_payload, o.status, o.paid_at, o.created_at, o.updated_at, u.email AS user_email
             FROM ' . Order::TABLE . ' o
             LEFT JOIN Users u ON u.id = o.user_id
             WHERE o.id IN (' . $inClause . ')'
        );
        $statement->execute($params);
        $ordersById = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $order = $this->hydrateOrder($row);
            $ordersById[$order['id']] = $order;
        }

        if ($includeItems) {
            $itemsByOrderId = $this->loadOrderItemsByOrderIds($normalizedIds, $includeSecrets, $connection);

            foreach ($ordersById as $orderId => $order) {
                $ordersById[$orderId]['items'] = $itemsByOrderId[$orderId] ?? [];
            }
        }

        $ordered = [];

        foreach ($normalizedIds as $orderId) {
            if (isset($ordersById[$orderId])) {
                $ordered[] = $ordersById[$orderId];
            }
        }

        return $ordered;
    }

    private function loadOrderItemsByOrderIds(array $orderIds, bool $includeSecrets, ?PDO $connection = null): array
    {
        $normalizedIds = array_values(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $orderIds)));

        if ($normalizedIds === []) {
            return [];
        }

        [$inClause, $params] = $this->buildInClauseParams('order_id', $normalizedIds);
        $statement = ($connection ?? $this->databaseService->connection())->prepare(
            'SELECT oi.id, oi.order_id, oi.product_id, oi.digital_account_id, oi.unit_price, oi.expires_at, oi.created_at, oi.updated_at,
                    p.name AS product_name, p.description AS product_description, p.features AS product_features, p.duration_days, p.image_url, p.requires_inventory, p.inventory_allocation_mode, p.requires_customer_email, p.stock_quantity, p.is_active,
                    da.username AS account_username, da.password AS account_password, da.status AS account_status, da.expires_at AS account_expires_at,
                    da.seat_capacity AS account_seat_capacity, da.seat_used AS account_seat_used,
                    CASE WHEN da.totp_secret IS NOT NULL AND da.totp_secret <> \'\' THEN 1 ELSE 0 END AS has_totp
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN Products p ON p.id = oi.product_id
             LEFT JOIN ' . DigitalAccount::TABLE . ' da ON da.id = oi.digital_account_id
             WHERE oi.order_id IN (' . $inClause . ')
             ORDER BY oi.order_id ASC, oi.created_at ASC, oi.id ASC'
        );
        $statement->execute($params);
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (string) ($row['order_id'] ?? '');

            if ($orderId === '') {
                continue;
            }

            $grouped[$orderId][] = $this->hydrateOrderItem($row, $includeSecrets);
        }

        return $grouped;
    }

    private function buildInClauseParams(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function filterBoolean(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function safeNotify(array $completedOrder): void
    {
        try {
            $this->notificationService->notifyOrderCompleted($completedOrder);
        } catch (\Throwable) {
            // Notifications must not break order flows.
        }
    }

    private function safeLog(string $userId, string $action, string $description, array $metadata = []): void
    {
        $normalizedUserId = trim($userId);

        if ($normalizedUserId === '') {
            return;
        }

        try {
            $this->userActivityService->log($normalizedUserId, $action, $description, $metadata);
        } catch (\Throwable) {
            // Activity history should not break order flows.
        }
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
