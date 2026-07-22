<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'provider',
        'provider_game_code',
        'provider_catalogue_id',
        'provider_catalogue_name',
        'name_en',
        'name_kh',
        'price_usd',
        'price_khr',
        'original_price_usd',
        'points_or_diamonds',
        'bonus_points',
        'is_active',
    ];

    protected $casts = [
        'price_usd' => 'decimal:2',
        'original_price_usd' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
