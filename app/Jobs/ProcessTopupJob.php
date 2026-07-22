<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Services\G2BulkService;
use App\Services\TelegramNotificationService;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

class ProcessTopupJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    protected string $orderId;

    public function __construct(string $orderId)
    {
        $this->orderId = $orderId;
        $this->onQueue('topup');
    }

    public function handle(G2BulkService $g2bulkService, TelegramNotificationService $telegramService): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::error("ProcessTopupJob: Order ID {$this->orderId} not found in database.");
            return;
        }

        // Prevent duplicate processing if already completed
        if ($order->status === OrderStatus::COMPLETED) {
            Log::info("ProcessTopupJob: Order {$order->order_no} is already COMPLETED. Skipping execution.");
            return;
        }

        // Update status to processing
        $order->status = OrderStatus::PROCESSING;
        $order->save();

        $package = \App\Models\Package::find($order->package_id);
        $providerGameCode = $order->provider_game_code ?? ($package->provider_game_code ?? 'mlbb');
        $providerCatalogueName = $order->provider_catalogue_name ?? ($package->provider_catalogue_name ?? $order->package_name);

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
        } else {
            $code = $res['code'] ?? 'ERROR';
            $msg = $res['message'] ?? 'Topup failed.';

            // REQUIREMENT: Provider unavailable or insufficient wholesaler balance -> WAITING_PROVIDER_BALANCE
            if (in_array($code, ['PROVIDER_UNAVAILABLE', 'INSUFFICIENT_BALANCE', 'WAITING_PROVIDER_BALANCE'])) {
                $order->status = OrderStatus::WAITING_PROVIDER_BALANCE;
                $order->waiting_reason = "Provider balance insufficient or offline ({$code}: {$msg})";
                $order->provider_status_snapshot = [
                    'code' => $code,
                    'message' => $msg,
                    'timestamp' => now()->toIso8601String(),
                ];
                $order->retry_attempts = ($order->retry_attempts ?? 0) + 1;
                $order->save();

                Log::warning("Order {$order->order_no} placed in WAITING_PROVIDER_BALANCE queue due to: {$code} - {$msg}");

                // Notify admin of waiting queue increase
                $waitingCount = Order::where('status', OrderStatus::WAITING_PROVIDER_BALANCE)->count();
                $threshold = (int) config('services.provider.queue_threshold', 5);
                if ($waitingCount >= $threshold) {
                    $telegramService->notifyQueueThresholdExceeded($waitingCount, $threshold);
                }
            } elseif ($code === 'OUT_OF_STOCK') {
                $order->status = OrderStatus::WAITING_VERIFICATION;
                $order->waiting_reason = "Provider out of stock";
                $order->save();

                $telegramService->notifyOutOfStock(
                    $order->order_no,
                    $order->game_name,
                    $order->package_name
                );
            } else {
                // Other unexpected failures -> WAITING_VERIFICATION for admin review (Customer money is preserved)
                $order->status = OrderStatus::WAITING_VERIFICATION;
                $order->waiting_reason = "Code: {$code} - {$msg}";
                $order->save();

                $telegramService->notifyTopupFailed(
                    $order->order_no,
                    $order->game_name,
                    "Code: {$code} - {$msg}"
                );
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessTopupJob failed permanently for order {$this->orderId}: " . $exception->getMessage());
        $order = Order::find($this->orderId);
        if ($order && $order->status !== OrderStatus::COMPLETED) {
            // Safety: Move to WAITING_PROVIDER_BALANCE rather than failing paid customer order
            $order->status = OrderStatus::WAITING_PROVIDER_BALANCE;
            $order->waiting_reason = "Queue exception: " . $exception->getMessage();
            $order->save();
        }
    }
}
