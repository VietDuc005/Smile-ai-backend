<?php

declare(strict_types=1);

namespace App\Models;

final class Product
{
    public const TABLE = 'Products';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'name' => 'string',
            'description' => 'text',
            'features' => 'json-text|null',
            'price' => 'decimal:10,2',
            'duration_days' => 'integer',
            'image_url' => 'string|null',
            'requires_inventory' => 'boolean',
            'inventory_allocation_mode' => 'enum:exclusive,shared',
            'requires_customer_email' => 'boolean',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }
}
