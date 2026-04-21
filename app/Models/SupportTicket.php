<?php

declare(strict_types=1);

namespace App\Models;

final class SupportTicket
{
    public const TABLE = 'Support_Tickets';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'user_id' => 'uuid',
            'order_item_id' => 'uuid|null',
            'subject' => 'string',
            'message' => 'text',
            'status' => 'enum:open,in_progress,resolved',
            'last_reply_at' => 'timestamp|null',
            'resolved_at' => 'timestamp|null',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }
}
