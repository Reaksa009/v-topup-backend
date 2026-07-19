<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$logs = App\Models\ApiLog::where('url', 'like', '%checkPlayerId%')
    ->orderBy('created_at', 'desc')
    ->take(5)
    ->get();

foreach ($logs as $l) {
    echo "========================================\n";
    echo "URL: " . $l->url . "\n";
    echo "Payload: " . json_encode($l->payload, JSON_PRETTY_PRINT) . "\n";
    echo "Status Code: " . $l->status_code . "\n";
    echo "Response: " . json_encode($l->response, JSON_PRETTY_PRINT) . "\n";
    echo "Error: " . $l->error . "\n";
}
