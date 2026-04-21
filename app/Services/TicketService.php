<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DigitalAccount;
use App\Models\OrderItem;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use PDO;
use PDOException;

final class TicketService
{
    private DatabaseService $databaseService;

    private UserActivityService $userActivityService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?UserActivityService $userActivityService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->userActivityService = $userActivityService ?? new UserActivityService($this->databaseService);
    }

    public function createForUser(array $user, array $payload): array
    {
        $userId = trim((string) ($user['id'] ?? ''));

        if ($userId === '') {
            throw new ValidationException('Authenticated user is required.');
        }

        $subject = trim((string) ($payload['subject'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $orderItemId = trim((string) ($payload['order_item_id'] ?? ''));

        if ($subject === '') {
            throw new ValidationException('Field subject is required.');
        }

        if ($message === '') {
            throw new ValidationException('Field message is required.');
        }

        $resolvedOrderItemId = null;

        if ($orderItemId !== '') {
            $resolvedOrderItemId = $this->requireOrderItemForUser($orderItemId, $userId)['id'];
        }

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $ticketId = $this->uuidV4();
            $statement = $connection->prepare(
                'INSERT INTO ' . SupportTicket::TABLE . ' (id, user_id, order_item_id, subject, message, status, last_reply_at, resolved_at) VALUES (:id, :user_id, :order_item_id, :subject, :message, :status, :last_reply_at, :resolved_at)'
            );
            $statement->execute([
                'id' => $ticketId,
                'user_id' => $userId,
                'order_item_id' => $resolvedOrderItemId,
                'subject' => $subject,
                'message' => $message,
                'status' => 'open',
                'last_reply_at' => null,
                'resolved_at' => null,
            ]);
            $this->appendMessage($ticketId, 'user', $userId, $message, $connection);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to create ticket.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        $ticket = $this->requireTicketForUser($ticketId, $userId);
        $this->safeLog($userId, 'ticket.created', 'Support ticket created.', [
            'ticket_id' => $ticketId,
            'order_item_id' => $resolvedOrderItemId,
        ]);

        return $ticket;
    }

    public function listForUser(string $userId, array $filters = []): array
    {
        $normalizedUserId = trim($userId);
        $includeMessages = $this->filterBoolean($filters['include_messages'] ?? null, true);

        if ($normalizedUserId === '') {
            throw new ValidationException('User id is required.');
        }

        [$page, $limit, $offset] = $this->pagination($filters);
        $status = $this->nullableStatus($filters['status'] ?? null);
        $conditions = ['t.user_id = :user_id'];
        $params = [
            'user_id' => $normalizedUserId,
        ];

        if ($status !== null) {
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }

        $where = implode(' AND ', $conditions);
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . SupportTicket::TABLE . ' t WHERE ' . $where
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT t.id FROM ' . SupportTicket::TABLE . ' t WHERE ' . $where . ' ORDER BY t.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute($params);
        $ticketIds = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $ticketIds[] = (string) $row['id'];
        }

        return [
            'items' => $this->loadTicketsByIds($ticketIds, $includeMessages),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'status' => $status,
            ],
        ];
    }

    public function findForUser(string $ticketId, string $userId): array
    {
        return $this->requireTicketForUser($ticketId, $userId);
    }

    public function listForAdmin(array $filters = []): array
    {
        $includeMessages = $this->filterBoolean($filters['include_messages'] ?? null, true);
        [$page, $limit, $offset] = $this->pagination($filters);
        $status = $this->nullableStatus($filters['status'] ?? null);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $conditions = ['1 = 1'];
        $params = [];

        if ($status !== null) {
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $conditions[] = '(LOWER(t.subject) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $where = implode(' AND ', $conditions);
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . SupportTicket::TABLE . ' t LEFT JOIN Users u ON u.id = t.user_id WHERE ' . $where
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT t.id FROM ' . SupportTicket::TABLE . ' t LEFT JOIN Users u ON u.id = t.user_id WHERE ' . $where . ' ORDER BY t.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute($params);
        $ticketIds = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $ticketIds[] = (string) $row['id'];
        }

        return [
            'items' => $this->loadTicketsByIds($ticketIds, $includeMessages),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ];
    }

    public function findForAdmin(string $ticketId): array
    {
        return $this->requireAdminTicket($ticketId);
    }

    public function replyAsAdmin(string $ticketId, array $adminUser, array $payload): array
    {
        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            throw new ValidationException('Field message is required.');
        }

        $adminId = trim((string) ($adminUser['id'] ?? ''));
        $ticket = $this->requireAdminTicket($ticketId);
        $status = $this->nullableStatus($payload['status'] ?? null);

        if ($status === null) {
            $currentStatus = (string) ($ticket['status'] ?? 'open');
            $status = in_array($currentStatus, ['open', 'resolved'], true) ? 'in_progress' : $currentStatus;
        }

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $this->appendMessage($ticket['id'], 'admin', $adminId !== '' ? $adminId : null, $message, $connection);
            $this->updateTicketStatus($ticket['id'], $status, $status === 'resolved', $connection);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to reply to ticket.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        $updatedTicket = $this->requireAdminTicket($ticketId);
        $this->safeLog((string) ($ticket['user']['id'] ?? ''), 'ticket.replied', 'Admin replied to support ticket.', [
            'ticket_id' => $ticket['id'],
            'status' => $status,
        ]);

        return $updatedTicket;
    }

    public function closeByAdmin(string $ticketId, array $adminUser, array $payload = []): array
    {
        $ticket = $this->requireAdminTicket($ticketId);
        $message = trim((string) ($payload['message'] ?? 'Ticket da duoc xu ly xong. Vui long kiem tra lai don hang.'));
        $adminId = trim((string) ($adminUser['id'] ?? ''));
        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $this->appendMessage($ticket['id'], 'admin', $adminId !== '' ? $adminId : null, $message, $connection);
            $this->updateTicketStatus($ticket['id'], 'resolved', true, $connection);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to close ticket.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        $updatedTicket = $this->requireAdminTicket($ticketId);
        $this->safeLog((string) ($ticket['user']['id'] ?? ''), 'ticket.resolved', 'Support ticket resolved by admin.', [
            'ticket_id' => $ticket['id'],
        ]);

        return $updatedTicket;
    }

    public function updateStatusByAdmin(string $ticketId, array $adminUser, array $payload): array
    {
        $ticket = $this->requireAdminTicket($ticketId);
        $status = $this->requireStatus($payload['status'] ?? null);
        $adminId = trim((string) ($adminUser['id'] ?? ''));

        if ((string) ($ticket['status'] ?? '') === $status) {
            return $ticket;
        }

        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $this->updateTicketStatus($ticket['id'], $status, $status === 'resolved', $connection);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to update ticket status.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        $updatedTicket = $this->requireAdminTicket($ticketId);
        $this->safeLog((string) ($ticket['user']['id'] ?? ''), 'ticket.status_updated', 'Support ticket status updated by admin.', [
            'ticket_id' => $ticket['id'],
            'status' => $status,
            'admin_id' => $adminId !== '' ? $adminId : null,
        ]);

        return $updatedTicket;
    }

    public function warrantyReplaceByAdmin(string $ticketId, array $adminUser, array $payload = []): array
    {
        $ticket = $this->requireAdminTicket($ticketId);
        $orderItemId = trim((string) ($ticket['order_item']['id'] ?? ''));

        if ($orderItemId === '') {
            throw new ValidationException('This ticket is not linked to a purchased account.');
        }

        $orderItem = $this->requireOrderItemForWarranty($orderItemId);
        $currentAccountId = trim((string) ($orderItem['digital_account']['id'] ?? ''));
        $productId = trim((string) ($orderItem['product']['id'] ?? ''));

        if ($currentAccountId === '') {
            throw new ValidationException('The linked order item does not have an assigned account.');
        }

        if ($productId === '') {
            throw new ValidationException('The linked order item does not have a valid product.');
        }

        $adminId = trim((string) ($adminUser['id'] ?? ''));
        $note = trim((string) ($payload['message'] ?? 'Da cap tai khoan thay the. Vui long vao don hang de xem thong tin moi.'));
        $connection = $this->databaseService->connection();
        $connection->beginTransaction();

        try {
            $replacementAccount = $this->findAvailableReplacementAccount($productId, $connection);

            if ($replacementAccount === null) {
                throw new ValidationException('No replacement account is currently available in inventory.');
            }

            $banOldAccount = $connection->prepare(
                'UPDATE ' . DigitalAccount::TABLE . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $banOldAccount->execute([
                'status' => 'banned',
                'id' => $currentAccountId,
            ]);

            $markReplacementSold = $connection->prepare(
                'UPDATE ' . DigitalAccount::TABLE . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $markReplacementSold->execute([
                'status' => 'sold',
                'id' => $replacementAccount['id'],
            ]);

            $updateOrderItem = $connection->prepare(
                'UPDATE ' . OrderItem::TABLE . ' SET digital_account_id = :digital_account_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $updateOrderItem->execute([
                'digital_account_id' => $replacementAccount['id'],
                'id' => $orderItemId,
            ]);

            $systemMessage = sprintf(
                'Da doi tai khoan bao hanh. Tai khoan cu %s duoc thu hoi, tai khoan moi %s da duoc cap.',
                (string) ($orderItem['digital_account']['username'] ?? 'N/A'),
                (string) ($replacementAccount['username'] ?? 'N/A')
            );
            $this->appendMessage($ticket['id'], 'system', null, $systemMessage, $connection);
            $this->appendMessage($ticket['id'], 'admin', $adminId !== '' ? $adminId : null, $note, $connection);
            $this->updateTicketStatus($ticket['id'], 'resolved', true, $connection);
            $connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack($connection);
            throw new InfrastructureException('Unable to replace account for warranty.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        $updatedTicket = $this->requireAdminTicket($ticketId);
        $this->safeLog((string) ($ticket['user']['id'] ?? ''), 'ticket.warranty_replaced', 'Warranty account replaced automatically.', [
            'ticket_id' => $ticket['id'],
            'order_item_id' => $orderItemId,
        ]);

        return $updatedTicket;
    }

    private function requireTicketForUser(string $ticketId, string $userId): array
    {
        $ticket = $this->findTicket($ticketId);

        if ($ticket === null || (string) ($ticket['user']['id'] ?? '') !== trim($userId)) {
            throw new NotFoundException('Ticket was not found.');
        }

        return $ticket;
    }

    private function requireAdminTicket(string $ticketId): array
    {
        $ticket = $this->findTicket($ticketId);

        if ($ticket === null) {
            throw new NotFoundException('Ticket was not found.');
        }

        return $ticket;
    }

    private function findTicket(string $ticketId): ?array
    {
        $tickets = $this->loadTicketsByIds([trim($ticketId)], true);

        return $tickets[0] ?? null;
    }

    private function loadMessages(string $ticketId): array
    {
        $grouped = $this->loadMessagesByTicketIds([$ticketId]);

        return $grouped[$ticketId] ?? [];
    }

    private function requireOrderItemForUser(string $orderItemId, string $userId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id, oi.order_id, oi.product_id, oi.digital_account_id, oi.expires_at, o.user_id, p.name AS product_name,
                    da.username AS account_username, da.status AS account_status
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN Orders o ON o.id = oi.order_id
             INNER JOIN Products p ON p.id = oi.product_id
             LEFT JOIN ' . DigitalAccount::TABLE . ' da ON da.id = oi.digital_account_id
             WHERE oi.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => trim($orderItemId),
        ]);
        $row = $statement->fetch();

        if (!is_array($row) || (string) ($row['user_id'] ?? '') !== trim($userId)) {
            throw new ValidationException('Order item was not found for this user.');
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
        ];
    }

    private function requireOrderItemForWarranty(string $orderItemId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id, oi.product_id, oi.digital_account_id, oi.expires_at,
                    p.name AS product_name,
                    da.username AS account_username, da.status AS account_status
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN Products p ON p.id = oi.product_id
             LEFT JOIN ' . DigitalAccount::TABLE . ' da ON da.id = oi.digital_account_id
             WHERE oi.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => trim($orderItemId),
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new ValidationException('Linked order item was not found.');
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'expires_at' => $row['expires_at'] ?? null,
            'product' => [
                'id' => (string) ($row['product_id'] ?? ''),
                'name' => $row['product_name'] ?? null,
            ],
            'digital_account' => [
                'id' => $row['digital_account_id'] ?? null,
                'username' => $row['account_username'] ?? null,
                'status' => $row['account_status'] ?? null,
            ],
        ];
    }

    private function findAvailableReplacementAccount(string $productId, PDO $connection): ?array
    {
        $statement = $connection->prepare(
            'SELECT id, username FROM ' . DigitalAccount::TABLE . " WHERE product_id = :product_id AND status = 'available' ORDER BY added_at ASC LIMIT 1"
        );
        $statement->execute([
            'product_id' => $productId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function appendMessage(string $ticketId, string $senderRole, ?string $senderUserId, string $message, PDO $connection): void
    {
        $statement = $connection->prepare(
            'INSERT INTO ' . SupportTicketMessage::TABLE . ' (id, ticket_id, sender_role, sender_user_id, message) VALUES (:id, :ticket_id, :sender_role, :sender_user_id, :message)'
        );
        $statement->execute([
            'id' => $this->uuidV4(),
            'ticket_id' => $ticketId,
            'sender_role' => $senderRole,
            'sender_user_id' => $senderUserId !== '' ? $senderUserId : null,
            'message' => trim($message),
        ]);
    }

    private function updateTicketStatus(string $ticketId, string $status, bool $markResolved, PDO $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE ' . SupportTicket::TABLE . ' SET status = :status, last_reply_at = CURRENT_TIMESTAMP, resolved_at = :resolved_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'resolved_at' => $markResolved ? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
            'id' => $ticketId,
        ]);
    }

    private function nullableStatus(mixed $status): ?string
    {
        $normalized = strtolower(trim((string) $status));

        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, ['open', 'in_progress', 'resolved'], true)) {
            throw new ValidationException('Ticket status is invalid.');
        }

        return $normalized;
    }

    private function requireStatus(mixed $status): string
    {
        $normalized = $this->nullableStatus($status);

        if ($normalized === null) {
            throw new ValidationException('Field status is required.');
        }

        return $normalized;
    }

    private function pagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        return [$page, $limit, $offset];
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
            // Ticket flow should not fail because activity logging is unavailable.
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

    private function loadTicketsByIds(array $ticketIds, bool $includeMessages): array
    {
        $normalizedIds = array_values(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $ticketIds)));

        if ($normalizedIds === []) {
            return [];
        }

        [$inClause, $params] = $this->buildInClauseParams('ticket_id', $normalizedIds);
        $statement = $this->databaseService->connection()->prepare(
            'SELECT t.id, t.user_id, t.order_item_id, t.subject, t.message, t.status, t.last_reply_at, t.resolved_at, t.created_at, t.updated_at,
                    u.email AS user_email,
                    oi.expires_at AS order_item_expires_at,
                    p.id AS product_id, p.name AS product_name,
                    da.id AS account_id, da.username AS account_username, da.status AS account_status
             FROM ' . SupportTicket::TABLE . ' t
             INNER JOIN Users u ON u.id = t.user_id
             LEFT JOIN ' . OrderItem::TABLE . ' oi ON oi.id = t.order_item_id
             LEFT JOIN Products p ON p.id = oi.product_id
             LEFT JOIN ' . DigitalAccount::TABLE . ' da ON da.id = oi.digital_account_id
             WHERE t.id IN (' . $inClause . ')'
        );
        $statement->execute($params);
        $ticketsById = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ticketId = (string) ($row['id'] ?? '');

            if ($ticketId === '') {
                continue;
            }

            $ticket = [
                'id' => $ticketId,
                'subject' => (string) ($row['subject'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'status' => (string) ($row['status'] ?? 'open'),
                'last_reply_at' => $row['last_reply_at'] ?? null,
                'resolved_at' => $row['resolved_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'user' => [
                    'id' => (string) ($row['user_id'] ?? ''),
                    'email' => $row['user_email'] ?? null,
                ],
                'order_item' => [
                    'id' => $row['order_item_id'] ?? null,
                    'expires_at' => $row['order_item_expires_at'] ?? null,
                    'product' => [
                        'id' => $row['product_id'] ?? null,
                        'name' => $row['product_name'] ?? null,
                    ],
                    'digital_account' => [
                        'id' => $row['account_id'] ?? null,
                        'username' => $row['account_username'] ?? null,
                        'status' => $row['account_status'] ?? null,
                    ],
                ],
                'messages' => [],
            ];

            if (($ticket['order_item']['id'] ?? null) === null) {
                $ticket['order_item'] = [
                    'id' => null,
                    'expires_at' => null,
                    'product' => [
                        'id' => null,
                        'name' => null,
                    ],
                    'digital_account' => [
                        'id' => null,
                        'username' => null,
                        'status' => null,
                    ],
                ];
            }

            $ticketsById[$ticketId] = $ticket;
        }

        if ($includeMessages) {
            $messagesByTicketId = $this->loadMessagesByTicketIds($normalizedIds);

            foreach ($ticketsById as $ticketId => $ticket) {
                $ticketsById[$ticketId]['messages'] = $messagesByTicketId[$ticketId] ?? [];
            }
        }

        $ordered = [];

        foreach ($normalizedIds as $ticketId) {
            if (isset($ticketsById[$ticketId])) {
                $ordered[] = $ticketsById[$ticketId];
            }
        }

        return $ordered;
    }

    private function loadMessagesByTicketIds(array $ticketIds): array
    {
        $normalizedIds = array_values(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $ticketIds)));

        if ($normalizedIds === []) {
            return [];
        }

        [$inClause, $params] = $this->buildInClauseParams('ticket_id', $normalizedIds);
        $statement = $this->databaseService->connection()->prepare(
            'SELECT tm.id, tm.ticket_id, tm.sender_role, tm.sender_user_id, tm.message, tm.created_at, u.email AS sender_email
             FROM ' . SupportTicketMessage::TABLE . ' tm
             LEFT JOIN Users u ON u.id = tm.sender_user_id
             WHERE tm.ticket_id IN (' . $inClause . ')
             ORDER BY tm.ticket_id ASC, tm.created_at ASC, tm.id ASC'
        );
        $statement->execute($params);
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ticketId = (string) ($row['ticket_id'] ?? '');

            if ($ticketId === '') {
                continue;
            }

            $grouped[$ticketId][] = [
                'id' => (string) ($row['id'] ?? ''),
                'sender_role' => (string) ($row['sender_role'] ?? 'system'),
                'sender' => [
                    'id' => $row['sender_user_id'] ?? null,
                    'email' => $row['sender_email'] ?? null,
                ],
                'message' => (string) ($row['message'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ];
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
}
