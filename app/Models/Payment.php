<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'transaction_no',
        'amount_usd',
        'amount_khr',
        'payment_method',
        'receipt_image_url',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
        'status',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'amount_khr' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_no', 'order_no');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
