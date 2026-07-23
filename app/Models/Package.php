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
        'stock_status', // available, limited, out_of_stock
        'last_stock_check_at',
        'provider_stock_message',
    ];

    protected $casts = [
        'provider_price_khr' => 'integer',
        'selling_price_khr' => 'integer',
        'price_khr' => 'integer',
        'original_price_khr' => 'integer',
        'points_or_diamonds' => 'integer',
        'bonus_points' => 'integer',
        'is_active' => 'boolean',
        'last_stock_check_at' => 'datetime',
    ];

    public function getStockStatusAttribute($value): string
    {
        return strtolower((string)($value ?? 'available'));
    }

    /**
     * Safely cast BSON Decimal128 or string values to float.
     */
    private function safeFloat($value): float
    {
        if ($value instanceof \MongoDB\BSON\Decimal128) {
            return (float) (string) $value;
        }
        return (float) ($value ?? 0.0);
    }

    public function getPriceUsdAttribute($value): float
    {
        return $this->safeFloat($value);
    }

    public function getSellingPriceUsdAttribute($value): float
    {
        return $this->safeFloat($value ?? $this->attributes['price_usd'] ?? 0.0);
    }

    public function getProviderPriceUsdAttribute($value): float
    {
        return $this->safeFloat($value ?? $this->attributes['original_price_usd'] ?? 0.0);
    }

    public function getOriginalPriceUsdAttribute($value): float
    {
        return $this->safeFloat($value ?? 0.0);
    }

    public function getProfitAmountAttribute($value): float
    {
        return $this->safeFloat($value ?? 0.0);
    }

    public function getProfitPercentageAttribute($value): float
    {
        return $this->safeFloat($value ?? 0.0);
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Recalculate profit_amount and profit_percentage based on current selling_price_usd & provider_price_usd.
     */
    public function recalculateProfit(): void
    {
        $providerPrice = $this->safeFloat($this->provider_price_usd ?? $this->original_price_usd ?? 0.0);
        $sellingPrice = $this->safeFloat($this->selling_price_usd ?? $this->price_usd ?? 0.0);

        $this->profit_amount = round($sellingPrice - $providerPrice, 2);
        $this->profit_percentage = $providerPrice > 0 
            ? round(($this->profit_amount / $providerPrice) * 100, 2) 
            : 0.0;
    }
}
