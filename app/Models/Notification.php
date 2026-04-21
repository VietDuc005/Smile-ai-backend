<?php

declare(strict_types=1);

namespace App\Models;

final class Notification
{
    public const TABLE = 'Notifications';

    public const TYPE_ORDER_COMPLETED = 'order_completed';
    public const TYPE_ACCOUNT_GRANTED = 'account_granted';
    public const TYPE_RENEWAL_REMINDER = 'renewal_reminder';
}
