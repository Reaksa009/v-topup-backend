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
        'provider_price_usd',
        'provider_price_khr',
        'selling_price_usd',
        'selling_price_khr',
        'price_usd', // Alias for backward compatibility
        'price_khr', // Alias for backward compatibility
        'original_price_usd',
        'original_price_khr',
        'profit_amount',
        'profit_percentage',
        'name_en',
        'name_kh',
        'points_or_diamonds',
        'bonus_points',
        'is_active',
    ];

    protected $casts = [
        'provider_price_usd' => 'float',
        'provider_price_khr' => 'integer',
        'selling_price_usd' => 'float',
        'selling_price_khr' => 'integer',
        'price_usd' => 'float',
        'price_khr' => 'integer',
        'original_price_usd' => 'float',
        'original_price_khr' => 'integer',
        'profit_amount' => 'float',
        'profit_percentage' => 'float',
        'is_active' => 'boolean',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Recalculate profit_amount and profit_percentage based on current selling_price_usd & provider_price_usd.
     */
    public function recalculateProfit(): void
    {
        $providerPrice = (float)($this->provider_price_usd ?? $this->original_price_usd ?? 0.0);
        $sellingPrice = (float)($this->selling_price_usd ?? $this->price_usd ?? 0.0);

        $this->profit_amount = round($sellingPrice - $providerPrice, 2);
        $this->profit_percentage = $providerPrice > 0 
            ? round(($this->profit_amount / $providerPrice) * 100, 2) 
            : 0.0;
    }
}
