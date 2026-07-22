<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\G2BulkService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CheckG2BulkBalance extends Command
{
    protected $signature = 'g2bulk:check-balance {--threshold=20 : Low balance alert threshold in USD}';
    protected $description = 'Scheduled 5-minute monitoring job for G2Bulk wholesaler account wallet balance & alerts';

    public function handle(G2BulkService $g2bulkService, TelegramNotificationService $telegramService)
    {
        $thresholdUsd = (float)$this->option('threshold');
        $this->info("Checking G2Bulk Wholesaler Wallet Balance (Low threshold: \${$thresholdUsd})...");

        // Force refresh balance from G2Bulk API
        $res = $g2bulkService->getWalletBalance(true);

        if (!$res['success']) {
            $this->error("Failed to query G2Bulk wallet balance API.");
            return 1;
        }

        $balance = (float)($res['balance'] ?? 0.0);
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

        Cache::forget('g2bulk_circuit_breaker_active');

        if ($balance < $thresholdUsd) {
            Log::warning("G2Bulk wholesaler account balance is LOW: \$" . number_format($balance, 2) . " (Threshold: \${$thresholdUsd})");

            // Send Telegram Low Balance Alert to Admin
            $telegramService->notifyLowBalance($balance, $thresholdUsd);
            $this->warn("WARNING: Balance is below \${$thresholdUsd}. Telegram warning sent.");
        } else {
            $this->info("✓ Balance is healthy (\$" . number_format($balance, 2) . ").");
        }

        return 0;
    }
}
