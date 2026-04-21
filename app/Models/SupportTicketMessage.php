<?php

declare(strict_types=1);

namespace App\Models;

final class SupportTicketMessage
{
    public const TABLE = 'Support_Ticket_Messages';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'ticket_id' => 'uuid',
            'sender_role' => 'enum:user,admin,system',
            'sender_user_id' => 'uuid|null',
            'message' => 'text',
            'created_at' => 'timestamp',
        ];
    }
}
