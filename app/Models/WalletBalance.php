<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class WalletBalance extends Model
{
    protected $collection = 'wallet_balances';

    protected $fillable = [
        'provider',
        'balance',
        'currency',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'balance' => 'float',
    ];
}
