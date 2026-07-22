<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class PriceAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'package_id',
        'package_name',
        'game_name',
        'old_selling_price_usd',
        'new_selling_price_usd',
        'old_selling_price_khr',
        'new_selling_price_khr',
        'provider_price_usd',
        'old_profit_amount',
        'new_profit_amount',
        'new_profit_percentage',
        'ip_address',
        'reason',
    ];

    protected $casts = [
        'old_selling_price_usd' => 'float',
        'new_selling_price_usd' => 'float',
        'old_selling_price_khr' => 'integer',
        'new_selling_price_khr' => 'integer',
        'provider_price_usd' => 'float',
        'old_profit_amount' => 'float',
        'new_profit_amount' => 'float',
        'new_profit_percentage' => 'float',
    ];
}
