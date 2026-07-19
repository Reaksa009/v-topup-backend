<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Game;
use App\Models\Package;
use Illuminate\Support\Facades\Log;

class SyncG2BulkPackages extends Command
{
    protected $signature = 'g2bulk:sync-packages {--markup=10 : The retail profit markup percentage}';
    protected $description = 'Sync game packages and catalogues dynamically from G2Bulk wholesaling API';

    public function handle()
    {
        $markupPercentage = (float)$this->option('markup');
        $this->info("Starting G2Bulk Catalog synchronization with {$markupPercentage}% markup...");

        $gameMappings = [
            'mobile-legends' => 'mlbb',
            'mobile-khmer' => 'mlbb',
            'free-fire' => 'freefire_global',
            'pubg-mobile' => 'pubgm',
            'valorant' => 'valorant_sg',
            'roblox' => 'roblox',
            'honor-of-kings' => 'hok',
        ];

        foreach ($gameMappings as $gameSlug => $g2bCode) {
            $game = Game::where('slug', $gameSlug)->first();
            if (!$game) {
                $this->warn("Skipping Game '{$gameSlug}': not found in MongoDB.");
                continue;
            }

            $url = "https://api.g2bulk.com/v1/games/{$g2bCode}/catalogue";
            $this->info("Fetching catalogue for {$game->name_en} ({$g2bCode})...");

            try {
                $response = Http::timeout(10)->get($url);
                if ($response->failed()) {
                    $this->error("Failed to fetch catalogue for {$g2bCode}: " . $response->body());
                    continue;
                }

                $data = $response->json();
                if (!isset($data['catalogues']) || !is_array($data['catalogues'])) {
                    $this->error("Invalid response format for {$g2bCode}.");
                    continue;
                }

                $catalogCount = 0;
                foreach ($data['catalogues'] as $item) {
                    $wholesalePrice = (float)$item['amount'];
                    $retailPrice = $wholesalePrice * (1 + ($markupPercentage / 100));
                    $priceKhr = (int)round($retailPrice * 4100);

                    // Parse diamonds/points count from name
                    preg_match('/\d+/', $item['name'], $matches);
                    $points = isset($matches[0]) ? (int)$matches[0] : 0;

                    // Update or create package in MongoDB
                    Package::updateOrCreate(
                        [
                            'game_id' => $game->id,
                            'name_en' => $item['name'],
                        ],
                        [
                            'name_kh' => $item['name'], // Khmer name uses G2Bulk package name
                            'price_usd' => $retailPrice,
                            'price_khr' => $priceKhr,
                            'original_price_usd' => $wholesalePrice, // Store wholesale price as reference
                            'points_or_diamonds' => $points,
                            'bonus_points' => 0,
                            'is_active' => true
                        ]
                    );
                    $catalogCount++;
                }

                $this->info("Successfully synced {$catalogCount} packages for {$game->name_en}.");

            } catch (\Exception $e) {
                $this->error("Exception while syncing {$g2bCode}: " . $e->getMessage());
            }
        }

        $this->info("G2Bulk Catalog sync completed.");
        return 0;
    }
}
