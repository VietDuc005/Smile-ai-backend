<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;

final class InvoiceService
{
    private DatabaseService $databaseService;

    private EncryptionService $encryptionService;

    private EnvService $envService;

    public function __construct(
        ?DatabaseService $databaseService = null,
        ?EncryptionService $encryptionService = null,
        ?EnvService $envService = null
    ) {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->envService = $envService ?? new EnvService();
    }

    public function buildForOrder(string $orderId, string $userId): array
    {
        $order = $this->requireCompletedOrder($orderId, $userId);
        $items = $this->loadItems($orderId);
        $seller = $this->sellerInfo();

        return [
            'invoice_number' => $this->invoiceNumber($order),
            'issued_at' => $order['paid_at'] ?? $order['updated_at'] ?? $order['created_at'],
            'seller' => $seller,
            'buyer' => [
                'email' => $order['user_email'] ?? $order['customer_email'] ?? 'N/A',
            ],
            'order' => [
                'id' => $order['id'],
                'transfer_syntax' => strtoupper((string) ($order['transfer_syntax'] ?? '')),
                'status' => $order['status'],
                'paid_at' => $order['paid_at'],
                'created_at' => $order['created_at'],
                'payment_provider' => $order['payment_provider'] ?? 'vietqr',
            ],
            'items' => $items,
            'total_amount' => (float) ($order['total_amount'] ?? 0),
            'currency' => 'VND',
        ];
    }

    private function requireCompletedOrder(string $orderId, string $userId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT o.id, o.user_id, o.customer_email, o.total_amount, o.transfer_syntax,
                    o.payment_provider, o.status, o.paid_at, o.created_at, o.updated_at,
                    u.email AS user_email
             FROM Orders o
             LEFT JOIN Users u ON u.id = o.user_id
             WHERE o.id = :id AND o.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['id' => trim($orderId), 'user_id' => trim($userId)]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('Order was not found.');
        }

        if ((string) ($row['status'] ?? '') !== 'completed') {
            throw new NotFoundException('Invoice is only available for completed orders.');
        }

        return $row;
    }

    private function loadItems(string $orderId): array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT oi.id, oi.unit_price, oi.expires_at,
                    p.name AS product_name, p.description AS product_description, p.duration_days,
                    da.username AS account_username, da.password AS account_password
             FROM Order_Items oi
             INNER JOIN Products p ON p.id = oi.product_id
             LEFT JOIN Digital_Accounts da ON da.id = oi.digital_account_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.created_at ASC'
        );
        $statement->execute(['order_id' => $orderId]);
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $username = $row['account_username'] ?? null;
            $plainPassword = null;

            if (is_string($row['account_password'] ?? null) && $row['account_password'] !== '') {
                try {
                    $plainPassword = $this->encryptionService->decrypt((string) $row['account_password']);
                } catch (\Throwable) {
                    $plainPassword = null;
                }
            }

            $items[] = [
                'product_name' => (string) ($row['product_name'] ?? ''),
                'product_description' => $row['product_description'] ?? null,
                'duration_days' => (int) ($row['duration_days'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'expires_at' => $row['expires_at'] ?? null,
                'account_username' => $username,
                'account_password' => $plainPassword,
            ];
        }

        return $items;
    }

    private function invoiceNumber(array $order): string
    {
        $prefix = strtoupper(trim((string) $this->envService->get('INVOICE_PREFIX', 'INV')));
        $id = strtoupper(substr((string) ($order['id'] ?? ''), 0, 8));
        $date = '';

        if (is_string($order['paid_at'] ?? null) && $order['paid_at'] !== '') {
            $date = date('Ymd', strtotime((string) $order['paid_at']));
        } elseif (is_string($order['created_at'] ?? null)) {
            $date = date('Ymd', strtotime((string) $order['created_at']));
        }

        return $prefix . '-' . $date . '-' . $id;
    }

    private function sellerInfo(): array
    {
        return [
            'name' => trim((string) $this->envService->get('APP_NAME', 'Smile AI')),
            'email' => trim((string) $this->envService->get('MAIL_FROM_ADDRESS', '')),
            'website' => trim((string) $this->envService->get('APP_URL', '')),
            'address' => trim((string) $this->envService->get('COMPANY_ADDRESS', '')),
            'tax_id' => trim((string) $this->envService->get('COMPANY_TAX_ID', '')),
        ];
    }
}
