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
     * Helper notification: Provider Out Of Stock Alert
     */
    public function notifyOutOfStock(string $orderNo, string $gameName, string $packageName, string $reason = 'Provider out of stock', int $ordersWaitingCount = 0, float $providerBalance = 0.0): bool
    {
        $timeStr = now()->toDateTimeString();
        $msg = "🚨 <b>PROVIDER_OUT_OF_STOCK</b>\n\n" .
               "• <b>Game:</b> {$gameName}\n" .
               "• <b>Package:</b> {$packageName}\n" .
               "• <b>Provider:</b> G2Bulk\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Reason:</b> {$reason}\n" .
               "• <b>Orders Waiting:</b> {$ordersWaitingCount}\n" .
               "• <b>Provider Balance:</b> \$" . number_format($providerBalance, 2) . "\n" .
               "• <b>Time:</b> {$timeStr}";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Provider Stock Recovered Alert
     */
    public function notifyProviderRecovered(string $gameName, string $packageName, int $waitingDispatchedCount = 0): bool
    {
        $timeStr = now()->toDateTimeString();
        $msg = "🟢 <b>PROVIDER_RECOVERED</b>\n\n" .
               "• <b>Game:</b> {$gameName}\n" .
               "• <b>Package:</b> {$packageName}\n" .
               "• <b>Provider:</b> G2Bulk\n" .
               "• <b>Recovered Time:</b> {$timeStr}\n" .
               "• <b>Waiting Orders Dispatched:</b> {$waitingDispatchedCount}";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Low Provider Balance Warning
     */
    public function notifyLowBalance(float $currentBalance, float $thresholdUsd): bool
    {
        $msg = "⚠️ <b>G2Bulk Low Balance Warning!</b>\n\n" .
               "• <b>Current Balance:</b> \$" . number_format($currentBalance, 2) . "\n" .
               "• <b>Threshold Alert:</b> \$" . number_format($thresholdUsd, 2) . "\n" .
               "• <b>Action Required:</b> Deposit funds into G2Bulk wholesaler wallet soon to avoid service interruption.";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Zero Provider Balance Critical Alert
     */
    public function notifyZeroBalance(float $currentBalance): bool
    {
        $msg = "🚨 <b>CRITICAL: G2Bulk Balance Exhausted ($0.00)!</b>\n\n" .
               "• <b>Current Balance:</b> \$" . number_format($currentBalance, 2) . "\n" .
               "• <b>Status:</b> CIRCUIT BREAKER ACTIVATED. All G2Bulk automated order submissions paused.\n" .
               "• <b>Action Required:</b> Immediately deposit funds on G2Bulk.com to resume automated fulfillment.";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Provider Wallet Restored
     */
    public function notifyWalletRestored(float $newBalance, int $waitingCount): bool
    {
        $msg = "🟢 <b>G2Bulk Wallet Balance Restored!</b>\n\n" .
               "• <b>New Balance:</b> \$" . number_format($newBalance, 2) . "\n" .
               "• <b>Waiting Orders Queue:</b> {$waitingCount} order(s)\n" .
               "• <b>Action:</b> Automated FIFO retry processor dispatched.";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Waiting Queue Threshold Exceeded
     */
    public function notifyQueueThresholdExceeded(int $waitingCount, int $threshold = 5): bool
    {
        $msg = "⚠️ <b>Provider Waiting Queue High!</b>\n\n" .
               "• <b>Current Waiting Orders:</b> {$waitingCount}\n" .
               "• <b>Threshold Limit:</b> {$threshold}\n" .
               "• <b>Action Required:</b> Check provider balance or system status.";
        return $this->sendAdminAlert($msg);
    }

    /**
     * Helper notification: Repeated Retry Failure
     */
    public function notifyRepeatedRetryFailure(string $orderNo, int $attempts, string $reason): bool
    {
        $msg = "🛑 <b>Repeated Order Retry Failure!</b>\n\n" .
               "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
               "• <b>Attempts Count:</b> {$attempts}\n" .
               "• <b>Last Failure Reason:</b> {$reason}\n" .
               "• <b>Action:</b> Requires admin manual review or manual refund.";
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
