<?php

declare(strict_types=1);

namespace App\Models;

final class Order
{
    public const TABLE = 'Orders';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'user_id' => 'uuid',
            'customer_email' => 'string|null',
            'transfer_syntax' => 'string',
            'payment_provider' => 'string',
            'payment_qr_url' => 'text|null',
            'payment_payload' => 'json-text|null',
            'status' => 'enum:pending,completed,failed,cancelled',
            'total_amount' => 'decimal:10,2',
            'paid_at' => 'timestamp|null',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public static function statuses(): array
    {
        return [
            'pending',
            'completed',
            'failed',
            'cancelled',
        ];
    }
}
