<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_en',
        'name_kh',
        'slug',
        'status',
    ];

    public function games()
    {
        return $this->hasMany(Game::class)->orderBy('order_index');
    }
}
