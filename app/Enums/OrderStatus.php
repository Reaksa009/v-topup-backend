<?php

namespace App\Enums;

class OrderStatus
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAID = 'paid';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const WAITING_VERIFICATION = 'waiting_verification';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /**
     * Get all valid order statuses.
     */
    public static function all(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::PAID,
            self::PROCESSING,
            self::COMPLETED,
            self::WAITING_VERIFICATION,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}
