<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Models\Order;
use App\Models\PaymentWebhook;
use PDO;
use PDOException;

final class PaymentWebhookService
{
    private DatabaseService $databaseService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?OrderService $orderService = null,
        ?PaymentService $paymentService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->orderService = $orderService ?? new OrderService($this->databaseService, null, null, $this->paymentService);
    }

    public function handle(array $payload, array $headers = [], array $query = []): array
    {
        $normalized = $this->paymentService->normalizeWebhookPayload($payload, $headers, $query);
        $this->paymentService->assertWebhookIsTrusted((string) ($normalized['provider'] ?? 'generic'), $headers, $payload, $query);
        $recordState = $this->recordWebhook($normalized, $payload, $headers);

        if ($recordState['is_duplicate'] === true) {
            return [
                'processed' => true,
                'matched' => (string) ($recordState['record']['status'] ?? '') === 'matched',
                'duplicate' => true,
                'provider' => (string) ($normalized['provider'] ?? 'generic'),
                'reason' => 'duplicate_event',
                'order' => $this->loadMatchedOrderSummary($recordState['record']),
                'webhook' => $this->presentWebhook($recordState['record']),
            ];
        }

        $record = $recordState['record'];

        if (($normalized['is_credit'] ?? false) !== true) {
            $record = $this->finalizeWebhook((string) $record['id'], 'ignored', 'Ignoring outbound or zero-value transaction.');

            return [
                'processed' => true,
                'matched' => false,
                'duplicate' => false,
                'provider' => (string) ($normalized['provider'] ?? 'generic'),
                'reason' => 'not_credit_transaction',
                'order' => null,
                'webhook' => $this->presentWebhook($record),
            ];
        }

        if (($normalized['transfer_syntax'] ?? null) === null) {
            $record = $this->finalizeWebhook((string) $record['id'], 'unmatched', 'Transfer syntax was not found in transaction content.');

            return [
                'processed' => true,
                'matched' => false,
                'duplicate' => false,
                'provider' => (string) ($normalized['provider'] ?? 'generic'),
                'reason' => 'transfer_syntax_missing',
                'order' => null,
                'webhook' => $this->presentWebhook($record),
            ];
        }

        $orderMatch = $this->findOrderMatch((string) $normalized['transfer_syntax'], (float) ($normalized['amount'] ?? 0));

        if ($orderMatch['matched'] !== true) {
            $record = $this->finalizeWebhook((string) $record['id'], 'unmatched', (string) ($orderMatch['message'] ?? 'Unable to match order.'));

            return [
                'processed' => true,
                'matched' => false,
                'duplicate' => false,
                'provider' => (string) ($normalized['provider'] ?? 'generic'),
                'reason' => (string) ($orderMatch['reason'] ?? 'order_unmatched'),
                'order' => null,
                'webhook' => $this->presentWebhook($record),
            ];
        }

        try {
            $order = $this->orderService->completeMatchedOrder((string) $orderMatch['order_id'], [
                'match_source' => 'payment_webhook',
                'payment_provider' => (string) ($normalized['provider'] ?? 'generic'),
                'payment_event_key' => (string) ($normalized['event_key'] ?? ''),
                'payment_external_id' => $normalized['external_id'] ?? null,
                'payment_reference' => $normalized['reference'] ?? null,
                'payment_amount' => (float) ($normalized['amount'] ?? 0),
                'transfer_syntax' => (string) ($normalized['transfer_syntax'] ?? ''),
                'transfer_content' => (string) ($normalized['transfer_content'] ?? ''),
            ]);
        } catch (\Throwable $exception) {
            $this->finalizeWebhook((string) $record['id'], 'failed', 'Payment matched but order completion failed: ' . $exception->getMessage());
            throw $exception;
        }

        $record = $this->finalizeWebhook(
            (string) $record['id'],
            'matched',
            'Webhook matched successfully.',
            (string) ($order['id'] ?? '')
        );

        return [
            'processed' => true,
            'matched' => true,
            'duplicate' => false,
            'provider' => (string) ($normalized['provider'] ?? 'generic'),
            'reason' => 'matched',
            'order' => $this->orderSummary($order),
            'webhook' => $this->presentWebhook($record),
        ];
    }

    private function recordWebhook(array $normalized, array $payload, array $headers): array
    {
        $existing = $this->findWebhookByEventKey((string) ($normalized['event_key'] ?? ''));

        if ($existing !== null) {
            $status = (string) ($existing['status'] ?? 'received');

            if (in_array($status, ['matched', 'ignored', 'unmatched'], true)) {
                return [
                    'record' => $existing,
                    'is_duplicate' => true,
                ];
            }

            $this->refreshWebhookPayload((string) $existing['id'], $payload, $headers);

            return [
                'record' => $this->requireWebhook((string) $existing['id']),
                'is_duplicate' => false,
            ];
        }

        $connection = $this->databaseService->connection();
        $statement = $connection->prepare(
            'INSERT INTO ' . PaymentWebhook::TABLE . ' (id, provider, event_key, external_id, direction, amount, transfer_content, transfer_syntax, status, note, raw_payload, headers, matched_order_id) VALUES (:id, :provider, :event_key, :external_id, :direction, :amount, :transfer_content, :transfer_syntax, :status, :note, :raw_payload, :headers, :matched_order_id)'
        );
        $id = $this->uuidV4();

        try {
            $statement->execute([
                'id' => $id,
                'provider' => (string) ($normalized['provider'] ?? 'generic'),
                'event_key' => (string) ($normalized['event_key'] ?? ''),
                'external_id' => $normalized['external_id'] ?? null,
                'direction' => (string) ($normalized['direction'] ?? 'in'),
                'amount' => (float) ($normalized['amount'] ?? 0),
                'transfer_content' => (string) ($normalized['transfer_content'] ?? ''),
                'transfer_syntax' => $normalized['transfer_syntax'] ?? null,
                'status' => 'received',
                'note' => 'Webhook received.',
                'raw_payload' => $this->encodeJson($payload),
                'headers' => $this->encodeJson($headers),
                'matched_order_id' => null,
            ]);
        } catch (PDOException $exception) {
            $duplicate = $this->findWebhookByEventKey((string) ($normalized['event_key'] ?? ''));

            if ($duplicate !== null) {
                return [
                    'record' => $duplicate,
                    'is_duplicate' => in_array((string) ($duplicate['status'] ?? 'received'), ['matched', 'ignored', 'unmatched'], true),
                ];
            }

            throw new InfrastructureException('Unable to persist payment webhook.', 0, $exception);
        }

        return [
            'record' => $this->requireWebhook($id),
            'is_duplicate' => false,
        ];
    }

    private function refreshWebhookPayload(string $webhookId, array $payload, array $headers): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . PaymentWebhook::TABLE . ' SET raw_payload = :raw_payload, headers = :headers, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'raw_payload' => $this->encodeJson($payload),
            'headers' => $this->encodeJson($headers),
            'id' => $webhookId,
        ]);
    }

    private function finalizeWebhook(string $webhookId, string $status, string $note, ?string $matchedOrderId = null): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'UPDATE ' . PaymentWebhook::TABLE . ' SET status = :status, note = :note, matched_order_id = :matched_order_id, processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'note' => trim($note),
            'matched_order_id' => $matchedOrderId !== '' ? $matchedOrderId : null,
            'id' => $webhookId,
        ]);

        return $this->requireWebhook($webhookId);
    }

    private function findOrderMatch(string $transferSyntax, float $amount): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, total_amount, status FROM ' . Order::TABLE . ' WHERE transfer_syntax = :transfer_syntax LIMIT 1'
        );
        $statement->execute([
            'transfer_syntax' => trim($transferSyntax),
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return [
                'matched' => false,
                'reason' => 'order_not_found',
                'message' => 'No order found for this transfer syntax.',
            ];
        }

        if (!$this->amountsMatch((float) ($row['total_amount'] ?? 0), $amount)) {
            return [
                'matched' => false,
                'reason' => 'amount_mismatch',
                'message' => sprintf(
                    'Transferred amount does not match order total. Expected %.2f, got %.2f.',
                    (float) ($row['total_amount'] ?? 0),
                    $amount
                ),
            ];
        }

        if ((string) ($row['status'] ?? 'pending') === 'cancelled') {
            return [
                'matched' => false,
                'reason' => 'order_cancelled',
                'message' => 'The matched order was already cancelled.',
            ];
        }

        return [
            'matched' => true,
            'order_id' => (string) ($row['id'] ?? ''),
        ];
    }

    private function findWebhookByEventKey(string $eventKey): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, provider, event_key, external_id, direction, amount, transfer_content, transfer_syntax, status, note, matched_order_id, processed_at, created_at, updated_at FROM ' . PaymentWebhook::TABLE . ' WHERE event_key = :event_key LIMIT 1'
        );
        $statement->execute([
            'event_key' => trim($eventKey),
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function requireWebhook(string $webhookId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, provider, event_key, external_id, direction, amount, transfer_content, transfer_syntax, status, note, matched_order_id, processed_at, created_at, updated_at FROM ' . PaymentWebhook::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $statement->execute([
            'id' => $webhookId,
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new InfrastructureException('Unable to load payment webhook record.');
        }

        return $row;
    }

    private function loadMatchedOrderSummary(array $webhook): ?array
    {
        $matchedOrderId = trim((string) ($webhook['matched_order_id'] ?? ''));

        if ($matchedOrderId === '') {
            return null;
        }

        try {
            return $this->orderSummary($this->orderService->findForAdmin($matchedOrderId));
        } catch (\Throwable) {
            return [
                'id' => $matchedOrderId,
            ];
        }
    }

    private function presentWebhook(array $webhook): array
    {
        return [
            'id' => (string) ($webhook['id'] ?? ''),
            'provider' => (string) ($webhook['provider'] ?? 'generic'),
            'event_key' => (string) ($webhook['event_key'] ?? ''),
            'external_id' => $webhook['external_id'] ?? null,
            'direction' => (string) ($webhook['direction'] ?? 'in'),
            'amount' => (float) ($webhook['amount'] ?? 0),
            'transfer_syntax' => $webhook['transfer_syntax'] ?? null,
            'status' => (string) ($webhook['status'] ?? 'received'),
            'note' => $webhook['note'] ?? null,
            'matched_order_id' => $webhook['matched_order_id'] ?? null,
            'processed_at' => $webhook['processed_at'] ?? null,
            'created_at' => $webhook['created_at'] ?? null,
            'updated_at' => $webhook['updated_at'] ?? null,
        ];
    }

    private function orderSummary(array $order): array
    {
        return [
            'id' => (string) ($order['id'] ?? ''),
            'status' => (string) ($order['status'] ?? 'pending'),
            'total_amount' => (float) ($order['total_amount'] ?? 0),
            'transfer_syntax' => (string) ($order['transfer_syntax'] ?? ''),
            'paid_at' => $order['paid_at'] ?? null,
        ];
    }

    private function amountsMatch(float $expected, float $actual): bool
    {
        return (int) round($expected * 100) === (int) round($actual * 100);
    }

    private function encodeJson(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
