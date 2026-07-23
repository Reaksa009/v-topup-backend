<?php

namespace App\Enums;

class ProviderError
{
    public const OUT_OF_STOCK = 'OUT_OF_STOCK';
    public const CATALOGUE_INACTIVE = 'CATALOGUE_INACTIVE';
    public const CATALOGUE_NOT_FOUND = 'CATALOGUE_NOT_FOUND';
    public const LOW_BALANCE = 'LOW_BALANCE';
    public const INVALID_PLAYER = 'INVALID_PLAYER';
    public const INVALID_SERVER = 'INVALID_SERVER';
    public const NETWORK_TIMEOUT = 'NETWORK_TIMEOUT';
    public const AUTH_FAILED = 'AUTH_FAILED';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    /**
     * Map raw provider response message and HTTP status code into standardized internal error code.
     */
    public static function mapG2BulkError(?string $rawMessage, int $httpStatus = 400): string
    {
        $msg = strtolower((string)$rawMessage);

        if (str_contains($msg, 'stock') || str_contains($msg, 'out of stock') || str_contains($msg, 'sold out') || str_contains($msg, 'unavailable')) {
            return self::OUT_OF_STOCK;
        }

        if (str_contains($msg, 'inactive') || str_contains($msg, 'disabled')) {
            return self::CATALOGUE_INACTIVE;
        }

        if ($httpStatus === 404 || str_contains($msg, 'not found') || str_contains($msg, 'catalogue')) {
            return self::CATALOGUE_NOT_FOUND;
        }

        if (str_contains($msg, 'balance') || str_contains($msg, 'insufficient')) {
            return self::LOW_BALANCE;
        }

        if (str_contains($msg, 'server') || str_contains($msg, 'zone')) {
            return self::INVALID_SERVER;
        }

        if (str_contains($msg, 'player') || str_contains($msg, 'user_id') || str_contains($msg, 'user id') || str_contains($msg, 'invalid id') || str_contains($msg, 'account')) {
            return self::INVALID_PLAYER;
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'connection') || $httpStatus === 504) {
            return self::NETWORK_TIMEOUT;
        }

        if ($httpStatus === 401 || $httpStatus === 403 || str_contains($msg, 'unauthorized') || str_contains($msg, 'api key')) {
            return self::AUTH_FAILED;
        }

        return self::UNKNOWN_ERROR;
    }

    /**
     * Convert internal error code to customer-friendly localized message.
     */
    public static function getCustomerFriendlyMessage(string $errorCode): string
    {
        switch ($errorCode) {
            case self::OUT_OF_STOCK:
            case self::CATALOGUE_INACTIVE:
            case self::CATALOGUE_NOT_FOUND:
                return 'This package is temporarily unavailable from the provider.';
            case self::LOW_BALANCE:
                return 'Top-up service is temporarily undergoing provider maintenance. Please try again shortly.';
            case self::INVALID_PLAYER:
                return 'The entered Player ID is invalid for this game.';
            case self::INVALID_SERVER:
                return 'The entered Server ID is invalid for this game.';
            case self::NETWORK_TIMEOUT:
                return 'Provider gateway timed out. Your request will be retried automatically.';
            default:
                return 'An unexpected issue occurred while processing top-up. Our team has been notified.';
        }
    }
}
