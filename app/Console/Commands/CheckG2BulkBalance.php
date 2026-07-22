<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\G2BulkService;
use App\Services\TelegramNotificationService;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Jobs\ProcessTopupJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CheckG2BulkBalance extends Command
{
    protected $signature = 'g2bulk:check-balance {--threshold=20 : Low balance alert threshold in USD}';
    protected $description = 'Scheduled 5-minute monitoring job for G2Bulk wholesaler wallet balance, alerts, and automated FIFO retry processing';

    public function handle(G2BulkService $g2bulkService, TelegramNotificationService $telegramService)
    {
        $thresholdUsd = (float) $this->option('threshold');
        $this->info("Checking G2Bulk Wholesaler Wallet Balance (Low threshold: \${$thresholdUsd})...");

        // Force refresh balance from G2Bulk API
        $res = $g2bulkService->getWalletBalance(true);

        if (!$res['success']) {
            $this->error("Failed to query G2Bulk wallet balance API.");
            return 1;
        }

        $balance = (float) ($res['balance'] ?? 0.0);
        $username = $res['username'] ?? 'N/A';

        $this->info("Current G2Bulk Balance for '{$username}': \$" . number_format($balance, 2));

        if ($balance <= 0.0) {
            Log::critical("G2Bulk wholesaler account balance is EXHAUSTED ($0.00)! Circuit breaker activated.");
            Cache::put('g2bulk_circuit_breaker_active', true, 600);

            // Send Telegram Critical Alert to Admin
            $telegramService->notifyZeroBalance($balance);
            $this->error("CRITICAL: Balance is $0.00. Circuit breaker activated and Telegram alert sent.");
            return 0;
        }

        $wasCircuitBreakerActive = Cache::has('g2bulk_circuit_breaker_active');
        Cache::forget('g2bulk_circuit_breaker_active');

        // Check if there are orders in WAITING_PROVIDER_BALANCE status
        $waitingOrders = Order::where('status', OrderStatus::WAITING_PROVIDER_BALANCE)
            ->orderBy('created_at', 'asc') // FIFO Order
            ->get();

        $waitingCount = $waitingOrders->count();

        if ($wasCircuitBreakerActive && $waitingCount > 0) {
            $telegramService->notifyWalletRestored($balance, $waitingCount);
        }

        if ($balance < $thresholdUsd) {
            Log::warning("G2Bulk wholesaler account balance is LOW: \$" . number_format($balance, 2) . " (Threshold: \${$thresholdUsd})");
            $telegramService->notifyLowBalance($balance, $thresholdUsd);
            $this->warn("WARNING: Balance is below \${$thresholdUsd}. Telegram warning sent.");
        } else {
            $this->info("✓ Balance is healthy (\$" . number_format($balance, 2) . ").");
        }

        // Automatic FIFO Retry Processing for WAITING_PROVIDER_BALANCE orders
        if ($waitingCount > 0 && config('services.provider.auto_retry_enabled', true)) {
            $this->info("Found {$waitingCount} order(s) in WAITING_PROVIDER_BALANCE. Starting FIFO retry queue...");
            $maxAttempts = (int) config('services.provider.max_retry_attempts', 10);

            foreach ($waitingOrders as $order) {
                // Check if max retry attempts exceeded
                if (($order->retry_attempts ?? 0) >= $maxAttempts) {
                    $this->warn("Order {$order->order_no} reached max retry attempts ({$maxAttempts}). Notifying admin.");
                    $telegramService->notifyRepeatedRetryFailure($order->order_no, $order->retry_attempts, $order->waiting_reason ?? 'Max retry attempts reached');
                    continue;
                }

                // Distributed Lock to guarantee exactly-once execution and prevent race conditions
                $lockKey = "retry_order_lock_{$order->id}";
                $lock = Cache::lock($lockKey, 30);

                if ($lock->get()) {
                    try {
                        $this->info("Retrying order: {$order->order_no} (Attempt " . (($order->retry_attempts ?? 0) + 1) . ")...");
                        ProcessTopupJob::dispatchSync($order->id);
                    } catch (\Exception $e) {
                        Log::error("Error processing retry for order {$order->order_no}: " . $e->getMessage());
                    } finally {
                        $lock->release();
                    }
                } else {
                    $this->warn("Order {$order->order_no} is currently locked by another worker. Skipping.");
                }
            }
        }

        return 0;
    }
}
