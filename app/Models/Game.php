<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name_en',
        'name_kh',
        'slug',
        'logo_url',
        'banner_url',
        'description_en',
        'description_kh',
        'player_id_label_en',
        'player_id_label_kh',
        'server_id_required',
        'server_id_label_en',
        'server_id_label_kh',
        'status',
        'order_index',
        'is_popular',
        'is_featured',
    ];

    protected $casts = [
        'server_id_required' => 'boolean',
        'status' => 'boolean',
        'is_popular' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class)->where('is_active', true);
    }
}
