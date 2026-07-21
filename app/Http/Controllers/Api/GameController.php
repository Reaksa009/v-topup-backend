<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Repositories\Contracts\GameRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;

class GameController extends Controller
{
    protected GameRepositoryInterface $gameRepository;
    protected CategoryRepositoryInterface $categoryRepository;
    protected \App\Services\G2BulkService $g2bulkService;

    public function __construct(
        GameRepositoryInterface $gameRepository,
        CategoryRepositoryInterface $categoryRepository,
        \App\Services\G2BulkService $g2bulkService
    ) {
        $this->gameRepository = $gameRepository;
        $this->categoryRepository = $categoryRepository;
        $this->g2bulkService = $g2bulkService;
    }

    public function index()
    {
        $games = \Illuminate\Support\Facades\Cache::remember('active_games', 3600, function () {
            return $this->gameRepository->allActive();
        });
        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function categories()
    {
        $categories = \Illuminate\Support\Facades\Cache::remember('active_categories', 3600, function () {
            return $this->categoryRepository->allActive();
        });
        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function show($slug)
    {
        $game = \Illuminate\Support\Facades\Cache::remember("game_detail_{$slug}", 3600, function () use ($slug) {
            return $this->gameRepository->findBySlug($slug);
        });
        
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
        $games = \Illuminate\Support\Facades\Cache::remember('popular_games', 3600, function () {
            return $this->gameRepository->getPopular(5);
        });
        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function featured()
    {
        $games = \Illuminate\Support\Facades\Cache::remember('featured_games', 3600, function () {
            return $this->gameRepository->getFeatured(5);
        });
        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    public function getSettings()
    {
        $settings = \Illuminate\Support\Facades\Cache::remember('system_settings_cache', 3600, function () {
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

        // Map frontend slug to G2Bulk game code
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
            'message' => 'Player not found.'
        ], 404);
    }

    public function g2bulkWebhook(Request $request)
    {
        $status = $request->input('status');
        $remark = $request->input('remark');

        // Log incoming webhook call
        try {
            \App\Models\ApiLog::create([
                'url' => request()->fullUrl(),
                'method' => 'POST',
                'payload' => $request->all(),
                'response' => ['success' => true, 'logged' => true],
                'status_code' => 200,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to log G2Bulk Webhook: ' . $e->getMessage());
        }

        // Extract order number from remark (e.g. "Order No: ORD-XXXXXX")
        preg_match('/ORD-[A-Z0-9]+/', $remark, $matches);
        $orderNo = isset($matches[0]) ? $matches[0] : null;

        if ($orderNo) {
            $order = \App\Models\Order::where('order_no', $orderNo)->first();
            if ($order) {
                if (strtoupper($status) === 'COMPLETED' || strtoupper($status) === 'SUCCESS') {
                    $order->status = 'completed';
                } elseif (strtoupper($status) === 'FAILED' || strtoupper($status) === 'CANCELLED') {
                    $order->status = 'cancelled';
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
        $news = \Illuminate\Support\Facades\Cache::remember('latest_news', 3600, function () {
            return \App\Models\News::where('is_published', true)
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
