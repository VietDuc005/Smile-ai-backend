<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Voucher;
use PDO;

final class VoucherService
{
    private DatabaseService $databaseService;

    public function __construct(?DatabaseService $databaseService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
    }

    public function list(): array
    {
        $statement = $this->databaseService->connection()->query(
            'SELECT * FROM ' . Voucher::TABLE . ' ORDER BY created_at DESC'
        );

        $rows = $statement->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrateVoucher($row);
            }
        }

        return $result;
    }

    public function findById(string $id): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT * FROM ' . Voucher::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => trim($id)]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateVoucher($row) : null;
    }

    public function findByCode(string $code): ?array
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT * FROM ' . Voucher::TABLE . ' WHERE code = :code LIMIT 1'
        );
        $statement->execute(['code' => strtoupper(trim($code))]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateVoucher($row) : null;
    }

    public function create(array $payload): array
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $type = $this->normalizeType((string) ($payload['discount_type'] ?? 'fixed'));
        $value = (float) ($payload['discount_value'] ?? 0);
        $maxUses = $this->normalizeMaxUses($payload['max_uses'] ?? null);
        $minAmount = max(0, (float) ($payload['min_order_amount'] ?? 0));
        $maxDiscount = $this->normalizeMaxDiscount($payload['max_discount_amount'] ?? null);
        $expiresAt = $this->normalizeExpiresAt($payload['expires_at'] ?? null);
        $isActive = isset($payload['is_active']) ? (bool) $payload['is_active'] : true;

        $this->validateCode($code);
        $this->validateValue($type, $value);
        $this->assertCodeUnique($code);

        $id = $this->uuidV4();
        $statement = $this->databaseService->connection()->prepare(
            'INSERT INTO ' . Voucher::TABLE . ' (id, code, discount_type, discount_value, max_uses, min_order_amount, max_discount_amount, expires_at, is_active) VALUES (:id, :code, :discount_type, :discount_value, :max_uses, :min_order_amount, :max_discount_amount, :expires_at, :is_active)'
        );
        $statement->execute([
            'id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $value,
            'max_uses' => $maxUses,
            'min_order_amount' => $minAmount,
            'max_discount_amount' => $maxDiscount,
            'expires_at' => $expiresAt,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $this->findById($id) ?? [];
    }

    public function update(string $id, array $payload): array
    {
        $voucher = $this->findById($id);

        if ($voucher === null) {
            throw new NotFoundException('Voucher was not found.');
        }

        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('code', $payload)) {
            $code = strtoupper(trim((string) $payload['code']));
            $this->validateCode($code);

            if ($code !== $voucher['code']) {
                $this->assertCodeUnique($code);
            }

            $fields[] = 'code = :code';
            $params['code'] = $code;
        }

        if (array_key_exists('discount_type', $payload)) {
            $fields[] = 'discount_type = :discount_type';
            $params['discount_type'] = $this->normalizeType((string) $payload['discount_type']);
        }

        if (array_key_exists('discount_value', $payload)) {
            $type = $params['discount_type'] ?? $voucher['discount_type'];
            $value = (float) $payload['discount_value'];
            $this->validateValue($type, $value);
            $fields[] = 'discount_value = :discount_value';
            $params['discount_value'] = $value;
        }

        if (array_key_exists('max_uses', $payload)) {
            $fields[] = 'max_uses = :max_uses';
            $params['max_uses'] = $this->normalizeMaxUses($payload['max_uses']);
        }

        if (array_key_exists('min_order_amount', $payload)) {
            $fields[] = 'min_order_amount = :min_order_amount';
            $params['min_order_amount'] = max(0, (float) $payload['min_order_amount']);
        }

        if (array_key_exists('max_discount_amount', $payload)) {
            $fields[] = 'max_discount_amount = :max_discount_amount';
            $params['max_discount_amount'] = $this->normalizeMaxDiscount($payload['max_discount_amount']);
        }

        if (array_key_exists('expires_at', $payload)) {
            $fields[] = 'expires_at = :expires_at';
            $params['expires_at'] = $this->normalizeExpiresAt($payload['expires_at']);
        }

        if (array_key_exists('is_active', $payload)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = ((bool) $payload['is_active']) ? 1 : 0;
        }

        if ($fields !== []) {
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $statement = $this->databaseService->connection()->prepare(
                'UPDATE ' . Voucher::TABLE . ' SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $statement->execute($params);
        }

        return $this->findById($id) ?? [];
    }

    public function delete(string $id): void
    {
        $voucher = $this->findById($id);

        if ($voucher === null) {
            throw new NotFoundException('Voucher was not found.');
        }

        $statement = $this->databaseService->connection()->prepare(
            'DELETE FROM ' . Voucher::TABLE . ' WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    public function validateForOrder(string $code, float $orderAmount): array
    {
        $voucher = $this->findByCode($code);

        if ($voucher === null) {
            throw new ValidationException('Mã voucher không tồn tại.');
        }

        if (!$voucher['is_active']) {
            throw new ValidationException('Mã voucher đã bị vô hiệu hóa.');
        }

        if ($voucher['expires_at'] !== null && strtotime($voucher['expires_at']) < time()) {
            throw new ValidationException('Mã voucher đã hết hạn.');
        }

        if ($voucher['max_uses'] !== null && $voucher['current_uses'] >= $voucher['max_uses']) {
            throw new ValidationException('Mã voucher đã hết lượt sử dụng.');
        }

        if ($orderAmount < $voucher['min_order_amount'] && $voucher['min_order_amount'] > 0) {
            throw new ValidationException(
                'Đơn hàng tối thiểu ' . number_format($voucher['min_order_amount'], 0, ',', '.') . 'đ để sử dụng voucher này.'
            );
        }

        $discountAmount = $this->calculateDiscount($voucher, $orderAmount);

        return [
            'voucher_id' => $voucher['id'],
            'discount_amount' => $discountAmount,
            'voucher' => $voucher,
        ];
    }

    public function calculateDiscount(array $voucher, float $amount): float
    {
        if ($voucher['discount_type'] === 'percent') {
            $discount = $amount * ((float) $voucher['discount_value'] / 100.0);

            if ($voucher['max_discount_amount'] !== null) {
                $discount = min($discount, (float) $voucher['max_discount_amount']);
            }
        } else {
            $discount = (float) $voucher['discount_value'];
        }

        return round(min($discount, $amount), 2);
    }

    public function consumeVoucher(string $voucherId, PDO $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE ' . Voucher::TABLE . '
             SET current_uses = current_uses + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND is_active = 1
               AND (max_uses IS NULL OR current_uses < max_uses)
               AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $statement->execute(['id' => $voucherId]);

        if ($statement->rowCount() === 0) {
            throw new ValidationException('Mã voucher không còn khả dụng.');
        }
    }

    private function hydrateVoucher(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'discount_type' => (string) ($row['discount_type'] ?? 'fixed'),
            'discount_value' => (float) ($row['discount_value'] ?? 0),
            'max_uses' => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            'current_uses' => (int) ($row['current_uses'] ?? 0),
            'min_order_amount' => (float) ($row['min_order_amount'] ?? 0),
            'max_discount_amount' => $row['max_discount_amount'] !== null ? (float) $row['max_discount_amount'] : null,
            'expires_at' => $row['expires_at'] ?? null,
            'is_active' => $this->dbBoolean($row['is_active'] ?? true),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        if (!in_array($normalized, ['fixed', 'percent'], true)) {
            throw new ValidationException('Field discount_type must be "fixed" or "percent".');
        }

        return $normalized;
    }

    private function validateCode(string $code): void
    {
        if ($code === '') {
            throw new ValidationException('Field code is required.');
        }

        if (strlen($code) > 50) {
            throw new ValidationException('Field code must not exceed 50 characters.');
        }

        if (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
            throw new ValidationException('Field code may only contain uppercase letters, digits, hyphens, and underscores.');
        }
    }

    private function validateValue(string $type, float $value): void
    {
        if ($value <= 0) {
            throw new ValidationException('Field discount_value must be greater than 0.');
        }

        if ($type === 'percent' && $value > 100) {
            throw new ValidationException('Field discount_value must not exceed 100 for percent type.');
        }
    }

    private function assertCodeUnique(string $code): void
    {
        $statement = $this->databaseService->connection()->prepare(
            'SELECT COUNT(*) AS total FROM ' . Voucher::TABLE . ' WHERE code = :code'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        if (is_array($row) && (int) ($row['total'] ?? 0) > 0) {
            throw new ValidationException('Voucher code already exists.');
        }
    }

    private function normalizeMaxUses(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (int) $value;

        if ($normalized <= 0) {
            throw new ValidationException('Field max_uses must be a positive integer or null for unlimited.');
        }

        return $normalized;
    }

    private function normalizeMaxDiscount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (float) $value;

        if ($normalized <= 0) {
            throw new ValidationException('Field max_discount_amount must be greater than 0 or null.');
        }

        return $normalized;
    }

    private function normalizeExpiresAt(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $ts = strtotime(trim((string) $value));

        if ($ts === false || $ts <= 0) {
            throw new ValidationException('Field expires_at is not a valid datetime.');
        }

        return date('Y-m-d H:i:s', $ts);
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

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
