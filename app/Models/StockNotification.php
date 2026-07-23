<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class StockNotification extends Model
{
    use HasFactory;

    protected $table = 'stock_notifications';

    protected $fillable = [
        'customer_id',
        'email',
        'telegram_id',
        'package_id',
        'game_id',
        'status', // pending, notified
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
