<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Models\UserActivity;
use PDOException;

final class UserActivityService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function log(string $userId, string $action, string $description, array $metadata = []): array
    {
        $normalizedUserId = trim($userId);

        if ($normalizedUserId === '') {
            throw new InfrastructureException('User activity cannot be logged without a user id.');
        }

        $id = $this->uuidV4();
        $statement = $this->databaseService->connection()->prepare(
            'INSERT INTO ' . UserActivity::TABLE . ' (id, user_id, action, description, metadata) VALUES (:id, :user_id, :action, :description, :metadata)'
        );

        try {
            $statement->execute([
                'id' => $id,
                'user_id' => $normalizedUserId,
                'action' => trim($action),
                'description' => trim($description),
                'metadata' => $this->encodeMetadata($metadata),
            ]);
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to write user activity.', 0, $exception);
        }

        return [
            'id' => $id,
            'user_id' => $normalizedUserId,
            'action' => trim($action),
            'description' => trim($description),
            'metadata' => $metadata,
        ];
    }

    public function historyForUser(string $userId, int $limit = 20): array
    {
        $safeLimit = max(1, min(100, $limit));
        $timeline = array_merge(
            $this->customActivities($userId, $safeLimit),
            $this->orderActivities($userId, $safeLimit),
            $this->ticketActivities($userId, $safeLimit)
        );

        usort($timeline, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        return array_slice($timeline, 0, $safeLimit);
    }

    private function customActivities(string $userId, int $limit): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, action, description, metadata, created_at FROM ' . UserActivity::TABLE . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . $limit
        );
        $statement->execute([
            'user_id' => $userId,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'source' => 'activity_log',
                'action' => (string) ($row['action'] ?? 'activity.logged'),
                'description' => (string) ($row['description'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'metadata' => $this->decodeMetadata($row['metadata'] ?? null),
            ];
        }

        return $items;
    }

    private function orderActivities(string $userId, int $limit): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, total_amount, transfer_syntax, status, created_at FROM Orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . $limit
        );
        $statement->execute([
            'user_id' => $userId,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'source' => 'orders',
                'action' => 'order.' . (string) ($row['status'] ?? 'created'),
                'description' => sprintf(
                    'Order %s with transfer syntax %s.',
                    (string) ($row['status'] ?? 'created'),
                    (string) ($row['transfer_syntax'] ?? '')
                ),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'metadata' => [
                    'total_amount' => (float) ($row['total_amount'] ?? 0),
                    'transfer_syntax' => (string) ($row['transfer_syntax'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                ],
            ];
        }

        return $items;
    }

    private function ticketActivities(string $userId, int $limit): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, subject, status, created_at FROM Support_Tickets WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . $limit
        );
        $statement->execute([
            'user_id' => $userId,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'source' => 'support_tickets',
                'action' => 'ticket.' . (string) ($row['status'] ?? 'open'),
                'description' => sprintf(
                    'Support ticket "%s" is %s.',
                    (string) ($row['subject'] ?? ''),
                    (string) ($row['status'] ?? 'open')
                ),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'metadata' => [
                    'subject' => (string) ($row['subject'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                ],
            ];
        }

        return $items;
    }

    private function encodeMetadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }

        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
