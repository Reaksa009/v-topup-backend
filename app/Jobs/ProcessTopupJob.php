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

        // Prevent duplicate processing if already completed or waiting verification
        if (in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::WAITING_VERIFICATION])) {
            Log::info("ProcessTopupJob: Order {$order->order_no} is already in state {$order->status}. Skipping execution.");
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

            if ($code === 'OUT_OF_STOCK') {
                $order->status = OrderStatus::WAITING_VERIFICATION;
                $order->save();

                $telegramService->notifyOutOfStock(
                    $order->order_no,
                    $order->game_name,
                    $order->package_name
                );
            } else {
                $order->status = OrderStatus::WAITING_VERIFICATION;
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
        if ($order) {
            $order->status = OrderStatus::FAILED;
            $order->save();
        }
    }
}
