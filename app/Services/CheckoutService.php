<?php

namespace App\Services;

use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\GameRepositoryInterface;
use App\Repositories\Contracts\CouponRepositoryInterface;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Enums\OrderStatus;
use App\Jobs\ProcessTopupJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutService
{
    protected OrderRepositoryInterface $orderRepository;
    protected PaymentRepositoryInterface $paymentRepository;
    protected GameRepositoryInterface $gameRepository;
    protected CouponRepositoryInterface $couponRepository;
    protected TelegramNotificationService $telegramService;
    protected G2BulkService $g2bulkService;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        PaymentRepositoryInterface $paymentRepository,
        GameRepositoryInterface $gameRepository,
        CouponRepositoryInterface $couponRepository,
        TelegramNotificationService $telegramService,
        G2BulkService $g2bulkService
    ) {
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
        $this->gameRepository = $gameRepository;
        $this->couponRepository = $couponRepository;
        $this->telegramService = $telegramService;
        $this->g2bulkService = $g2bulkService;
    }

    /**
     * Process checkout form, calculate prices, apply coupons, save orders, upload receipt, notify Telegram.
     */
    public function processCheckout(
        ?User $user,
        array $items,
        string $paymentMethod,
        string $transactionNo,
        $receiptFile,
        ?string $couponCode
    ) {
        return DB::transaction(function () use ($user, $items, $paymentMethod, $transactionNo, $receiptFile, $couponCode) {
            // Prevent duplicate checkouts using the same transaction reference number
            if (Payment::where('transaction_no', $transactionNo)->exists()) {
                throw new \Exception("This transaction number has already been used for another order.");
            }

            $totalSubtotal = 0.0;
            $processedItems = [];

            foreach ($items as $item) {
                $game = $this->gameRepository->find($item['game_id']);
                if (!$game) {
                    throw new \Exception("Game not found.");
                }

                $package = $game->packages()->find($item['package_id']);
                if (!$package) {
                    throw new \Exception("Package not found.");
                }

                $itemSubtotal = (float) $package->price_usd * (int) $item['qty'];
                $totalSubtotal += $itemSubtotal;

                $processedItems[] = [
                    'game' => $game,
                    'package' => $package,
                    'player_id' => $item['player_id'],
                    'server_id' => $item['server_id'] ?? null,
                    'qty' => $item['qty'],
                    'price_usd' => (float) $package->price_usd,
                    'price_khr' => (int) $package->price_khr,
                ];
            }

            // Validate Coupon Code
            $discount = 0.0;
            if ($couponCode) {
                $coupon = $this->couponRepository->findByCode($couponCode);
                if ($coupon && $coupon->isValidForAmount($totalSubtotal)) {
                    $discount = $coupon->calculateDiscount($totalSubtotal);
                    $this->couponRepository->incrementUsage($coupon->id);
                }
            }

            $totalUsd = max(0.0, $totalSubtotal - $discount);
            $totalKhr = (int) round($totalUsd * 4100);

            // Upload Receipt File if provided
            $receiptPath = 'receipts/default.png';
            if ($receiptFile) {
                $filename = time() . '_' . Str::random(10) . '.' . $receiptFile->getClientOriginalExtension();
                $receiptPath = $receiptFile->storeAs('receipts', $filename, 'public');
            }
            $receiptUrl = Storage::url($receiptPath);

            $orderNo = 'ORD-' . strtoupper(Str::random(10));
            $isAutomated = ($paymentMethod === 'khqr_bakong');
            $lastOrder = null;

            foreach ($processedItems as $pItem) {
                $orderData = [
                    'user_id' => $user ? $user->id : null,
                    'order_no' => $orderNo,
                    'game_id' => $pItem['game']->id,
                    'package_id' => $pItem['package']->id,
                    'game_name' => $pItem['game']->name_en,
                    'package_name' => $pItem['package']->name_en,
                    'player_id' => $pItem['player_id'],
                    'server_id' => $pItem['server_id'],
                    'qty' => $pItem['qty'],
                    'original_price_usd' => $pItem['price_usd'],
                    'price_usd' => $pItem['price_usd'],
                    'discount_usd' => $discount,
                    'total_price_usd' => $totalUsd,
                    'total_price_khr' => $totalKhr,
                    'status' => $isAutomated ? OrderStatus::PAID : OrderStatus::PENDING_PAYMENT,
                    'payment_method' => $paymentMethod,
                    'coupon_code' => $couponCode,
                ];

                $order = $this->orderRepository->create($orderData);
                $lastOrder = $order;

                if ($isAutomated) {
                    // Dispatch topup processing job onto Horizon topup queue
                    ProcessTopupJob::dispatch($order->id)->onQueue('topup');
                }
            }

            // Create payment validation record
            $paymentData = [
                'order_no' => $orderNo,
                'transaction_no' => $transactionNo,
                'amount_usd' => $totalUsd,
                'amount_khr' => $totalKhr,
                'payment_method' => $paymentMethod,
                'receipt_image_url' => $receiptUrl,
                'status' => $isAutomated ? 'verified' : 'pending',
            ];
            $this->paymentRepository->create($paymentData);

            // Send Telegram Notification
            $this->telegramService->notifyPaymentSuccess($orderNo, $totalUsd, strtoupper(str_replace('_', ' ', $paymentMethod)));

            return $lastOrder;
        });
    }

    /**
     * Generate KHQR payment from order items and coupon code.
     */
    public function generateKhqrPayment(array $items, ?string $couponCode)
    {
        $totalSubtotal = 0.0;
        foreach ($items as $item) {
            $game = $this->gameRepository->find($item['game_id']);
            if (!$game) {
                throw new \Exception("Game not found.");
            }

            $package = $game->packages()->find($item['package_id']);
            if (!$package) {
                throw new \Exception("Package not found.");
            }

            $itemSubtotal = (float) $package->price_usd * (int) $item['qty'];
            $totalSubtotal += $itemSubtotal;
        }

        $discount = 0.0;
        if ($couponCode) {
            $coupon = $this->couponRepository->findByCode($couponCode);
            if ($coupon && $coupon->isValidForAmount($totalSubtotal)) {
                $discount = $coupon->calculateDiscount($totalSubtotal);
            }
        }

        $totalUsd = max(0.01, $totalSubtotal - $discount);
        $totalUsdFormatted = number_format($totalUsd, 2, '.', '');

        $baseUrl = (string) config('services.khqr.base_url', 'https://api.khqr.link');
        $token = (string) config('services.khqr.token');
        $bakongId = (string) config('services.khqr.bakong_account_id');
        $merchantName = (string) config('services.khqr.merchant_name', 'V-TOPUP-STORE CO., LTD.');

        $response = Http::timeout(8)
            ->withToken($token)
            ->get("{$baseUrl}/v1/khqr/create", [
                'amount' => $totalUsdFormatted,
                'bakongid' => $bakongId,
                'merchantname' => $merchantName,
            ]);

        if ($response->failed()) {
            throw new \Exception("Failed to generate KHQR code from gateway: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Check KHQR payment status by transaction MD5 hash.
     */
    public function checkKhqrStatus(string $md5)
    {
        $baseUrl = (string) config('services.khqr.base_url', 'https://api.khqr.link');
        $token = (string) config('services.khqr.token');

        $response = Http::timeout(8)
            ->withToken($token)
            ->get("{$baseUrl}/v1/khqr/check", [
                'md5' => $md5,
            ]);

        if ($response->failed()) {
            throw new \Exception("Failed to verify payment status: " . $response->body());
        }

        $json = $response->json();

        // If payment verified on KHQR gateway, find associated order and dispatch topup job
        if (isset($json['responseCode']) && (int) $json['responseCode'] === 0) {
            $payment = Payment::where('transaction_no', $md5)->first();
            if ($payment && $payment->status !== 'verified') {
                $payment->status = 'verified';
                $payment->save();

                $orders = Order::where('order_no', $payment->order_no)->get();
                foreach ($orders as $order) {
                    if ($order->status === OrderStatus::PENDING_PAYMENT) {
                        $order->status = OrderStatus::PAID;
                        $order->save();
                        ProcessTopupJob::dispatch($order->id)->onQueue('topup');
                    }
                }
            }
        }

        return $json;
    }
}
