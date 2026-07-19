<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class News extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_en',
        'title_kh',
        'content_en',
        'content_kh',
        'thumbnail_url',
        'views',
        'is_published',
    ];

    protected $casts = [
        'views' => 'integer',
        'is_published' => 'boolean',
    ];
}
