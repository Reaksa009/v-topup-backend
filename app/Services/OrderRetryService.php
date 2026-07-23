<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

class OrderRetryService
{
    /**
     * Backoff schedule in minutes:
     * Retry #0: 10m, #1: 30m, #2: 60m, #3: 120m, #4: 360m, #5: 720m, #6+: 1440m (24h)
     */
    public const BACKOFF_SCHEDULE = [10, 30, 60, 120, 360, 720, 1440];
    public const MAX_RETRIES = 10;

    public static function getBackoffMinutes(int $retryCount): int
    {
        $idx = min($retryCount, count(self::BACKOFF_SCHEDULE) - 1);
        return self::BACKOFF_SCHEDULE[$idx];
    }

    /**
     * Scan and retry orders in WAITING_PROVIDER status whose next_retry_at has arrived.
     */
    public function retryWaitingOrders(): array
    {
        $now = now();
        $orders = Order::where('status', OrderStatus::WAITING_PROVIDER)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', $now);
            })
            ->where(function ($query) {
                $query->whereNull('retry_count')
                      ->orWhere('retry_count', '<', self::MAX_RETRIES);
            })
            ->get();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        $g2bulkService = app(G2BulkService::class);
        $telegramService = app(TelegramNotificationService::class);

        foreach ($orders as $order) {
            $processed++;
            $package = Package::find($order->package_id);

            // Re-verify package stock status prior to retry
            if ($package && $package->stock_status === 'out_of_stock') {
                $nextCount = ((int)($order->retry_count ?? 0)) + 1;
                $nextMinutes = self::getBackoffMinutes($nextCount);
                $order->retry_count = $nextCount;
                $order->last_retry_at = now();
                $order->next_retry_at = now()->addMinutes($nextMinutes);
                $order->estimated_retry_at = now()->addMinutes($nextMinutes);
                $order->save();

                if ($nextCount >= self::MAX_RETRIES) {
                    $telegramService->notifyRepeatedRetryFailure(
                        $order->order_no,
                        $nextCount,
                        "Exceeded maximum retries ({$nextCount}/10). Package remains out of stock."
                    );
                }
                $failed++;
                continue;
            }

            // Package is available or unknown -> Attempt G2Bulk fulfillment
            $providerGameCode = $order->provider_game_code ?? ($package->provider_game_code ?? 'mlbb');
            $providerCatalogueName = $order->provider_catalogue_name ?? ($package->provider_catalogue_name ?? $order->package_name);

            $order->status = OrderStatus::PROCESSING;
            $order->save();

            $res = $g2bulkService->placeOrder(
                $providerGameCode,
                $providerCatalogueName,
                $order->player_id,
                $order->server_id,
                $order->order_no
            );

            if ($res['success']) {
                if (isset($res['data']['order_id'])) {
                    $order->g2b_order_id = $res['data']['order_id'];
                }
                $order->status = OrderStatus::COMPLETED;
                $order->completed_at = now()->toDateTimeString();
                $order->save();

                $telegramService->notifyTopupSuccess(
                    $order->order_no,
                    $order->game_name,
                    $order->package_name,
                    $order->player_id,
                    $order->g2b_order_id ?? null
                );
                $succeeded++;
            } else {
                $nextCount = ((int)($order->retry_count ?? 0)) + 1;
                $nextMinutes = self::getBackoffMinutes($nextCount);

                $order->status = OrderStatus::WAITING_PROVIDER;
                $order->waiting_reason = "Retry #{$nextCount} failed: " . ($res['message'] ?? 'Provider unavailable');
                $order->retry_count = $nextCount;
                $order->last_retry_at = now();
                $order->next_retry_at = now()->addMinutes($nextMinutes);
                $order->estimated_retry_at = now()->addMinutes($nextMinutes);
                $order->provider_status_snapshot = $res;
                $order->save();

                if ($nextCount >= self::MAX_RETRIES) {
                    $telegramService->notifyRepeatedRetryFailure(
                        $order->order_no,
                        $nextCount,
                        "Exceeded maximum retries ({$nextCount}/10). Last failure: " . ($res['message'] ?? 'Unknown error')
                    );
                }
                $failed++;
            }
        }

        return [
            'total_queued' => count($orders),
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }
}
