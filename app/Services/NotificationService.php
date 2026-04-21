<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Jobs\SendOrderCompletedEmailJob;
use App\Models\DigitalAccount;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

final class NotificationService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    /**
     * Called after an order is completed. Creates in-app notifications and sends email.
     */
    public function notifyOrderCompleted(array $completedOrder): void
    {
        $userId = trim((string) ($completedOrder['user']['id'] ?? ''));
        $userEmail = trim((string) ($completedOrder['user']['email'] ?? ''));
        $orderId = (string) ($completedOrder['id'] ?? '');
        $transferSyntax = strtoupper((string) ($completedOrder['transfer_syntax'] ?? ''));

        if ($userId === '') {
            return;
        }

        $this->createNotification(
            $userId,
            Notification::TYPE_ORDER_COMPLETED,
            'Thanh toan thanh cong',
            sprintf('Don hang %s da duoc xac nhan. Tai khoan dang duoc cap phat.', $transferSyntax),
            ['order_id' => $orderId]
        );

        $items = is_array($completedOrder['items'] ?? null) ? $completedOrder['items'] : [];
        $hasGrantedAccount = false;

        foreach ($items as $item) {
            if (($item['digital_account']['username'] ?? null) !== null) {
                $hasGrantedAccount = true;
                break;
            }
        }

        if ($hasGrantedAccount) {
            $this->createNotification(
                $userId,
                Notification::TYPE_ACCOUNT_GRANTED,
                'Tai khoan da duoc cap',
                sprintf('Thong tin dang nhap cho don hang %s da san sang. Vao muc Don Hang de xem.', $transferSyntax),
                ['order_id' => $orderId]
            );
        }

        if ($userEmail !== '') {
            $this->safelySendOrderCompletedEmail($userEmail, $completedOrder);
        }
    }

    /**
     * Creates renewal reminder notifications for items expiring in the given threshold.
     * Meant to be called from a cron or on-demand scan.
     */
    public function notifyRenewalReminders(int $daysThreshold = 3): int
    {
        try {
            $rows = $this->fetchExpiringOrderItems($daysThreshold);
            $count = 0;

            foreach ($rows as $row) {
                $userId = trim((string) ($row['user_id'] ?? ''));
                $productName = trim((string) ($row['product_name'] ?? 'san pham'));
                $expiresAt = trim((string) ($row['expires_at'] ?? ''));
                $orderId = trim((string) ($row['order_id'] ?? ''));

                if ($userId === '') {
                    continue;
                }

                if ($this->hasRecentRenewalReminder($userId, $orderId)) {
                    continue;
                }

                $daysLeft = $expiresAt !== '' ? max(0, (int) ceil((strtotime($expiresAt) - time()) / 86400)) : 0;
                $message = $daysLeft <= 1
                    ? sprintf('Tai khoan %s cua ban se het han trong vong 24 gio. Hay gia han ngay!', $productName)
                    : sprintf('Tai khoan %s cua ban con %d ngay la het han. Dung quen gia han!', $productName, $daysLeft);

                $this->createNotification(
                    $userId,
                    Notification::TYPE_RENEWAL_REMINDER,
                    'Sap het han: ' . $productName,
                    $message,
                    ['order_id' => $orderId, 'expires_at' => $expiresAt]
                );

                $count++;
            }

            return $count;
        } catch (\Throwable $exception) {
            throw new InfrastructureException('Unable to send renewal reminders.', 0, $exception);
        }
    }

    public function listForUser(string $userId, int $limit = 30, int $offset = 0): array
    {
        $normalizedUserId = trim($userId);

        if ($normalizedUserId === '') {
            return ['items' => [], 'unread_count' => 0];
        }

        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, type, title, message, metadata, is_read, created_at
             FROM ' . Notification::TABLE . '
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute(['user_id' => $normalizedUserId]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $metadata = [];

            if (is_string($row['metadata'] ?? null) && trim((string) $row['metadata']) !== '') {
                $decoded = json_decode((string) $row['metadata'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }

            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'metadata' => $metadata,
                'is_read' => $this->dbBoolean($row['is_read'] ?? false),
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return [
            'items' => $items,
            'unread_count' => $this->countUnread($normalizedUserId),
        ];
    }

    public function markAllReadForUser(string $userId): void
    {
        $normalizedUserId = trim($userId);

        if ($normalizedUserId === '') {
            return;
        }

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . Notification::TABLE . ' SET is_read = TRUE WHERE user_id = :user_id AND is_read = FALSE'
        );
        $statement->execute(['user_id' => $normalizedUserId]);
    }

    public function markReadById(string $notificationId, string $userId): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . Notification::TABLE . ' SET is_read = TRUE WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => trim($notificationId),
            'user_id' => trim($userId),
        ]);
    }

    /**
     * Returns admin alert data: accounts expiring soon + low-stock products.
     */
    public function adminAlerts(int $expiringDaysThreshold = 7, int $lowStockThreshold = 3): array
    {
        try {
            return [
                'expiring_accounts' => $this->fetchExpiringAccountsForAdmin($expiringDaysThreshold),
                'low_stock_products' => $this->fetchLowStockProductsForAdmin($lowStockThreshold),
                'generated_at' => date('Y-m-d\TH:i:sP'),
            ];
        } catch (\Throwable $exception) {
            throw new InfrastructureException('Unable to load admin alerts.', 0, $exception);
        }
    }

    private function createNotification(string $userId, string $type, string $title, string $message, array $metadata = []): void
    {
        $id = $this->uuidV4();
        $metadataJson = $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $statement = $this->databaseService->connection()->prepare(
            'INSERT INTO ' . Notification::TABLE . ' (id, user_id, type, title, message, metadata, is_read) VALUES (:id, :user_id, :type, :title, :message, :metadata, FALSE)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadataJson,
        ]);
    }

    private function countUnread(string $userId): int
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . Notification::TABLE . ' WHERE user_id = :user_id AND is_read = FALSE'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    private function hasRecentRenewalReminder(string $userId, string $orderId): bool
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT COUNT(*) AS total FROM " . Notification::TABLE . "
             WHERE user_id = :user_id
               AND type = :type
               AND metadata LIKE :order_pattern
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => Notification::TYPE_RENEWAL_REMINDER,
            'order_pattern' => '%"order_id":"' . $orderId . '"%',
        ]);
        $row = $statement->fetch();

        return is_array($row) && (int) ($row['total'] ?? 0) > 0;
    }

    private function fetchExpiringOrderItems(int $daysThreshold): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id, oi.order_id, oi.expires_at, o.user_id, p.name AS product_name
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN ' . Order::TABLE . ' o ON o.id = oi.order_id
             INNER JOIN ' . Product::TABLE . ' p ON p.id = oi.product_id
             WHERE oi.expires_at IS NOT NULL
               AND oi.expires_at > NOW()
               AND oi.expires_at <= :expires_before
               AND o.status = :completed_status'
        );
        $statement->execute([
            'expires_before' => $this->expiresBefore($daysThreshold),
            'completed_status' => 'completed',
        ]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function fetchExpiringAccountsForAdmin(int $daysThreshold): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id AS order_item_id, oi.expires_at, o.id AS order_id, o.user_id,
                    u.email AS user_email, p.name AS product_name,
                    da.username AS account_username
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN ' . Order::TABLE . ' o ON o.id = oi.order_id
             INNER JOIN ' . Product::TABLE . ' p ON p.id = oi.product_id
             LEFT JOIN ' . DigitalAccount::TABLE . ' da ON da.id = oi.digital_account_id
             LEFT JOIN ' . User::TABLE . ' u ON u.id = o.user_id
             WHERE oi.expires_at IS NOT NULL
               AND oi.expires_at > NOW()
               AND oi.expires_at <= :expires_before
               AND o.status = :completed_status
             ORDER BY oi.expires_at ASC
             LIMIT 50'
        );
        $statement->execute([
            'expires_before' => $this->expiresBefore($daysThreshold),
            'completed_status' => 'completed',
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = [
                'order_item_id' => (string) ($row['order_item_id'] ?? ''),
                'order_id' => (string) ($row['order_id'] ?? ''),
                'user_email' => $row['user_email'] ?? null,
                'product_name' => (string) ($row['product_name'] ?? ''),
                'account_username' => $row['account_username'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
                'days_remaining' => $row['expires_at'] !== null
                    ? max(0, (int) ceil((strtotime((string) $row['expires_at']) - time()) / 86400))
                    : null,
            ];
        }

        return $items;
    }

    private function fetchLowStockProductsForAdmin(int $threshold): array
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT p.id, p.name, p.requires_inventory, p.inventory_allocation_mode,
                    p.stock_quantity,
                    COUNT(CASE WHEN da.status = 'available' THEN 1 END) AS available_count
             FROM " . Product::TABLE . " p
             LEFT JOIN " . DigitalAccount::TABLE . " da ON da.product_id = p.id
             WHERE p.is_active = TRUE
             GROUP BY p.id, p.name, p.requires_inventory, p.inventory_allocation_mode, p.stock_quantity
             HAVING
               (p.requires_inventory = TRUE AND COUNT(CASE WHEN da.status = 'available' THEN 1 END) <= :inventory_threshold)
               OR
               (p.requires_inventory = FALSE AND p.stock_quantity <= :manual_threshold)
             ORDER BY available_count ASC, p.stock_quantity ASC
             LIMIT 30"
        );
        $statement->execute([
            'inventory_threshold' => $threshold,
            'manual_threshold' => $threshold,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $requiresInventory = $this->dbBoolean($row['requires_inventory'] ?? true);
            $availableStock = $requiresInventory
                ? (int) ($row['available_count'] ?? 0)
                : (int) ($row['stock_quantity'] ?? 0);

            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'requires_inventory' => $requiresInventory,
                'inventory_allocation_mode' => (string) ($row['inventory_allocation_mode'] ?? 'exclusive'),
                'available_stock' => $availableStock,
            ];
        }

        return $items;
    }

    private function expiresBefore(int $daysThreshold): string
    {
        $days = max(1, $daysThreshold);

        return (new \DateTimeImmutable('now'))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function safelySendOrderCompletedEmail(string $email, array $order): void
    {
        try {
            (new SendOrderCompletedEmailJob($email, $order))->handle();
        } catch (\Throwable) {
            // Email failure must not break order fulfillment.
        }
    }

    private function dbBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes'], true);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
