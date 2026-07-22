<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ApiLog extends Model
{
    protected $collection = 'api_logs';

    protected $fillable = [
        'request_id',
        'order_no',
        'player_id',
        'provider',
        'url',
        'method',
        'payload',
        'response',
        'status_code',
        'latency_ms',
        'error',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
        'latency_ms' => 'integer',
    ];
}
