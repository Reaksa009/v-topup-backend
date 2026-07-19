<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$games = App\Models\Game::all();
foreach ($games as $game) {
    echo "ID: {$game->id} | Slug: {$game->slug} | Name: {$game->name_en}\n";
}
