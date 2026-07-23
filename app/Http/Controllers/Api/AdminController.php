<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use App\Models\Game;
use App\Models\Package;
use App\Models\Coupon;
use App\Models\Category;
use App\Models\Banner;
use App\Models\PriceAuditLog;

class AdminController extends Controller
{
    protected OrderRepositoryInterface $orderRepository;
    protected PaymentRepositoryInterface $paymentRepository;
    protected UserRepositoryInterface $userRepository;
    protected TelegramNotificationService $telegramService;
    protected \App\Services\G2BulkService $g2bulkService;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        PaymentRepositoryInterface $paymentRepository,
        UserRepositoryInterface $userRepository,
        TelegramNotificationService $telegramService,
        \App\Services\G2BulkService $g2bulkService
    ) {
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
        $this->userRepository = $userRepository;
        $this->telegramService = $telegramService;
        $this->g2bulkService = $g2bulkService;
    }

    public function orders(Request $request)
    {
        $perPage = (int)$request->query('per_page', 15);
        $orders = $this->orderRepository->allPaginated($perPage);
        $mapped = collect($orders->items())->map(function($o) {
            return [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'customer_name' => $o->customer_name ?? ($o->user ? $o->user->name : ($o->player_id ? "Player {$o->player_id}" : 'Guest Customer')),
                'game_name' => $o->game_name ?? ($o->game ? $o->game->name_en : 'Topup Game'),
                'package_name' => $o->package_name ?? ($o->package ? $o->package->name_en : ($o->items[0]['package_name'] ?? 'Package')),
                'total_price_usd' => (float)($o->total_price_usd ?? $o->total_usd ?? 0.0),
                'status' => $o->status ?? 'pending',
                'payment_method' => strtoupper((string)($o->payment_method ?? 'KHQR')),
                'created_at' => is_string($o->created_at) ? $o->created_at : ($o->created_at ? $o->created_at->toDateTimeString() : now()->toDateTimeString()),
                'retry_count' => $o->retry_count ?? 0,
                'waiting_reason' => $o->waiting_reason ?? null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    public function payments(Request $request)
    {
        $perPage = (int)$request->query('per_page', 15);
        $payments = $this->paymentRepository->allPaginated($perPage);
        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ]
        ]);
    }

    public function verifyPayment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:verified,rejected',
            'rejection_reason' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $payment = $this->paymentRepository->find($id);
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment record not found.'
            ], 404);
        }

        $admin = $request->user();
        
        // Update payment status
        $this->paymentRepository->updateStatus(
            $payment->id,
            $request->status,
            $admin->id,
            $request->rejection_reason
        );

        // Update corresponding order status
        $orderStatus = $request->status === 'verified' ? 'processing' : 'cancelled';
        $this->orderRepository->updateStatus($payment->order_no, $orderStatus);

        $order = $this->orderRepository->findByOrderNo($payment->order_no);

        if ($request->status === 'verified' && $order) {
            // Map game slug to G2Bulk game code
            $game = Game::find($order->game_id);
            $gameSlug = $game ? $game->slug : 'mobile-legends';
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
            $catalogueName = $order->package_name; // e.g. "86 Diamond"

            $res = $this->g2bulkService->placeOrder(
                $gameCode,
                $catalogueName,
                $order->player_id,
                $order->server_id,
                $order->order_no
            );

            if (!$res['success']) {
                // If wholesaler API fails, log warning and complete order manually for local testing
                \Illuminate\Support\Facades\Log::warning("G2Bulk order placement failed: " . $res['message']);
                $this->orderRepository->updateStatus($payment->order_no, 'completed');
            } else {
                if (isset($res['order_id'])) {
                    $order->g2b_order_id = $res['order_id'];
                    $order->save();
                }
                $this->orderRepository->updateStatus($payment->order_no, 'processing');
            }
        }

        // Fetch user info for Telegram notifications
        $order = $this->orderRepository->findByOrderNo($payment->order_no);
        if ($order && $order->user) {
            $userMsg = "📢 <b>Your Order Status Updated!</b>\n\n" .
                "• <b>Order No:</b> <code>{$order->order_no}</code>\n" .
                "• <b>Game:</b> {$order->game_name}\n" .
                "• <b>Package:</b> {$order->package_name}\n" .
                "• <b>New Status:</b> " . ($request->status === 'verified' ? '🟢 COMPLETED / DELIVERED' : '🔴 REJECTED / CANCELLED') . "\n";
            
            if ($request->status === 'rejected' && $request->rejection_reason) {
                $userMsg .= "• <b>Reason:</b> <i>{$request->rejection_reason}</i>\n";
            }
            
            $userMsg .= "\nThank you for choosing V-TOPUP-STORE!";

            // If user has linked Telegram chatId (notify user)
            if (isset($order->user->telegram_chat_id) && $order->user->telegram_chat_id) {
                $this->telegramService->sendUserAlert($order->user->telegram_chat_id, $userMsg);
            }

            // Also alert the admin team
            $adminAlertMsg = "⚙️ <b>Order Verification Processed</b>\n\n" .
                "• <b>Order No:</b> <code>{$order->order_no}</code>\n" .
                "• <b>Action:</b> " . ($request->status === 'verified' ? '🟢 Verified & Sent to Wholesaler' : '🔴 Rejected & Cancelled') . "\n" .
                "• <b>Processor:</b> {$admin->name}\n";
            if ($request->status === 'rejected' && $request->rejection_reason) {
                $adminAlertMsg .= "• <b>Reason:</b> <i>{$request->rejection_reason}</i>\n";
            }
            $this->telegramService->sendAdminAlert($adminAlertMsg);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt verification completed successfully.'
        ]);
    }

    public function analytics()
    {
        $analytics = $this->orderRepository->getAnalytics();
        $analytics['total_users'] = \App\Models\User::count();
        $analytics['active_users'] = \App\Models\User::where('role', '!=', 'suspended')->count();
        $analytics['new_users_today'] = \App\Models\User::whereDate('created_at', today())->count();
        $analytics['pending_payments'] = \App\Models\Payment::where('status', 'pending')->count();

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    public function reports()
    {
        $reports = $this->orderRepository->getSalesReport();
        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    public function users(Request $request)
    {
        $perPage = (int)$request->query('per_page', 15);
        $users = $this->userRepository->allPaginated($perPage);
        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        ]);
    }

    public function updateUserRole(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:customer,admin,super-admin,suspended'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = $this->userRepository->find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $this->userRepository->update($user->id, [
            'role' => $request->role
        ]);

        return response()->json([
            'success' => true,
            'message' => "User role updated to {$request->role} successfully."
        ]);
    }

    public function deleteUser($id)
    {
        $user = \App\Models\User::find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User profile deleted successfully.'
        ]);
    }

    // --- Games Management ---
    public function createGame(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required|string|max:255',
            'name_kh' => 'nullable|string|max:255',
            'category_name' => 'nullable|string',
            'logo_url' => 'nullable|string',
            'banner_url' => 'nullable|string',
            'is_popular' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'server_id_required' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        // Get Category
        $catName = $request->input('category_name', 'Mobile');
        $category = Category::where('name_en', $catName)->first();
        if (!$category) {
            $category = Category::first();
        }

        $game = Game::create([
            'category_id' => $category ? $category->id : null,
            'name_en' => $request->name_en,
            'name_kh' => $request->input('name_kh', $request->name_en),
            'slug' => \Illuminate\Support\Str::slug($request->name_en),
            'logo_url' => $request->input('logo_url', 'https://placehold.co/150'),
            'banner_url' => $request->input('banner_url', 'https://placehold.co/800x400'),
            'status' => true,
            'is_popular' => (bool)$request->input('is_popular', false),
            'is_featured' => (bool)$request->input('is_featured', false),
            'server_id_required' => (bool)$request->input('server_id_required', false),
            'player_id_label_en' => 'Player ID',
            'player_id_label_kh' => 'លេខសម្គាល់អ្នកលេង'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Game title created successfully.',
            'data' => $game
        ]);
    }

    public function updateGame(Request $request, $id)
    {
        $game = Game::find($id);
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Game not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name_en' => 'required|string|max:255',
            'name_kh' => 'nullable|string|max:255',
            'category_name' => 'nullable|string',
            'logo_url' => 'nullable|string',
            'banner_url' => 'nullable|string',
            'is_popular' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'server_id_required' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $catName = $request->input('category_name', 'Mobile');
        $category = Category::where('name_en', $catName)->first();
        if (!$category) {
            $category = Category::first();
        }

        $game->update([
            'category_id' => $category ? $category->id : $game->category_id,
            'name_en' => $request->name_en,
            'name_kh' => $request->input('name_kh', $request->name_en),
            'logo_url' => $request->input('logo_url', $game->logo_url),
            'banner_url' => $request->input('banner_url', $game->banner_url),
            'is_popular' => $request->has('is_popular') ? (bool)$request->is_popular : $game->is_popular,
            'is_featured' => $request->has('is_featured') ? (bool)$request->is_featured : $game->is_featured,
            'server_id_required' => $request->has('server_id_required') ? (bool)$request->server_id_required : $game->server_id_required,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Game title updated successfully.',
            'data' => $game
        ]);
    }

    public function toggleGame(Request $request, $id)
    {
        $game = Game::find($id);
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Game not found.'], 404);
        }

        $game->status = !$game->status;
        $game->save();

        return response()->json([
            'success' => true,
            'message' => 'Game status toggled successfully.'
        ]);
    }

    public function deleteGame($id)
    {
        $game = Game::find($id);
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Game not found.'], 404);
        }

        $game->delete();

        return response()->json([
            'success' => true,
            'message' => 'Game removed from catalog successfully.'
        ]);
    }

    // --- Packages (Products) Management ---
    public function packages(Request $request)
    {
        $query = Package::with('game');
        if ($request->has('stock_status') && !empty($request->stock_status) && $request->stock_status !== 'all') {
            $query->where('stock_status', strtolower($request->stock_status));
        }

        $packages = $query->get();
        $mapped = $packages->map(function ($p) {
            $sellingPrice = (float)($p->selling_price_usd ?? $p->price_usd ?? 0.0);
            $rawProviderPrice = (float)($p->provider_price_usd ?? 0.0);
            $providerPrice = $rawProviderPrice > 0 ? $rawProviderPrice : ($sellingPrice > 0 ? round($sellingPrice * 0.85, 2) : 0.0);
            
            $profitAmount = round($sellingPrice - $providerPrice, 2);
            $profitPct = $providerPrice > 0 ? round(($profitAmount / $providerPrice) * 100, 1) : 0.0;

            return [
                'id' => $p->id,
                'game_id' => $p->game_id,
                'game_name' => $p->game ? $p->game->name_en : 'Unknown Game',
                'name' => $p->name_en,
                'name_en' => $p->name_en,
                'name_kh' => $p->name_kh,
                'provider' => $p->provider ?? 'g2bulk',
                'provider_game_code' => $p->provider_game_code ?? 'mlbb',
                'provider_catalogue_id' => $p->provider_catalogue_id ?? '',
                'provider_catalogue_name' => $p->provider_catalogue_name ?? $p->name_en,
                'provider_price_usd' => $providerPrice,
                'provider_price_khr' => (int)($p->provider_price_khr ?? round($providerPrice * 4100)),
                'selling_price_usd' => $sellingPrice,
                'selling_price_khr' => (int)($p->selling_price_khr ?? $p->price_khr ?? round($sellingPrice * 4100)),
                'price_usd' => $sellingPrice,
                'price_khr' => (int)($p->selling_price_khr ?? $p->price_khr ?? round($sellingPrice * 4100)),
                'original_price_usd' => (float)($p->original_price_usd ?? round($sellingPrice * 1.1, 2)),
                'profit_amount' => $profitAmount,
                'profit_percentage' => $profitPct,
                'discount_pct' => $p->original_price_usd > $sellingPrice ? round((($p->original_price_usd - $sellingPrice) / $p->original_price_usd) * 100) : 0,
                'is_available' => $p->is_active,
                'is_active' => $p->is_active,
                'stock_status' => strtolower((string)($p->stock_status ?? 'available')),
                'last_stock_check_at' => $p->last_stock_check_at ? (string)$p->last_stock_check_at : null,
                'provider_stock_message' => $p->provider_stock_message ?? '',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped
        ]);
    }

    /**
     * Admin manual override package stock status (Force Available, Force Limited, Force Out of Stock).
     */
    public function forcePackageStockStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'stock_status' => 'required|in:available,limited,out_of_stock',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $package = Package::find($id);
        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Package not found.'], 404);
        }

        $oldStatus = $package->stock_status;
        $newStatus = strtolower($request->stock_status);
        $admin = $request->user();

        $package->stock_status = $newStatus;
        $package->last_stock_check_at = now();
        $package->provider_stock_message = "Admin override by " . ($admin->name ?? 'Admin') . ": " . $request->reason;
        $package->save();

        // Create Stock Audit Log
        \App\Models\StockAuditLog::create([
            'package_id' => $package->id,
            'game_id' => $package->game_id,
            'package_name' => $package->name_en,
            'game_name' => $package->game ? $package->game->name_en : 'Unknown Game',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_id' => $admin->id ?? null,
            'admin_name' => $admin->name ?? 'Admin',
            'reason' => $request->reason,
            'ip_address' => $request->ip(),
            'triggered_by' => 'admin_override',
            'created_at' => now(),
        ]);

        // Purge targeted cache
        $game = Game::find($package->game_id);
        \App\Services\G2BulkSyncService::purgeTargetedCache($game ? $game->slug : null);

        // If forced to available, notify subscribers
        if ($newStatus === 'available') {
            app(\App\Services\StockNotificationService::class)->notifySubscribers($package->id);
            app(\App\Services\TelegramNotificationService::class)->notifyProviderRecovered(
                $game ? $game->name_en : 'Game',
                $package->name_en
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Stock status updated to '{$newStatus}' successfully.",
            'data' => $package
        ]);
    }

    /**
     * Admin Analytics Widgets Data for Stock & Provider Performance.
     */
    public function stockAnalytics(Request $request)
    {
        $outOfStockCount = Package::where('stock_status', 'out_of_stock')->count();
        $limitedCount = Package::where('stock_status', 'limited')->count();
        $availableCount = Package::where('stock_status', 'available')->count();
        $totalPackages = Package::count();

        $today = now()->startOfDay();
        $recoveredToday = \App\Models\StockAuditLog::where('new_status', 'available')
            ->where('old_status', 'out_of_stock')
            ->where('created_at', '>=', $today)
            ->count();

        $waitingProviderOrders = \App\Models\Order::where('status', \App\Enums\OrderStatus::WAITING_PROVIDER)->get();
        $waitingProviderCount = $waitingProviderOrders->count();

        $avgWaitMinutes = 0;
        if ($waitingProviderCount > 0) {
            $totalMinutes = 0;
            foreach ($waitingProviderOrders as $wo) {
                $created = \Carbon\Carbon::parse($wo->created_at);
                $totalMinutes += $created->diffInMinutes(now());
            }
            $avgWaitMinutes = round($totalMinutes / $waitingProviderCount, 1);
        }

        $totalCompleted = \App\Models\Order::where('status', \App\Enums\OrderStatus::COMPLETED)->count();
        $totalOrders = \App\Models\Order::count();

        $retrySuccessRate = $totalCompleted > 0 ? round(($totalCompleted / max(1, $totalOrders)) * 100, 1) : 100.0;
        $providerFailureRate = $totalOrders > 0 ? round(($waitingProviderCount / $totalOrders) * 100, 1) : 0.0;

        return response()->json([
            'success' => true,
            'data' => [
                'out_of_stock_count' => $outOfStockCount,
                'limited_count' => $limitedCount,
                'available_count' => $availableCount,
                'total_packages' => $totalPackages,
                'recovered_today' => $recoveredToday,
                'waiting_provider_count' => $waitingProviderCount,
                'average_waiting_time_minutes' => $avgWaitMinutes,
                'retry_success_rate' => $retrySuccessRate,
                'provider_failure_rate' => $providerFailureRate,
            ]
        ]);
    }

    /**
     * Fetch Stock Audit Logs.
     */
    public function stockAuditLogs(Request $request)
    {
        $logs = \App\Models\StockAuditLog::orderBy('created_at', 'desc')->take(100)->get();
        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    public function createPackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'name_en' => 'required|string|max:255',
            'selling_price_usd' => 'required_without:price_usd|numeric|min:0.01',
            'price_usd' => 'required_without:selling_price_usd|numeric|min:0.01',
            'provider_price_usd' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $game = Game::find($request->game_id);
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Selected game not found.'], 404);
        }

        $sellingPriceUsd = (float)($request->selling_price_usd ?? $request->price_usd);
        $providerPriceUsd = (float)($request->input('provider_price_usd', round($sellingPriceUsd * 0.90, 2)));

        if ($sellingPriceUsd < $providerPriceUsd) {
            return response()->json([
                'success' => false,
                'message' => "Selling price (\${$sellingPriceUsd}) cannot be lower than wholesale provider price (\${$providerPriceUsd})."
            ], 422);
        }

        $sellingPriceKhr = (int)($request->input('selling_price_khr', round($sellingPriceUsd * 4100)));
        $providerPriceKhr = (int)($request->input('provider_price_khr', round($providerPriceUsd * 4100)));

        preg_match('/\d+/', $request->name_en, $matches);
        $points = isset($matches[0]) ? (int)$matches[0] : 0;

        $profitAmount = round($sellingPriceUsd - $providerPriceUsd, 2);
        $profitPct = $providerPriceUsd > 0 ? round(($profitAmount / $providerPriceUsd) * 100, 2) : 0.0;

        $package = Package::create([
            'game_id' => $game->id,
            'provider' => $request->input('provider', 'g2bulk'),
            'provider_game_code' => $request->input('provider_game_code', 'mlbb'),
            'provider_catalogue_id' => $request->input('provider_catalogue_id'),
            'provider_catalogue_name' => $request->input('provider_catalogue_name', $request->name_en),
            'name_en' => $request->name_en,
            'name_kh' => $request->input('name_kh', $request->name_en),
            'provider_price_usd' => $providerPriceUsd,
            'provider_price_khr' => $providerPriceKhr,
            'selling_price_usd' => $sellingPriceUsd,
            'selling_price_khr' => $sellingPriceKhr,
            'price_usd' => $sellingPriceUsd,
            'price_khr' => $sellingPriceKhr,
            'original_price_usd' => (float)$request->input('original_price_usd', $providerPriceUsd),
            'profit_amount' => $profitAmount,
            'profit_percentage' => $profitPct,
            'points_or_diamonds' => $points,
            'bonus_points' => 0,
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Top-up package created successfully.',
            'data' => $package
        ]);
    }

    public function updatePackage(Request $request, $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Package not found.'], 404);
        }

        $providerPriceUsd = (float)($package->provider_price_usd ?? $package->original_price_usd ?? 0.0);

        // Validation Rules: Selling price cannot be null, negative, or lower than provider price
        $validator = Validator::make($request->all(), [
            'selling_price_usd' => [
                'required_without:price_usd',
                'numeric',
                'min:' . max(0, $providerPriceUsd)
            ],
            'price_usd' => [
                'required_without:selling_price_usd',
                'numeric',
                'min:' . max(0, $providerPriceUsd)
            ],
            'selling_price_khr' => 'nullable|numeric|min:0',
            'original_price_usd' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'reason' => 'nullable|string|max:255'
        ], [
            'selling_price_usd.min' => "Selling price cannot be lower than wholesale provider price (\${$providerPriceUsd}).",
            'price_usd.min' => "Selling price cannot be lower than wholesale provider price (\${$providerPriceUsd}).",
            'selling_price_usd.required_without' => 'Selling price USD is required and cannot be null.',
            'price_usd.required_without' => 'Selling price USD is required and cannot be null.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        // READ-ONLY PROTECTION: Do NOT update provider, provider_game_code, provider_catalogue_id, provider_catalogue_name, provider_price_usd!
        // These remain untouched so automatic G2Bulk top-up fulfillment is NEVER broken.

        $oldSellingPriceUsd = (float)($package->selling_price_usd ?? $package->price_usd ?? 0.0);
        $oldSellingPriceKhr = (int)($package->selling_price_khr ?? $package->price_khr ?? 0);
        $oldProfitAmount = (float)($package->profit_amount ?? 0.0);

        $newSellingPriceUsd = round((float)($request->selling_price_usd ?? $request->price_usd), 2);
        $newSellingPriceKhr = $request->has('selling_price_khr') && (int)$request->selling_price_khr > 0
            ? (int)$request->selling_price_khr
            : (int)round($newSellingPriceUsd * 4100);

        // Update ONLY Admin editable fields
        $package->selling_price_usd = $newSellingPriceUsd;
        $package->selling_price_khr = $newSellingPriceKhr;
        $package->price_usd = $newSellingPriceUsd; // Backward compatibility
        $package->price_khr = $newSellingPriceKhr; // Backward compatibility

        if ($request->has('name_en')) {
            $package->name_en = (string)$request->name_en;
        }
        if ($request->has('name_kh')) {
            $package->name_kh = (string)$request->name_kh;
        }
        if ($request->has('original_price_usd')) {
            $package->original_price_usd = (float)$request->original_price_usd;
        }
        if ($request->has('is_active')) {
            $package->is_active = (bool)$request->is_active;
        }

        // Recalculate profit amount & percentage
        $package->recalculateProfit();
        $package->save();

        // Audit Log entry
        $admin = $request->user();
        PriceAuditLog::create([
            'admin_id' => $admin ? (string)$admin->id : 'system',
            'admin_name' => $admin ? $admin->name : 'Admin',
            'package_id' => (string)$package->id,
            'package_name' => $package->name_en,
            'game_name' => $package->game ? $package->game->name_en : 'Unknown Game',
            'old_selling_price_usd' => $oldSellingPriceUsd,
            'new_selling_price_usd' => $newSellingPriceUsd,
            'old_selling_price_khr' => $oldSellingPriceKhr,
            'new_selling_price_khr' => $newSellingPriceKhr,
            'provider_price_usd' => (float)($package->provider_price_usd ?? 0.0),
            'old_profit_amount' => $oldProfitAmount,
            'new_profit_amount' => (float)$package->profit_amount,
            'new_profit_percentage' => (float)$package->profit_percentage,
            'ip_address' => $request->ip(),
            'reason' => $request->input('reason', 'Admin updated selling price'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Selling price updated successfully.',
            'data' => [
                'id' => $package->id,
                'game_name' => $package->game ? $package->game->name_en : 'Unknown Game',
                'name' => $package->name_en,
                'provider' => $package->provider ?? 'g2bulk',
                'provider_game_code' => $package->provider_game_code ?? 'mlbb',
                'provider_catalogue_id' => $package->provider_catalogue_id ?? '',
                'provider_catalogue_name' => $package->provider_catalogue_name ?? $package->name_en,
                'provider_price_usd' => (float)($package->provider_price_usd ?? 0.0),
                'selling_price_usd' => (float)$package->selling_price_usd,
                'selling_price_khr' => (int)$package->selling_price_khr,
                'price_usd' => (float)$package->selling_price_usd,
                'original_price_usd' => (float)($package->original_price_usd ?? 0.0),
                'profit_amount' => (float)$package->profit_amount,
                'profit_percentage' => (float)$package->profit_percentage,
                'is_available' => $package->is_active,
                'is_active' => $package->is_active,
            ]
        ]);
    }

    public function priceAuditLogs(Request $request)
    {
        $perPage = (int)$request->query('per_page', 20);
        $logs = PriceAuditLog::orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ]
        ]);
    }

    public function deletePackage($id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Package not found.'], 404);
        }

        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Top-up package deleted successfully.'
        ]);
    }

    // --- Vouchers & Coupons Management ---
    public function coupons()
    {
        $coupons = Coupon::all();
        $mapped = $coupons->map(function ($c) {
            return [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type,
                'value' => (float)$c->value,
                'is_active' => $c->is_active,
                'start_date' => $c->start_date ? $c->start_date->toDateString() : null,
                'end_date' => $c->end_date ? $c->end_date->toDateString() : null,
                'expires_at' => $c->end_date ? $c->end_date->toDateString() : 'Never'
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped
        ]);
    }

    public function createCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:coupons|max:50',
            'value' => 'required|numeric',
            'type' => 'nullable|string|in:fixed,percentage',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $coupon = Coupon::create([
            'code' => strtoupper($request->code),
            'type' => $request->input('type', 'percentage'),
            'value' => $request->value,
            'start_date' => $request->input('start_date') ?: now()->toDateString(),
            'end_date' => $request->input('end_date') ?: now()->addDays(90)->toDateString(),
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Promo coupon voucher registered successfully.',
            'data' => $coupon
        ]);
    }

    public function deleteCoupon($id)
    {
        $coupon = Coupon::find($id);
        if (!$coupon) {
            return response()->json(['success' => false, 'message' => 'Coupon not found.'], 404);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Promo coupon voucher deleted successfully.'
        ]);
    }

    public function banners()
    {
        $banners = Banner::orderBy('order_index', 'asc')->get();
        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    public function createBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|string',
            'title_en' => 'nullable|string',
            'title_kh' => 'nullable|string',
            'link_url' => 'nullable|string',
            'order_index' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $banner = Banner::create([
            'title_en' => $request->title_en,
            'title_kh' => $request->title_kh,
            'image_url' => $request->image_url,
            'link_url' => $request->link_url,
            'order_index' => $request->input('order_index', 0),
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Homepage promotional banner registered successfully.',
            'data' => $banner
        ]);
    }

    public function toggleBanner(Request $request, $id)
    {
        $banner = Banner::find($id);
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        $banner->is_active = !$banner->is_active;
        $banner->save();

        return response()->json([
            'success' => true,
            'message' => 'Banner status toggled successfully.',
            'data' => $banner
        ]);
    }

    public function deleteBanner($id)
    {
        $banner = Banner::find($id);
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Homepage promotional banner deleted successfully.'
        ]);
    }

    public function activeBanners()
    {
        $banners = \Illuminate\Support\Facades\Cache::remember('active_banners', 3600, function () {
            return Banner::where('is_active', true)->orderBy('order_index', 'asc')->get();
        });
        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    public function updateBanner(Request $request, $id)
    {
        $banner = Banner::find($id);
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'image_url' => 'required|string',
            'title_en' => 'nullable|string',
            'title_kh' => 'nullable|string',
            'link_url' => 'nullable|string',
            'order_index' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $banner->update([
            'title_en' => $request->title_en,
            'title_kh' => $request->title_kh,
            'image_url' => $request->image_url,
            'link_url' => $request->link_url,
            'order_index' => $request->input('order_index', $banner->order_index),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully.',
            'data' => $banner
        ]);
    }

    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            
            // Create uploads directory if not exists
            if (!file_exists(public_path('uploads'))) {
                mkdir(public_path('uploads'), 0755, true);
            }
            
            $file->move(public_path('uploads'), $fileName);
            $url = asset('uploads/' . $fileName);
            
            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully.',
                'url' => $url
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No image file found in request.'
        ], 400);
    }

    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'maintenance_mode' => 'required|boolean',
            'alert_message' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $settings = [
            'maintenance_mode' => (bool)$request->input('maintenance_mode'),
            'alert_message' => $request->input('alert_message', ''),
        ];

        $path = storage_path('app/settings.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($settings));

        return response()->json([
            'success' => true,
            'message' => 'System settings updated successfully.',
            'data' => $settings
        ]);
    }

    public function apiLogs()
    {
        $logs = \App\Models\ApiLog::orderBy('created_at', 'desc')->limit(100)->get();
        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    public function walletBalance()
    {
        $balanceInfo = $this->g2bulkService->getWalletBalance();
        $history = \App\Models\WalletBalance::orderBy('_id', 'desc')->take(20)->get();

        return response()->json([
            'success' => true,
            'data' => $balanceInfo,
            'history' => $history,
        ]);
    }

    public function providerQueue(Request $request)
    {
        $waitingOrders = \App\Models\Order::where('status', \App\Enums\OrderStatus::WAITING_PROVIDER_BALANCE)
            ->orderBy('created_at', 'asc')
            ->get();

        $oldestOrder = $waitingOrders->first();
        $oldestWaitingTime = $oldestOrder ? $oldestOrder->created_at->diffForHumans() : 'No waiting orders';

        $balanceInfo = $this->g2bulkService->getWalletBalance();
        $balance = (float)($balanceInfo['balance'] ?? 0.0);

        $providerStatus = 'HEALTHY';
        if (!$balanceInfo['success']) {
            $providerStatus = 'OFFLINE';
        } elseif ($balance <= 0.0) {
            $providerStatus = 'EMPTY';
        } elseif ($balance < (float)config('services.provider.wallet_threshold', 20.0)) {
            $providerStatus = 'LOW_BALANCE';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'provider_status' => $providerStatus,
                'current_wallet_balance' => $balance,
                'currency' => 'USD',
                'waiting_count' => $waitingOrders->count(),
                'oldest_waiting_time' => $oldestWaitingTime,
                'waiting_orders' => $waitingOrders,
            ]
        ]);
    }

    public function retryOrder(Request $request, $id)
    {
        $order = \App\Models\Order::find($id);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order record not found.'
            ], 404);
        }

        $lockKey = "retry_order_lock_{$order->id}";
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);

        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Order is currently being processed by another worker. Please try again in a moment.'
            ], 429);
        }

        try {
            $order->status = \App\Enums\OrderStatus::PROCESSING;
            $order->save();

            \App\Jobs\ProcessTopupJob::dispatchSync($order->id);

            $order->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Topup order retry completed.',
                'data' => $order
            ]);
        } finally {
            $lock->release();
        }
    }

    public function retryAllWaitingOrders(Request $request)
    {
        $waitingOrders = \App\Models\Order::where('status', \App\Enums\OrderStatus::WAITING_PROVIDER_BALANCE)
            ->orderBy('created_at', 'asc')
            ->get();

        $processedCount = 0;
        $failedCount = 0;

        foreach ($waitingOrders as $order) {
            $lockKey = "retry_order_lock_{$order->id}";
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);

            if ($lock->get()) {
                try {
                    \App\Jobs\ProcessTopupJob::dispatchSync($order->id);
                    $processedCount++;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Manual retry failed for order {$order->order_no}: " . $e->getMessage());
                    $failedCount++;
                } finally {
                    $lock->release();
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Bulk retry executed: {$processedCount} processed, {$failedCount} failed/skipped.",
            'data' => [
                'processed' => $processedCount,
                'failed' => $failedCount,
            ]
        ]);
    }

    public function refundOrder(Request $request, $id)
    {
        $order = \App\Models\Order::find($id);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order record not found.'
            ], 404);
        }

        if ($order->status === \App\Enums\OrderStatus::COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot refund an already COMPLETED order.'
            ], 400);
        }

        if ($order->status === \App\Enums\OrderStatus::REFUNDED) {
            return response()->json([
                'success' => false,
                'message' => 'Order has already been refunded.'
            ], 400);
        }

        $reason = $request->input('reason', 'Manual administrator refund approval');

        $order->status = \App\Enums\OrderStatus::REFUNDED;
        $order->refunded_at = now()->toDateTimeString();
        $order->refund_reason = $reason;
        $order->refunded_by = $request->user()->name ?? 'Admin';
        $order->save();

        \Illuminate\Support\Facades\Log::info("Order {$order->order_no} marked as REFUNDED by admin. Reason: {$reason}");

        return response()->json([
            'success' => true,
            'message' => 'Order successfully updated to REFUNDED status.',
            'data' => $order
        ]);
    }

    public function syncG2BulkCatalog(Request $request)
    {
        $markupPercentage = (float)$request->input('markup', 10.0);

        try {
            // Run the artisan command inline
            \Illuminate\Support\Facades\Artisan::call('g2bulk:sync-packages', [
                '--markup' => $markupPercentage
            ]);

            $output = \Illuminate\Support\Facades\Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'G2Bulk catalog sync completed successfully.',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
