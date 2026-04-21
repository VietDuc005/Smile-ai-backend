<?php

declare(strict_types=1);

namespace App\Models;

final class OrderItem
{
    public const TABLE = 'Order_Items';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'order_id' => 'uuid',
            'product_id' => 'uuid',
            'digital_account_id' => 'uuid|null',
            'unit_price' => 'decimal:10,2',
            'expires_at' => 'timestamp|null',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }
}
