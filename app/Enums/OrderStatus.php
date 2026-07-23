<?php

namespace App\Enums;

class OrderStatus
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAID = 'paid';
    public const WAITING_PROVIDER = 'waiting_provider';
    public const WAITING_PROVIDER_BALANCE = 'waiting_provider_balance';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const WAITING_VERIFICATION = 'waiting_verification';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';

    /**
     * Get all valid order statuses.
     */
    public static function all(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::PAID,
            self::WAITING_PROVIDER,
            self::WAITING_PROVIDER_BALANCE,
            self::PROCESSING,
            self::COMPLETED,
            self::WAITING_VERIFICATION,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ];
    }
}
