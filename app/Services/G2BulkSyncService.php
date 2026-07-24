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
                $response = Http::timeout(5)
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
    /**
     * Purge targeted Redis cache keys related to a game or home page. NEVER flush global Redis!
     */
    public static function purgeTargetedCache(?string $gameSlug = null): void
    {
        Cache::forget('home_page_data_v1');
        Cache::forget('featured_games');
        Cache::forget('active_banners');

        if ($gameSlug) {
            $cleanSlug = str_replace([' ', '%20'], '-', trim(strtolower($gameSlug)));
            Cache::forget("game_{$cleanSlug}");
            Cache::forget("game_{$gameSlug}");
            Cache::forget("packages_{$cleanSlug}");
            Cache::forget("packages_{$gameSlug}");
        }
    }

    /**
     * Synchronize G2Bulk packages into MongoDB.
     * Preserves custom admin selling prices and updates provider prices & stock status.
     */
    public function syncAllPackages(float $markupPercentage = 10.0): array
    {
        $results = [];
        $telegramService = app(TelegramNotificationService::class);
        $stockNotifyService = app(StockNotificationService::class);

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

            // 1. Group items by normalized_name for smart deduplication
            $grouped = [];
            foreach ($catalogues as $item) {
                $rawName = (string)($item['name'] ?? '');
                $normName = $this->normalizePackageName($rawName);
                $price = round((float)($item['amount'] ?? 0), 2);
                $catId = isset($item['id']) ? (int)$item['id'] : 0;
                $status = strtolower((string)($item['status'] ?? 'available'));

                $grouped[$normName][] = [
                    'item' => $item,
                    'norm_name' => $normName,
                    'price' => $price,
                    'cat_id' => $catId,
                    'status' => $status,
                ];
            }

            // 2. Choose ONLY ONE package per normalized_name (Priority: Available > Lowest Price > Lowest ID)
            $finalCatalogues = [];
            foreach ($grouped as $normName => $itemsList) {
                usort($itemsList, function ($a, $b) {
                    $aAvail = ($a['status'] === 'available' || $a['status'] === 'active' || $a['status'] === '1' || $a['status'] === 1) ? 1 : 0;
                    $bAvail = ($b['status'] === 'available' || $b['status'] === 'active' || $b['status'] === '1' || $b['status'] === 1) ? 1 : 0;

                    if ($aAvail !== $bAvail) {
                        return $bAvail <=> $aAvail;
                    }
                    if ($a['price'] !== $b['price']) {
                        return $a['price'] <=> $b['price'];
                    }
                    return $a['cat_id'] <=> $b['cat_id'];
                });

                $finalCatalogues[] = $itemsList[0]['item'];
            }

            $syncedCount = 0;
            $recoveredCount = 0;
            $syncedPackageIds = [];

            foreach ($finalCatalogues as $item) {
                $rawName = (string)($item['name'] ?? '');
                $normName = $this->normalizePackageName($rawName);
                $providerPriceUsd = round((float)($item['amount'] ?? 0), 2);
                $providerPriceKhr = (int)round($providerPriceUsd * 4100);

                preg_match('/\d+/', $rawName, $matches);
                $points = isset($matches[0]) ? (int)$matches[0] : 0;

                $isEvent = $this->detectEvent($rawName);
                $isBestSelling = $this->detectBestSelling($gameSlug, $normName, $rawName);
                $categoryType = $this->determineCategoryType($rawName, $normName, $isBestSelling, $isEvent);
                $displayOrder = $this->calculateDisplayOrder($categoryType, $normName);

                $packageType = $isBestSelling ? 'best_selling' : ($isEvent ? 'event' : 'normal');
                $isPopular = $isBestSelling;
                $isPass = (str_contains(strtolower($rawName), 'pass') || str_contains(strtolower($rawName), 'weekly') || str_contains(strtolower($rawName), 'membership'));

                // Determine provider stock status
                $itemStockStatus = 'available';
                if (isset($item['status']) && in_array(strtolower($item['status']), ['inactive', 'out_of_stock', 'disabled'])) {
                    $itemStockStatus = 'out_of_stock';
                }

                // Locate existing package by game_id and normalized_name or provider_catalogue_name
                $package = Package::where('game_id', $game->id)
                    ->where(function ($q) use ($normName, $rawName) {
                        $q->where('normalized_name', $normName)
                          ->orWhere('duplicate_group', $normName)
                          ->orWhere('provider_catalogue_name', $rawName);
                    })
                    ->first();

                if ($package) {
                    $oldStockStatus = $package->stock_status;
                    $newStockStatus = $itemStockStatus;

                    // Respect admin overrides if present
                    $finalCategoryType = $package->admin_category_override ?? $categoryType;
                    $finalBestSelling = $package->admin_best_selling_override ?? $isBestSelling;
                    $finalDisplayOrder = $package->admin_display_order_override ?? $displayOrder;
                    $finalVisible = $package->admin_visible_override ?? true;

                    $package->provider = 'g2bulk';
                    $package->provider_game_code = $g2bCode;
                    $package->provider_catalogue_id = isset($item['id']) ? (string)$item['id'] : null;
                    $package->provider_catalogue_name = $rawName;
                    $package->provider_price_usd = $providerPriceUsd;
                    $package->provider_price_khr = $providerPriceKhr;
                    $package->stock_status = $newStockStatus;
                    $package->last_stock_check_at = now();
                    $package->provider_stock_message = 'Synchronized from G2Bulk API';

                    // Smart classification attributes
                    $package->normalized_name = $normName;
                    $package->duplicate_group = $normName;
                    $package->category_type = $finalCategoryType;
                    $package->is_best_selling = $finalBestSelling;
                    $package->is_event = $isEvent;
                    $package->display_order = $finalDisplayOrder;
                    $package->visible = $finalVisible;
                    $package->package_type = $packageType;
                    $package->is_popular = $isPopular;
                    $package->is_pass = $isPass;

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
                    $syncedPackageIds[] = $package->id;

                    // Detect Stock Recovery Transition (out_of_stock -> available)
                    if ($oldStockStatus === 'out_of_stock' && $newStockStatus === 'available') {
                        $recoveredCount++;

                        // Log Stock Audit Entry
                        \App\Models\StockAuditLog::create([
                            'package_id' => $package->id,
                            'game_id' => $game->id,
                            'package_name' => $package->name_en,
                            'game_name' => $game->name_en,
                            'old_status' => 'out_of_stock',
                            'new_status' => 'available',
                            'provider_response' => $item,
                            'triggered_by' => 'sync',
                            'created_at' => now(),
                        ]);

                        // Clear Targeted Redis Cache
                        self::purgeTargetedCache($game->slug);

                        // Send Telegram Recovered Alert
                        $telegramService->notifyProviderRecovered($game->name_en, $package->name_en);

                        // Notify subscribed customers
                        $stockNotifyService->notifySubscribers($package->id);
                    }
                } else {
                    // Create new package record with default markup
                    $defaultSellingUsd = round($providerPriceUsd * (1 + ($markupPercentage / 100)), 2);
                    $defaultSellingKhr = (int)round($defaultSellingUsd * 4100);
                    $profitAmount = round($defaultSellingUsd - $providerPriceUsd, 2);
                    $profitPct = $providerPriceUsd > 0 ? round(($profitAmount / $providerPriceUsd) * 100, 2) : 0.0;

                    $newPkg = Package::create([
                        'game_id' => $game->id,
                        'provider' => 'g2bulk',
                        'provider_game_code' => $g2bCode,
                        'provider_catalogue_id' => isset($item['id']) ? (string)$item['id'] : null,
                        'provider_catalogue_name' => $rawName,
                        'name_en' => $rawName,
                        'name_kh' => $rawName,
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
                        'stock_status' => $itemStockStatus,
                        'last_stock_check_at' => now(),
                        'provider_stock_message' => 'Created via G2Bulk Sync',
                        'normalized_name' => $normName,
                        'duplicate_group' => $normName,
                        'category_type' => $categoryType,
                        'is_best_selling' => $isBestSelling,
                        'is_event' => $isEvent,
                        'display_order' => $displayOrder,
                        'visible' => true,
                        'package_type' => $packageType,
                        'is_popular' => $isPopular,
                        'is_pass' => $isPass,
                    ]);
                    $syncedPackageIds[] = $newPkg->id;
                }

                $syncedCount++;
            }

            // Delete stale/unmatched duplicate packages for this game
            if (!empty($syncedPackageIds)) {
                Package::where('game_id', $game->id)
                    ->whereNotIn('_id', $syncedPackageIds)
                    ->delete();
            }
            
            self::purgeTargetedCache($game->slug);

            $results[$gameSlug] = [
                'success' => true,
                'provider_game_code' => $g2bCode,
                'synced_count' => $syncedCount,
                'recovered_count' => $recoveredCount,
            ];
        }

        return $results;
    }

    public function normalizePackageName(string $rawName): string
    {
        $name = trim($rawName);

        // Remove noise in brackets/parentheses e.g. (Promo), (MLBB), etc.
        $nameClean = preg_replace('/\((?:promo|bonus|mlbb|global|package|packages|discount|special)\)/i', '', $name);

        // Remove noise words
        $noiseWords = ['diamonds', 'diamond', 'uc', 'vp', 'tokens', 'token', 'robux', 'promo', 'bonus', 'mlbb', 'global', 'package', 'packages'];
        foreach ($noiseWords as $word) {
            $nameClean = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $nameClean);
        }

        $nameClean = trim(preg_replace('/\s+/', ' ', $nameClean));

        // If numeric value exists, extract clean point string
        if (preg_match('/^(\d+)$/', $nameClean, $matches)) {
            return (string)$matches[1];
        }

        return $nameClean ?: $rawName;
    }

    public function detectEvent(string $rawName): bool
    {
        $keywords = [
            'event', 'promo', 'limited', 'special', 'anniversary', 'recharge',
            'festival', 'lucky', 'bonus', 'season', 'msc', 'm series', 'm4', 'm5', 'm6',
            'worlds', 'championship', 'summer', 'winter', 'new year', 'christmas',
            'halloween', 'valentine', 'ramadan', 'khmer new year', 'songkran'
        ];

        $nameLower = strtolower($rawName);
        foreach ($keywords as $kw) {
            if (str_contains($nameLower, $kw)) {
                return true;
            }
        }
        return false;
    }

    public function detectBestSelling(string $gameSlug, string $normalizedName, string $rawName): bool
    {
        $nameLower = strtolower($rawName);
        if (str_contains($nameLower, 'weekly') || str_contains($nameLower, 'twilight') || str_contains($nameLower, 'membership')) {
            return true;
        }

        $normDigits = (int)$normalizedName;

        if ($gameSlug === 'mobile-legends' || $gameSlug === 'mobile-khmer' || $gameSlug === 'mobile-legends-global') {
            return in_array($normDigits, [86, 172, 257, 344, 514, 706, 878, 963]);
        } elseif ($gameSlug === 'pubg-mobile') {
            return in_array($normDigits, [60, 325, 660, 1800, 3850]);
        } elseif ($gameSlug === 'free-fire') {
            return in_array($normDigits, [100, 310, 520]);
        } elseif ($gameSlug === 'valorant') {
            return in_array($normDigits, [475, 1000, 2050, 3650, 5350]);
        } elseif ($gameSlug === 'honor-of-kings') {
            return in_array($normDigits, [80, 240, 400, 800, 1200]);
        }

        return false;
    }

    public function determineCategoryType(string $rawName, string $normalizedName, bool $isBestSelling, bool $isEvent): string
    {
        if ($isEvent) {
            return 'event';
        }
        if ($isBestSelling) {
            return 'best_selling';
        }

        $nameLower = strtolower($rawName);
        if (str_contains($nameLower, 'weekly')) {
            return 'weekly';
        }
        if (str_contains($nameLower, 'monthly')) {
            return 'monthly';
        }
        if (str_contains($nameLower, 'pass') || str_contains($nameLower, 'membership') || str_contains($nameLower, 'twilight') || str_contains($nameLower, 'royale')) {
            return 'pass';
        }

        return 'normal';
    }

    public function calculateDisplayOrder(string $categoryType, string $normalizedName): int
    {
        $num = (int)$normalizedName;
        switch ($categoryType) {
            case 'best_selling':
                return 1000 + ($num > 0 ? $num : 99);
            case 'weekly':
                return 2000;
            case 'monthly':
                return 3000;
            case 'pass':
                return 4000;
            case 'normal':
                return 5000 + ($num > 0 ? $num : 99);
            case 'event':
                return 6000;
            default:
                return 7000;
        }
    }
}
