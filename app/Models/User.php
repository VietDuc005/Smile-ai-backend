<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public const TABLE = 'Users';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'email' => 'string',
            'role' => 'enum:admin,user',
            'status' => 'enum:active,blocked',
            'blocked_reason' => 'text|null',
            'blocked_at' => 'timestamp|null',
            'last_login_at' => 'timestamp|null',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public static function roles(): array
    {
        return [
            'admin',
            'user',
        ];
    }

    public static function statuses(): array
    {
        return [
            'active',
            'blocked',
        ];
    }
}
