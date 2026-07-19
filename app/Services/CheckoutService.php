<?php

namespace App\Services;

use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\GameRepositoryInterface;
use App\Repositories\Contracts\CouponRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\G2BulkService;

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
        User $user,
        array $items,
        string $paymentMethod,
        string $transactionNo,
        $receiptFile,
        ?string $couponCode
    ) {
        return DB::transaction(function () use ($user, $items, $paymentMethod, $transactionNo, $receiptFile, $couponCode) {
            // Prevent duplicate/spam checkouts using the same transaction reference number
            if (\App\Models\Payment::where('transaction_no', $transactionNo)->exists()) {
                throw new \Exception("This transaction number has already been used for another order.");
            }

            $totalSubtotal = 0.0;
            
            // 1. Process items and verify details
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

                // Verify package stock availability on G2Bulk live wholesaler API
                $this->verifyG2BulkStock($game, $package);

                $itemSubtotal = (float)$package->price_usd * (int)$item['qty'];
                $totalSubtotal += $itemSubtotal;

                $processedItems[] = [
                    'game' => $game,
                    'package' => $package,
                    'player_id' => $item['player_id'],
                    'server_id' => $item['server_id'] ?? null,
                    'qty' => $item['qty'],
                    'price_usd' => (float)$package->price_usd,
                    'price_khr' => (int)$package->price_khr,
                ];
            }

            // 2. Validate Coupon Code
            $discount = 0.0;
            $coupon = null;
            if ($couponCode) {
                $coupon = $this->couponRepository->findByCode($couponCode);
                if ($coupon && $coupon->isValidForAmount($totalSubtotal)) {
                    $discount = $coupon->calculateDiscount($totalSubtotal);
                    $this->couponRepository->incrementUsage($coupon->id);
                }
            }

            $totalUsd = max(0.0, $totalSubtotal - $discount);
            $totalKhr = (int)Math_round($totalUsd * 4100);

            // 3. Upload Receipt File
            $receiptPath = 'receipts/default.png';
            if ($receiptFile) {
                $filename = time() . '_' . Str::random(10) . '.' . $receiptFile->getClientOriginalExtension();
                $receiptPath = $receiptFile->storeAs('receipts', $filename, 'public');
            }
            $receiptUrl = Storage::url($receiptPath);

            // 4. Create Order & Payment records (Supporting multiple items if shopping cart utilized, or first main item)
            $orderNo = 'ORD-' . strtoupper(Str::random(10));
            
            // For multi-item carts, we record each item under the same order number or list
            $lastOrder = null;
            $isAutomated = ($paymentMethod === 'khqr_bakong');

            foreach ($processedItems as $pItem) {
                $orderData = [
                    'user_id' => $user->id,
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
                    'discount_usd' => $discount, // Spread or record discount
                    'total_price_usd' => $totalUsd,
                    'total_price_khr' => $totalKhr,
                    'status' => $isAutomated ? 'processing' : 'waiting_verification',
                    'payment_method' => $paymentMethod,
                    'coupon_code' => $couponCode,
                ];

                $order = $this->orderRepository->create($orderData);
                $lastOrder = $order;

                if ($isAutomated) {
                    // Automate top-up order via G2Bulk Wholesaler API
                    try {
                        $gameSlug = $pItem['game']->slug;
                        $gameMapping = [
                            'mobile-legends' => 'mlbb',
                            'mobile-khmer' => 'mlbb',
                            'free-fire' => 'freefire_global',
                            'pubg-mobile' => 'pubgm',
                            'valorant' => 'valorant_sg',
                            'honor-of-kings' => 'hok',
                            'roblox' => 'roblox',
                        ];
                        $gameCode = $gameMapping[$gameSlug] ?? 'mlbb';

                        $res = $this->g2bulkService->placeOrder(
                            $gameCode,
                            $pItem['package']->name_en,
                            $pItem['player_id'],
                            $pItem['server_id'],
                            $orderNo
                        );

                        if ($res['success']) {
                            if (isset($res['order_id'])) {
                                $order->g2b_order_id = $res['order_id'];
                                $order->save();
                            }
                        } else {
                            \Illuminate\Support\Facades\Log::warning("Automated G2Bulk placement failed for {$orderNo}: " . ($res['message'] ?? 'Unknown error'));
                        }
                    } catch (\Exception $ex) {
                        \Illuminate\Support\Facades\Log::error("Automated G2Bulk exception for {$orderNo}: " . $ex->getMessage());
                    }
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

            // 5. Send Telegram Notification Alert to Admins
            $telegramMsg = "🔔 <b>New Top-Up Order Placed!</b>\n\n" .
                "• <b>Order No:</b> <code>{$orderNo}</code>\n" .
                "• <b>Customer:</b> {$user->name} ({$user->email})\n" .
                "• <b>Payment Mode:</b> " . strtoupper(str_replace('_', ' ', $paymentMethod)) . "\n" .
                "• <b>Ref Txn ID:</b> <code>{$transactionNo}</code>\n" .
                "• <b>Total Amount:</b> \${$totalUsd} (" . number_format($totalKhr) . " KHR)\n" .
                "• <b>Automation:</b> " . ($isAutomated ? "⚡ AUTO-VERIFIED & SENT TO G2BULK" : "✍️ MANUAL RECEIPT VERIFICATION REQUIRED") . "\n";

            $this->telegramService->sendAdminAlert($telegramMsg);

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

            // Verify package stock availability on G2Bulk live wholesaler API
            $this->verifyG2BulkStock($game, $package);

            $itemSubtotal = (float)$package->price_usd * (int)$item['qty'];
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

        $baseUrl = env('KHQR_API_BASE_URL', 'https://api.khqr.link');
        $token = env('KHQR_API_TOKEN');
        $bakongId = env('KHQR_BAKONG_ACCOUNT_ID');
        $merchantName = env('KHQR_ACCOUNT_NAME', 'V-TOPUP-STORE CO., LTD.');

        $response = \Illuminate\Support\Facades\Http::withToken($token)
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
        $baseUrl = env('KHQR_API_BASE_URL', 'https://api.khqr.link');
        $token = env('KHQR_API_TOKEN');

        $response = \Illuminate\Support\Facades\Http::withToken($token)
            ->get("{$baseUrl}/v1/khqr/check", [
                'md5' => $md5,
            ]);

        if ($response->failed()) {
            throw new \Exception("Failed to verify payment status: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Check if a game package is available in the live G2Bulk catalogue.
     *
     * @throws \Exception
     */
    protected function verifyG2BulkStock($game, $package): void
    {
        $gameMapping = [
            'mobile-legends' => 'mlbb',
            'mobile-khmer' => 'mlbb',
            'free-fire' => 'freefire_global',
            'pubg-mobile' => 'pubgm',
            'valorant' => 'valorant_sg',
        ];
        $g2bCode = $gameMapping[$game->slug] ?? null;

        if ($g2bCode) {
            $url = "https://api.g2bulk.com/v1/games/{$g2bCode}/catalogue";
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(5)->get($url);
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['catalogues']) && is_array($data['catalogues'])) {
                        $found = false;
                        foreach ($data['catalogues'] as $cat) {
                            if (strcasecmp($cat['name'], $package->name_en) === 0) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            throw new \Exception("Stock not found.");
                        }
                    }
                }
            } catch (\Exception $e) {
                if ($e->getMessage() === "Stock not found.") {
                    throw $e;
                }
                \Illuminate\Support\Facades\Log::warning("G2Bulk stock verify warning: " . $e->getMessage());
            }
        }
    }
}

// Math_round helper fallback if not exists
if (!function_exists('App\Services\Math_round')) {
    function Math_round($val) {
        return round($val);
    }
}
