<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DigitalAccount;
use App\Models\Order;
use App\Models\OrderItem;

final class PortalService
{
    private const COOLDOWN_SECONDS = 25;
    private const IP_MAX_PER_MINUTE = 20;

    private DatabaseService $databaseService;

    private TotpService $totpService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?TotpService $totpService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->totpService = $totpService ?? new TotpService();
    }

    public function getCode(string $orderKey, string $ipAddress): array
    {
        $key = strtoupper(trim($orderKey));

        if ($key === '') {
            throw new ValidationException('Order key is required.');
        }

        $order = $this->findCompletedOrder($key);
        $item = $this->findItemWithTotp((string) $order['id']);

        if ($item === null) {
            throw new NotFoundException('This order does not have any TOTP-enabled accounts. Only accounts with 2FA secrets support this portal.');
        }

        $this->assertIpLimit($ipAddress);
        $this->assertCooldown((string) $item['order_item_id']);

        $code = $this->totpService->currentCode((string) $item['totp_secret']);
        $secondsRemaining = $this->totpService->secondsRemaining();

        $this->logAccess((string) $item['order_item_id'], $ipAddress);

        return [
            'code' => $code,
            'seconds_remaining' => $secondsRemaining,
            'order_key' => $key,
            'product_name' => $item['product_name'] ?? null,
            'account_username' => $item['account_username'] ?? null,
            'expires_at' => $item['expires_at'] ?? null,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function findCompletedOrder(string $transferSyntax): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT id, transfer_syntax, status FROM ' . Order::TABLE . ' WHERE UPPER(transfer_syntax) = :key LIMIT 1'
        );
        $statement->execute(['key' => $transferSyntax]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Order key not found. Please double-check the key from your confirmation email.');
        }

        if ((string) ($row['status'] ?? '') !== 'completed') {
            throw new NotFoundException('This order has not been completed yet. TOTP access is only available for completed orders.');
        }

        return $row;
    }

    private function findItemWithTotp(string $orderId): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id AS order_item_id, oi.expires_at,
                    p.name AS product_name,
                    da.username AS account_username,
                    da.totp_secret
             FROM ' . OrderItem::TABLE . ' oi
             INNER JOIN Products p ON p.id = oi.product_id
             INNER JOIN ' . DigitalAccount::TABLE . " da ON da.id = oi.digital_account_id
             WHERE oi.order_id = :order_id
               AND da.totp_secret IS NOT NULL
               AND da.totp_secret <> ''
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function assertIpLimit(string $ip): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM Totp_Access_Log
             WHERE ip_address = :ip AND accessed_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );
        $statement->execute(['ip' => $ip]);
        $row = $statement->fetch();

        if (is_array($row) && (int) ($row['cnt'] ?? 0) >= self::IP_MAX_PER_MINUTE) {
            throw new ValidationException('Too many requests from your IP. Please wait a minute before trying again.');
        }
    }

    private function assertCooldown(string $orderItemId): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(accessed_at), NOW()) AS elapsed,
                    UNIX_TIMESTAMP(MAX(accessed_at)) AS last_access_ts
             FROM Totp_Access_Log
             WHERE order_item_id = :item_id'
        );
        $statement->execute(['item_id' => $orderItemId]);
        $row = $statement->fetch();

        if (!is_array($row) || $row['elapsed'] === null) {
            return;
        }

        $elapsed = (int) $row['elapsed'];
        $lastAccessTs = isset($row['last_access_ts']) ? (int) $row['last_access_ts'] : null;

        if ($elapsed < self::COOLDOWN_SECONDS && $lastAccessTs !== null) {
            // Once the TOTP window rolls over, a new code exists and the portal
            // should be allowed to refresh immediately even if the last fetch was recent.
            if ($this->totpService->currentWindow($lastAccessTs) !== $this->totpService->currentWindow()) {
                return;
            }

            $wait = self::COOLDOWN_SECONDS - $elapsed;
            throw new ValidationException(sprintf('Please wait %d more second%s before requesting a new code.', $wait, $wait === 1 ? '' : 's'));
        }
    }

    private function logAccess(string $orderItemId, string $ip): void
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        $statement = $this->databaseService->connection()->prepare(
            'INSERT INTO Totp_Access_Log (id, order_item_id, ip_address) VALUES (:id, :item_id, :ip)'
        );
        $statement->execute([
            'id' => $id,
            'item_id' => $orderItemId,
            'ip' => $ip,
        ]);
    }
}
