<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Repositories\Contracts\GameRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Services\G2BulkService;
use App\Enums\OrderStatus;
use App\Models\ApiLog;
use App\Models\Order;
use App\Models\News;
use App\Models\Banner;

class GameController extends Controller
{
    protected GameRepositoryInterface $gameRepository;
    protected CategoryRepositoryInterface $categoryRepository;
    protected G2BulkService $g2bulkService;

    public function __construct(
        GameRepositoryInterface $gameRepository,
        CategoryRepositoryInterface $categoryRepository,
        G2BulkService $g2bulkService
    ) {
        $this->gameRepository = $gameRepository;
        $this->categoryRepository = $categoryRepository;
        $this->g2bulkService = $g2bulkService;
    }

    public function home()
    {
        $homeData = Cache::remember('home_page_data_v1', 3600, function () {
            $games = $this->gameRepository->allActive();
            $categories = $this->categoryRepository->allActive();
            $banners = Banner::where('is_active', true)->orderBy('order_index', 'asc')->get();
            $news = News::where('is_published', true)->orderBy('created_at', 'desc')->limit(5)->get();

            $path = storage_path('app/settings.json');
            $settings = [
                'maintenance_mode' => false,
                'alert_message' => 'Welcome to V-TOPUP-STORE! Fast payments and 24/7 top-up services active.',
            ];
            if (file_exists($path)) {
                $decoded = json_decode(file_get_contents($path), true);
                if ($decoded) {
                    $settings = $decoded;
                }
            }

            return [
                'games' => $games,
                'categories' => $categories,
                'banners' => $banners,
                'news' => $news,
                'settings' => $settings,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $homeData,
        ]);
    }

    public function index()
    {
        $games = Cache::remember('active_games', 3600, function () {
            return $this->gameRepository->allActive();
        });

        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function categories()
    {
        $categories = Cache::remember('active_categories', 3600, function () {
            return $this->categoryRepository->allActive();
        });

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function show($slug)
    {
        $cleanSlug = str_replace([' ', '%20'], '-', trim(strtolower($slug)));
        $game = $this->gameRepository->findBySlug($cleanSlug);

        if (!$game) {
            $game = $this->gameRepository->findBySlug($slug);
        }

        if (!$game) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found.'
            ], 404);
        }

        $cacheKey = "game_{$cleanSlug}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($game) {
            $packages = $game->packages ? $game->packages->map(function ($p) {
                $providerPrice = (float)(string)($p->provider_price_usd ?? $p->original_price_usd ?? 0.0);
                $sellingPrice = (float)(string)($p->selling_price_usd ?? $p->price_usd ?? 0.0);
                $sellingKhr = (int)($p->selling_price_khr ?? $p->price_khr ?? round($sellingPrice * 4100));
                $profitAmt = (float)(string)($p->profit_amount ?? round($sellingPrice - $providerPrice, 2));
                $profitPct = (float)(string)($p->profit_percentage ?? ($providerPrice > 0 ? round(($profitAmt / $providerPrice) * 100, 2) : 0.0));

                return [
                    'id' => (string)$p->id,
                    'game_id' => (string)$p->game_id,
                    'provider' => $p->provider ?? 'g2bulk',
                    'provider_game_code' => $p->provider_game_code ?? 'mlbb',
                    'provider_catalogue_id' => $p->provider_catalogue_id ?? '',
                    'provider_catalogue_name' => $p->provider_catalogue_name ?? $p->name_en,
                    'name_en' => $p->name_en,
                    'name_kh' => $p->name_kh ?? $p->name_en,
                    'provider_price_usd' => $providerPrice,
                    'provider_price_khr' => (int)($p->provider_price_khr ?? round($providerPrice * 4100)),
                    'selling_price_usd' => $sellingPrice,
                    'selling_price_khr' => $sellingKhr,
                    'price_usd' => $sellingPrice,
                    'price_khr' => $sellingKhr,
                    'original_price_usd' => (float)(string)($p->original_price_usd ?? $providerPrice),
                    'profit_amount' => $profitAmt,
                    'profit_percentage' => $profitPct,
                    'points_or_diamonds' => (int)($p->points_or_diamonds ?? 0),
                    'bonus_points' => (int)($p->bonus_points ?? 0),
                    'is_active' => (bool)$p->is_active,
                    'stock_status' => strtolower((string)($p->stock_status ?? 'available')),
                    'last_stock_check_at' => $p->last_stock_check_at ? (string)$p->last_stock_check_at : null,
                    'provider_stock_message' => $p->provider_stock_message ?? '',
                ];
            })->values() : [];

            $gameData = $game->toArray();
            $gameData['packages'] = $packages;

            return response()->json([
                'success' => true,
                'data' => $gameData
            ]);
        });
    }

    public function search(Request $request)
    {
        $search = $request->query('query', '');
        $category = $request->query('category', 'All');

        $games = $this->gameRepository->searchAndFilter($search, $category);

        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function popular()
    {
        $games = Cache::remember('popular_games', 3600, function () {
            return $this->gameRepository->getPopular(5);
        });

        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function featured()
    {
        $games = Cache::remember('featured_games', 3600, function () {
            return $this->gameRepository->getFeatured(5);
        });

        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function getSettings()
    {
        $settings = Cache::remember('system_settings_cache', 3600, function () {
            $path = storage_path('app/settings.json');
            if (!file_exists($path)) {
                $settingsData = [
                    'maintenance_mode' => false,
                    'alert_message' => 'Welcome to V-TOPUP-STORE! Fast payments and 24/7 top-up services active.',
                ];
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                file_put_contents($path, json_encode($settingsData));
                return $settingsData;
            }
            return json_decode(file_get_contents($path), true);
        });

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    public function verifyPlayer(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
            'server_id' => 'nullable|string',
            'game_id' => 'required|string',
        ]);

        $playerId = $request->input('player_id');
        $serverId = $request->input('server_id');
        $gameId = $request->input('game_id');

        $game = \App\Models\Game::find($gameId);
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

        $res = $this->g2bulkService->checkPlayerId($gameCode, $playerId, $serverId);

        if ($res['success']) {
            return response()->json([
                'success' => true,
                'nickname' => $res['nickname']
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $res['message'] ?? 'Player not found.'
        ], 404);
    }

    public function g2bulkWebhook(Request $request)
    {
        $status = $request->input('status');
        $remark = $request->input('remark');

        // Log incoming webhook
        try {
            ApiLog::create([
                'request_id' => request()->header('X-Request-ID') ?? (string) \Illuminate\Support\Str::uuid(),
                'provider' => 'G2Bulk-Webhook',
                'url' => request()->fullUrl(),
                'method' => 'POST',
                'payload' => $request->all(),
                'response' => ['success' => true, 'logged' => true],
                'status_code' => 200,
                'latency_ms' => 0,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log G2Bulk Webhook: ' . $e->getMessage());
        }

        // Extract order number from remark (e.g. "Order No: ORD-XXXXXX")
        preg_match('/ORD-[A-Z0-9]+/', (string) $remark, $matches);
        $orderNo = $matches[0] ?? null;

        if ($orderNo) {
            $order = Order::where('order_no', $orderNo)->first();
            if ($order) {
                // Prevent duplicate webhook updates if order is already completed
                if ($order->status === OrderStatus::COMPLETED) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Order is already marked as completed. Duplicate webhook ignored.'
                    ]);
                }

                $upperStatus = strtoupper((string) $status);
                if ($upperStatus === 'COMPLETED' || $upperStatus === 'SUCCESS') {
                    $order->status = OrderStatus::COMPLETED;
                } elseif ($upperStatus === 'FAILED' || $upperStatus === 'CANCELLED') {
                    $order->status = OrderStatus::CANCELLED;
                }

                $order->save();

                return response()->json([
                    'success' => true,
                    'message' => "Order {$orderNo} status updated to {$order->status} successfully."
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Order not found or status update could not be parsed.'
        ], 404);
    }

    public function latestNews()
    {
        $news = Cache::remember('latest_news', 3600, function () {
            return News::where('is_published', true)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $news
        ]);
    }
}
