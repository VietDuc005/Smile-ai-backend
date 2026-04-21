<?php

declare(strict_types=1);

namespace App\Models;

final class DigitalAccount
{
    public const TABLE = 'Digital_Accounts';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'product_id' => 'uuid',
            'username' => 'string',
            'password' => 'encrypted-string',
            'status' => 'enum:available,sold,banned',
            'expires_at' => 'timestamp|null',
            'seat_capacity' => 'integer',
            'seat_used' => 'integer',
            'added_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public static function statuses(): array
    {
        return [
            'available',
            'sold',
            'banned',
        ];
    }
}
