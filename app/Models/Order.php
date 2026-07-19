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
    ];

    protected $casts = [
        'qty' => 'integer',
        'original_price_usd' => 'decimal:2',
        'price_usd' => 'decimal:2',
        'discount_usd' => 'decimal:2',
        'total_price_usd' => 'decimal:2',
        'total_price_khr' => 'integer',
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
