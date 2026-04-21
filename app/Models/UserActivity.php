<?php

declare(strict_types=1);

namespace App\Models;

final class UserActivity
{
    public const TABLE = 'User_Activities';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'user_id' => 'uuid',
            'action' => 'string',
            'description' => 'text',
            'metadata' => 'text|null',
            'created_at' => 'timestamp',
        ];
    }
}
