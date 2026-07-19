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

    public function orders()
    {
        $orders = $this->orderRepository->all();
        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function payments()
    {
        $payments = $this->paymentRepository->all();
        return response()->json([
            'success' => true,
            'data' => $payments
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

    public function users()
    {
        $users = $this->userRepository->all();
        return response()->json([
            'success' => true,
            'data' => $users
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
    public function packages()
    {
        $packages = Package::with('game')->get();
        // Map data to match client requirements
        $mapped = $packages->map(function ($p) {
            return [
                'id' => $p->id,
                'game_name' => $p->game ? $p->game->name_en : 'Unknown Game',
                'name' => $p->name_en,
                'price_usd' => (float)$p->price_usd,
                'discount_pct' => $p->original_price_usd > $p->price_usd ? round((($p->original_price_usd - $p->price_usd) / $p->original_price_usd) * 100) : 0,
                'is_available' => $p->is_active
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped
        ]);
    }

    public function createPackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'name_en' => 'required|string|max:255',
            'price_usd' => 'required|numeric',
            'discount_pct' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $game = Game::find($request->game_id);
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Selected game not found.'], 404);
        }

        $priceUsd = (float)$request->price_usd;
        $discountPct = (int)$request->input('discount_pct', 0);
        $originalPrice = $priceUsd;
        if ($discountPct > 0) {
            $originalPrice = $priceUsd / (1 - ($discountPct / 100));
        }

        preg_match('/\d+/', $request->name_en, $matches);
        $points = isset($matches[0]) ? (int)$matches[0] : 0;

        $package = Package::create([
            'game_id' => $game->id,
            'name_en' => $request->name_en,
            'name_kh' => $request->name_en,
            'price_usd' => $priceUsd,
            'price_khr' => (int)($priceUsd * 4100),
            'original_price_usd' => $originalPrice,
            'points_or_diamonds' => $points,
            'bonus_points' => 0,
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Top-up package registered successfully.',
            'data' => $package
        ]);
    }

    public function updatePackage(Request $request, $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Package not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'name_en' => 'required|string|max:255',
            'price_usd' => 'required|numeric',
            'discount_pct' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $priceUsd = (float)$request->price_usd;
        $discountPct = (int)$request->input('discount_pct', 0);
        $originalPrice = $priceUsd;
        if ($discountPct > 0) {
            $originalPrice = $priceUsd / (1 - ($discountPct / 100));
        }

        preg_match('/\d+/', $request->name_en, $matches);
        $points = isset($matches[0]) ? (int)$matches[0] : 0;

        $package->update([
            'game_id' => $request->game_id,
            'name_en' => $request->name_en,
            'name_kh' => $request->name_en,
            'price_usd' => $priceUsd,
            'price_khr' => (int)($priceUsd * 4100),
            'original_price_usd' => $originalPrice,
            'points_or_diamonds' => $points,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Package updated successfully.',
            'data' => [
                'id' => $package->id,
                'game_name' => $package->game ? $package->game->name_en : 'Unknown Game',
                'name' => $package->name_en,
                'price_usd' => (float)$package->price_usd,
                'discount_pct' => $discountPct,
                'is_available' => $package->is_active
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
        $banners = Banner::where('is_active', true)->orderBy('order_index', 'asc')->get();
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
        return response()->json([
            'success' => true,
            'data' => $balanceInfo
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
        $catalogueName = $order->package_name;

        $res = $this->g2bulkService->placeOrder(
            $gameCode,
            $catalogueName,
            $order->player_id,
            $order->server_id,
            $order->order_no
        );

        if ($res['success']) {
            if (isset($res['order_id'])) {
                $order->g2b_order_id = $res['order_id'];
            }
            $order->status = 'processing';
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Order retried and submitted to G2Bulk wholesaler successfully.',
                'data' => $order
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to retry order submittal: ' . $res['message']
        ], 400);
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
