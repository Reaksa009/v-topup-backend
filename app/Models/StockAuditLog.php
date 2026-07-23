<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class StockAuditLog extends Model
{
    use HasFactory;

    protected $table = 'stock_audit_logs';

    protected $fillable = [
        'package_id',
        'game_id',
        'package_name',
        'game_name',
        'old_status',
        'new_status',
        'admin_id',
        'admin_name',
        'reason',
        'ip_address',
        'provider_response',
        'triggered_by', // sync, order_fulfillment, admin_override
    ];

    protected $casts = [
        'provider_response' => 'array',
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
