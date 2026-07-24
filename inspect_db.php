<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$game = App\Models\Game::where('slug', 'mobile-legends')->first();
$packages = App\Models\Package::where('game_id', $game->id)->orderBy('display_order', 'asc')->get();

echo "Mobile Legends Packages Count: " . $packages->count() . "\n";
echo str_repeat('-', 90) . "\n";

foreach ($packages->take(15) as $pkg) {
    echo sprintf(
        "%-30s | Norm: %-10s | Cat: %-12s | BestSeller: %-3s | Order: %-5d | Price: $%s\n",
        substr($pkg->name_en, 0, 30),
        $pkg->normalized_name ?? 'N/A',
        $pkg->category_type ?? 'N/A',
        $pkg->is_best_selling ? 'YES' : 'NO',
        $pkg->display_order ?? 999,
        $pkg->selling_price_usd
    );
}
