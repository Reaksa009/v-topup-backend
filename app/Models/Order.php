<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_no',
        'game_id',
        'package_id',
        'provider',
        'provider_game_code',
        'provider_catalogue_id',
        'provider_catalogue_name',
        'provider_price_usd',
        'selling_price_usd',
        'selling_price_khr',
        'profit_amount',
        'profit_percentage',
        'game_name',
        'package_name',
        'player_id',
        'server_id',
        'qty',
        'original_price_usd',
        'price_usd',
        'discount_usd',
        'total_price_usd',
        'total_price_khr',
        'status',
        'payment_method',
        'coupon_code',
        'g2b_order_id',
        'waiting_reason',
        'provider_status_snapshot',
        'retry_attempts',
        'retry_count',
        'last_retry_at',
        'next_retry_at',
        'estimated_retry_at',
        'completed_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'provider_price_usd' => 'float',
        'selling_price_usd' => 'float',
        'selling_price_khr' => 'integer',
        'profit_amount' => 'float',
        'profit_percentage' => 'float',
        'original_price_usd' => 'float',
        'price_usd' => 'float',
        'discount_usd' => 'float',
        'total_price_usd' => 'float',
        'total_price_khr' => 'integer',
        'retry_attempts' => 'integer',
        'retry_count' => 'integer',
        'last_retry_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'estimated_retry_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'order_no', 'order_no');
    }
}
