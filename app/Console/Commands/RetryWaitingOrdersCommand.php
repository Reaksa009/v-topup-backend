<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OrderRetryService;

class RetryWaitingOrdersCommand extends Command
{
    protected $signature = 'orders:retry-waiting';
    protected $description = 'Automatically retry orders in WAITING_PROVIDER status using exponential backoff schedule';

    public function handle(OrderRetryService $retryService): int
    {
        $this->info("Scanning WAITING_PROVIDER orders for automatic retry...");
        $res = $retryService->retryWaitingOrders();

        $this->info("Retry engine summary - Queued: {$res['total_queued']}, Processed: {$res['processed']}, Succeeded: {$res['succeeded']}, Failed: {$res['failed']}");
        return Command::SUCCESS;
    }
}
