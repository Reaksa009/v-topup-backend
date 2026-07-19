<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ApiLog extends Model
{
    protected $collection = 'api_logs';

    protected $fillable = [
        'url',
        'method',
        'payload',
        'response',
        'status_code',
        'error',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
    ];
}
