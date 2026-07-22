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
        $game = $this->gameRepository->findBySlug($slug);

        if (!$game) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $game
        ]);
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
