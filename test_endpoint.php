<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new App\Services\G2BulkSyncService();

echo "Testing fetchCatalogue for 'mlbb':\n";
$mlbb = $service->fetchCatalogue('mlbb', true);
echo "MLBB items count: " . count($mlbb) . "\n";

echo "Testing fetchCatalogue for 'mlbb_global':\n";
$global = $service->fetchCatalogue('mlbb_global', true);
echo "MLBB Global items count: " . count($global) . "\n";
