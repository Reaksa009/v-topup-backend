<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\G2BulkSyncService;

class SyncG2BulkPackages extends Command
{
    protected $signature = 'g2bulk:sync-packages {--markup=10 : The retail profit markup percentage}';
    protected $description = 'Sync game packages and catalogues dynamically from G2Bulk wholesaling API into MongoDB';

    public function handle(G2BulkSyncService $syncService)
    {
        $markupPercentage = (float)$this->option('markup');
        $this->info("Starting G2Bulk Catalog synchronization with {$markupPercentage}% markup...");

        $results = $syncService->syncAllPackages($markupPercentage);

        foreach ($results as $gameSlug => $res) {
            if ($res['success']) {
                $this->info("✓ Synced {$res['synced_count']} packages for '{$gameSlug}' (provider code: {$res['provider_game_code']}).");
            } else {
                $this->warn("! Skipped '{$gameSlug}': {$res['message']}");
            }
        }

        $this->info("G2Bulk Catalog sync completed.");
        return 0;
    }
}
