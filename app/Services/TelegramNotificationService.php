<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    protected string $botToken;
    protected string $adminChatId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token') ?? '';
        $this->adminChatId = config('services.telegram.admin_chat_id') ?? '';
    }

    /**
     * Send message alert to Administrator Telegram Group.
     */
    public function sendAdminAlert(string $message): bool
    {
        if (empty($this->botToken) || empty($this->adminChatId)) {
            Log::warning('Telegram Bot Token or Admin Chat ID not configured. Skipping alert.');
            return false;
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->adminChatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            if ($response->failed()) {
                Log::error('Telegram API response error: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram notification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper notification: Payment Success
     */
    public function notifyPaymentSuccess(string $orderNo, float $amountUsd, string $method): bool
    {
        $msg = "💳 <b>Payment Received!</b>\n\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Amount:</b> \${$amountUsd}\n" .
               "• <b>Method:</b> {$method}\n" .
               "• <b>Status:</b> PAID (Queued for topup dispatch)";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Topup Success
     */
    public function notifyTopupSuccess(string $orderNo, string $gameName, string $packageName, string $playerId, ?string $providerOrderId): bool
    {
        $msg = "✅ <b>Topup Completed!</b>\n\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Game:</b> {$gameName}\n" .
               "• <b>Package:</b> {$packageName}\n" .
               "• <b>Player ID:</b> {$playerId}\n" .
               "• <b>Provider Order ID:</b> " . ($providerOrderId ?: 'N/A');
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Topup Failed
     */
    public function notifyTopupFailed(string $orderNo, string $gameName, string $reason): bool
    {
        $msg = "❌ <b>Topup Processing Failed!</b>\n\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Game:</b> {$gameName}\n" .
               "• <b>Reason:</b> {$reason}\n" .
               "• <b>Status:</b> Moved to WAITING_VERIFICATION";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Out Of Stock
     */
    public function notifyOutOfStock(string $orderNo, string $gameName, string $packageName): bool
    {
        $msg = "⚠️ <b>Provider Out of Stock!</b>\n\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Game:</b> {$gameName}\n" .
               "• <b>Package:</b> {$packageName}\n" .
               "• <b>Action:</b> Order queued in WAITING_VERIFICATION for admin manual retry.";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Provider API Down
     */
    public function notifyApiDown(string $providerName, string $endpoint, string $errorDetails): bool
    {
        $msg = "🚨 <b>Provider API Gateway Offline!</b>\n\n" .
               "• <b>Provider:</b> {$providerName}\n" .
               "• <b>Endpoint:</b> <code>{$endpoint}</code>\n" .
               "• <b>Error:</b> {$errorDetails}";
        return $this->sendAdminAlert($msg);
    }
}
