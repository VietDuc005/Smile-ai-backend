<?php

declare(strict_types=1);

namespace App\Models;

final class PaymentWebhook
{
    public const TABLE = 'Payment_Webhooks';

    public static function schema(): array
    {
        return [
            'id' => 'uuid',
            'provider' => 'string',
            'event_key' => 'string',
            'external_id' => 'string|null',
            'direction' => 'string',
            'amount' => 'decimal:10,2',
            'transfer_content' => 'text|null',
            'transfer_syntax' => 'string|null',
            'status' => 'enum:received,matched,unmatched,ignored,failed',
            'note' => 'text|null',
            'raw_payload' => 'text|null',
            'headers' => 'text|null',
            'matched_order_id' => 'uuid|null',
            'processed_at' => 'timestamp|null',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }
}
