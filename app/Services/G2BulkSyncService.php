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
        'mobile-legends-global' => 'mlbb',
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
     * Preserves custom admin selling prices and updates provider prices & metadata.
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
                $providerPriceUsd = round((float)($item['amount'] ?? 0), 2);
                $providerPriceKhr = (int)round($providerPriceUsd * 4100);

                preg_match('/\d+/', (string)$item['name'], $matches);
                $points = isset($matches[0]) ? (int)$matches[0] : 0;

                // Locate existing package by game_id and provider_catalogue_name
                $package = Package::where('game_id', $game->id)
                    ->where('provider_catalogue_name', (string)$item['name'])
                    ->first();

                if ($package) {
                    // Update ONLY provider metadata & provider prices. DO NOT overwrite selling_price_usd!
                    $package->provider = 'g2bulk';
                    $package->provider_game_code = $g2bCode;
                    $package->provider_catalogue_id = isset($item['id']) ? (string)$item['id'] : null;
                    $package->provider_catalogue_name = (string)$item['name'];
                    $package->provider_price_usd = $providerPriceUsd;
                    $package->provider_price_khr = $providerPriceKhr;

                    // Ensure selling_price_usd exists
                    if (!$package->selling_price_usd) {
                        $package->selling_price_usd = (float)($package->price_usd ?? round($providerPriceUsd * 1.10, 2));
                    }
                    if (!$package->selling_price_khr) {
                        $package->selling_price_khr = (int)($package->price_khr ?? round($package->selling_price_usd * 4100));
                    }

                    // Keep price_usd and price_khr in sync with selling prices
                    $package->price_usd = $package->selling_price_usd;
                    $package->price_khr = $package->selling_price_khr;

                    $package->recalculateProfit();
                    $package->save();
                } else {
                    // Create new package record with default markup
                    $defaultSellingUsd = round($providerPriceUsd * (1 + ($markupPercentage / 100)), 2);
                    $defaultSellingKhr = (int)round($defaultSellingUsd * 4100);
                    $profitAmount = round($defaultSellingUsd - $providerPriceUsd, 2);
                    $profitPct = $providerPriceUsd > 0 ? round(($profitAmount / $providerPriceUsd) * 100, 2) : 0.0;

                    Package::create([
                        'game_id' => $game->id,
                        'provider' => 'g2bulk',
                        'provider_game_code' => $g2bCode,
                        'provider_catalogue_id' => isset($item['id']) ? (string)$item['id'] : null,
                        'provider_catalogue_name' => (string)$item['name'],
                        'name_en' => (string)$item['name'],
                        'name_kh' => (string)$item['name'],
                        'provider_price_usd' => $providerPriceUsd,
                        'provider_price_khr' => $providerPriceKhr,
                        'selling_price_usd' => $defaultSellingUsd,
                        'selling_price_khr' => $defaultSellingKhr,
                        'price_usd' => $defaultSellingUsd,
                        'price_khr' => $defaultSellingKhr,
                        'original_price_usd' => $providerPriceUsd,
                        'profit_amount' => $profitAmount,
                        'profit_percentage' => $profitPct,
                        'points_or_diamonds' => $points,
                        'bonus_points' => 0,
                        'is_active' => true,
                    ]);
                }

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
