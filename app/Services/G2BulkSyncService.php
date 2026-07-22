<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Game;
use App\Models\Package;

class G2BulkSyncService
{
    protected string $baseUrl;
    protected string $apiKey;

    public const GAME_MAPPINGS = [
        'mobile-legends' => 'mlbb',
        'mobile-khmer' => 'mlbb',
        'free-fire' => 'freefire_global',
        'pubg-mobile' => 'pubgm',
        'valorant' => 'valorant_sg',
        'roblox' => 'roblox',
        'honor-of-kings' => 'hok',
    ];

    public function __construct()
    {
        $this->baseUrl = (string) config('services.g2bulk.base_url', 'https://api.g2bulk.com/v1');
        $this->apiKey = (string) config('services.g2bulk.api_key');
    }

    /**
     * Fetch G2Bulk catalogue list for a given provider game code (with 24h caching).
     */
    public function fetchCatalogue(string $g2bCode, bool $forceRefresh = false): array
    {
        $cacheKey = "g2bulk_catalogue_{$g2bCode}";

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 86400, function () use ($g2bCode) {
            $url = "{$this->baseUrl}/games/{$g2bCode}/catalogue";
            try {
                $response = Http::retry(2, 500)
                    ->timeout(10)
                    ->withHeaders([
                        'X-API-Key' => $this->apiKey,
                        'Accept' => 'application/json',
                    ])->get($url);

                if ($response->successful() && isset($response->json()['catalogues'])) {
                    return $response->json()['catalogues'];
                }

                Log::warning("Failed to fetch G2Bulk catalogue for {$g2bCode}: " . $response->body());
                return [];
            } catch (\Exception $e) {
                Log::error("Exception fetching G2Bulk catalogue for {$g2bCode}: " . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Synchronize G2Bulk packages into MongoDB.
     */
    public function syncAllPackages(float $markupPercentage = 10.0): array
    {
        $results = [];

        foreach (self::GAME_MAPPINGS as $gameSlug => $g2bCode) {
            $game = Game::where('slug', $gameSlug)->first();
            if (!$game) {
                $results[$gameSlug] = ['success' => false, 'message' => "Game slug '{$gameSlug}' not found in MongoDB."];
                continue;
            }

            $catalogues = $this->fetchCatalogue($g2bCode, true);
            if (empty($catalogues)) {
                $results[$gameSlug] = ['success' => false, 'message' => "Empty or invalid catalogue received for provider code '{$g2bCode}'."];
                continue;
            }

            $syncedCount = 0;
            foreach ($catalogues as $item) {
                $wholesalePrice = (float)($item['amount'] ?? 0);
                $retailPrice = round($wholesalePrice * (1 + ($markupPercentage / 100)), 2);
                $priceKhr = (int)round($retailPrice * 4100);

                preg_match('/\d+/', $item['name'], $matches);
                $points = isset($matches[0]) ? (int)$matches[0] : 0;

                Package::updateOrCreate(
                    [
                        'game_id' => $game->id,
                        'provider_catalogue_name' => (string)$item['name'],
                    ],
                    [
                        'provider' => 'g2bulk',
                        'provider_game_code' => $g2bCode,
                        'provider_catalogue_id' => isset($item['id']) ? (string)$item['id'] : null,
                        'provider_catalogue_name' => (string)$item['name'],
                        'name_en' => (string)$item['name'],
                        'name_kh' => (string)$item['name'],
                        'price_usd' => $retailPrice,
                        'price_khr' => $priceKhr,
                        'original_price_usd' => $wholesalePrice,
                        'points_or_diamonds' => $points,
                        'bonus_points' => 0,
                        'is_active' => true,
                    ]
                );
                $syncedCount++;
            }

            $results[$gameSlug] = [
                'success' => true,
                'provider_game_code' => $g2bCode,
                'synced_count' => $syncedCount
            ];
        }

        return $results;
    }
}
