<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new App\Services\G2BulkSyncService();

echo "Testing Name Normalization:\n";
echo "'86 Diamonds (Promo)' -> " . $service->normalizePackageName('86 Diamonds (Promo)') . "\n";
echo "'325 UC MLBB' -> " . $service->normalizePackageName('325 UC MLBB') . "\n";
echo "'Weekly Pass' -> " . $service->normalizePackageName('Weekly Pass') . "\n";
echo "'Twilight Pass' -> " . $service->normalizePackageName('Twilight Pass') . "\n";

echo "\nExecuting Smart Sync for all games:\n";
$res = $service->syncAllPackages();
print_r($res);

echo "\nQuerying MongoDB Packages for Mobile Legends:\n";
$game = App\Models\Game::where('slug', 'mobile-legends')->first();
$packages = App\Models\Package::where('game_id', $game->id)->orderBy('display_order', 'asc')->get();

foreach ($packages as $pkg) {
    echo sprintf(
        "ID: %s | Raw: %s | Norm: %s | Cat: %s | BestSeller: %s | Order: %d | Price: $%s\n",
        $pkg->id,
        $pkg->name_en,
        $pkg->normalized_name ?? 'N/A',
        $pkg->category_type ?? 'N/A',
        $pkg->is_best_selling ? 'YES' : 'NO',
        $pkg->display_order ?? 999,
        $pkg->selling_price_usd
    );
}
