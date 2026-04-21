<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\InfrastructureException;
use App\Exceptions\NotFoundException;
use App\Models\User;
use PDOException;

final class UserService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, email, role, status, blocked_reason, blocked_at, last_login_at, created_at, updated_at FROM ' . User::TABLE . ' WHERE email = :email LIMIT 1'
        );
        $statement->execute([
            'email' => strtolower(trim($email)),
        ]);
        $user = $statement->fetch();

        return is_array($user) ? $this->hydrateUser($user) : null;
    }

    public function findById(string $id): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, email, role, status, blocked_reason, blocked_at, last_login_at, created_at, updated_at FROM ' . User::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
        ]);
        $user = $statement->fetch();

        return is_array($user) ? $this->hydrateUser($user) : null;
    }

    public function createFromEmail(string $email, string $role = 'user'): array
    {
        $id = $this->uuidV4();
        $normalizedEmail = strtolower(trim($email));

        try {
            $statement = $this->databaseService->connection()->prepare(
                'INSERT INTO ' . User::TABLE . ' (id, email, role, status) VALUES (:id, :email, :role, :status)'
            );
            $statement->execute([
                'id' => $id,
                'email' => $normalizedEmail,
                'role' => $role,
                'status' => 'active',
            ]);
        } catch (PDOException $exception) {
            $existingUser = $this->findByEmail($normalizedEmail);

            if ($existingUser !== null) {
                return $existingUser;
            }

            throw new InfrastructureException('Unable to create user record.', 0, $exception);
        }

        $user = $this->findById($id);

        if ($user === null) {
            throw new InfrastructureException('User record was created but could not be loaded.');
        }

        return $user;
    }

    public function ensureActive(array $user): void
    {
        if (($user['status'] ?? 'active') !== 'blocked') {
            return;
        }

        $reason = trim((string) ($user['blocked_reason'] ?? ''));
        $message = 'User account is blocked.';

        if ($reason !== '') {
            $message .= ' Reason: ' . $reason;
        }

        throw new ForbiddenException($message);
    }

    public function touchLastLogin(string $userId): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . User::TABLE . ' SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => trim($userId),
        ]);
    }

    public function profileSummary(string $userId): array
    {
        $user = $this->requireUser($userId);

        return [
            'user' => $user,
            'statistics' => [
                'orders' => $this->orderStatistics($userId),
                'tickets' => $this->ticketStatistics($userId),
                'subscriptions' => $this->subscriptionStatistics($userId),
            ],
        ];
    }

    public function listCustomers(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $conditions = ['role = :role'];
        $params = [
            'role' => 'user',
        ];

        if ($search !== '') {
            $conditions[] = 'LOWER(email) LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        if ($status !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        $whereClause = implode(' AND ', $conditions);
        $countStatement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . User::TABLE . ' WHERE ' . $whereClause
        );
        $countStatement->execute($params);
        $countRow = $countStatement->fetch();
        $total = is_array($countRow) ? (int) ($countRow['total'] ?? 0) : 0;

        $listStatement = $this->databaseService->connection()->prepare(
            'SELECT id, email, role, status, blocked_reason, blocked_at, last_login_at, created_at, updated_at FROM ' . User::TABLE .
            ' WHERE ' . $whereClause .
            ' ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStatement->execute($params);
        $items = [];

        foreach ($listStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->hydrateUser($row);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ];
    }

    public function blockCustomer(string $userId, string $reason = 'Violated policy'): array
    {
        $this->requireCustomer($userId);

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . User::TABLE . ' SET status = :status, blocked_reason = :blocked_reason, blocked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => 'blocked',
            'blocked_reason' => trim($reason) !== '' ? trim($reason) : 'Violated policy',
            'id' => trim($userId),
        ]);

        return $this->requireCustomer($userId);
    }

    public function unblockCustomer(string $userId): array
    {
        $this->requireCustomer($userId);

        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . User::TABLE . ' SET status = :status, blocked_reason = NULL, blocked_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => 'active',
            'id' => trim($userId),
        ]);

        return $this->requireCustomer($userId);
    }

    private function requireUser(string $userId): array
    {
        $user = $this->findById(trim($userId));

        if ($user === null) {
            throw new NotFoundException('User was not found.');
        }

        return $user;
    }

    private function requireCustomer(string $userId): array
    {
        $user = $this->requireUser($userId);

        if (($user['role'] ?? 'user') !== 'user') {
            throw new ForbiddenException('Only customer accounts can be managed in this module.');
        }

        return $user;
    }

    private function orderStatistics(string $userId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS total_spent,
                    MAX(created_at) AS last_order_at
             FROM Orders
             WHERE user_id = :user_id"
        );
        $statement->execute([
            'user_id' => $userId,
        ]);
        $stats = $statement->fetch();
        $stats = is_array($stats) ? $stats : [];

        return [
            'total_orders' => (int) ($stats['total_orders'] ?? 0),
            'total_spent' => (float) ($stats['total_spent'] ?? 0),
            'last_order_at' => $stats['last_order_at'] ?? null,
        ];
    }

    private function ticketStatistics(string $userId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT COUNT(*) AS total_tickets,
                    COALESCE(SUM(CASE WHEN status IN ('open', 'in_progress') THEN 1 ELSE 0 END), 0) AS open_tickets,
                    MAX(created_at) AS last_ticket_at
             FROM Support_Tickets
             WHERE user_id = :user_id"
        );
        $statement->execute([
            'user_id' => $userId,
        ]);
        $stats = $statement->fetch();
        $stats = is_array($stats) ? $stats : [];

        return [
            'total_tickets' => (int) ($stats['total_tickets'] ?? 0),
            'open_tickets' => (int) ($stats['open_tickets'] ?? 0),
            'last_ticket_at' => $stats['last_ticket_at'] ?? null,
        ];
    }

    private function subscriptionStatistics(string $userId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            "SELECT COUNT(*) AS active_accounts,
                    MIN(oi.expires_at) AS next_expiration_at
             FROM Order_Items oi
             INNER JOIN Orders o ON o.id = oi.order_id
             WHERE o.user_id = :user_id
               AND oi.expires_at IS NOT NULL
               AND oi.expires_at > CURRENT_TIMESTAMP"
        );
        $statement->execute([
            'user_id' => $userId,
        ]);
        $stats = $statement->fetch();
        $stats = is_array($stats) ? $stats : [];

        return [
            'active_accounts' => (int) ($stats['active_accounts'] ?? 0),
            'next_expiration_at' => $stats['next_expiration_at'] ?? null,
        ];
    }

    private function hydrateUser(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'id' => (string) ($row['id'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['role'] ?? 'user'),
            'status' => $status,
            'is_blocked' => $status === 'blocked',
            'blocked_reason' => $row['blocked_reason'] ?? null,
            'blocked_at' => $row['blocked_at'] ?? null,
            'last_login_at' => $row['last_login_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
