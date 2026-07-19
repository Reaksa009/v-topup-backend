<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\GameRepositoryInterface;
use App\Models\Game;

class EloquentGameRepository implements GameRepositoryInterface
{
    public function allActive()
    {
        return Game::with('category')->where('status', true)->orderBy('order_index')->get();
    }

    public function findBySlug(string $slug)
    {
        return Game::with(['category', 'packages'])->where('slug', $slug)->first();
    }

    public function find($id)
    {
        return Game::with('packages')->find($id);
    }

    public function getPopular(int $limit)
    {
        return Game::where('status', true)->where('is_popular', true)->orderBy('order_index')->limit($limit)->get();
    }

    public function getFeatured(int $limit)
    {
        return Game::where('status', true)->where('is_featured', true)->orderBy('order_index')->limit($limit)->get();
    }

    public function searchAndFilter(string $search, string $categoryName)
    {
        $query = Game::where('status', true);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'like', "%{$search}%")
                  ->orWhere('name_kh', 'like', "%{$search}%");
            });
        }

        if (!empty($categoryName) && $categoryName !== 'All') {
            $query->whereHas('category', function ($q) use ($categoryName) {
                $q->where('name_en', $categoryName);
            });
        }

        return $query->orderBy('order_index')->get();
    }

    public function create(array $data)
    {
        return Game::create($data);
    }

    public function update($id, array $data)
    {
        $game = Game::findOrFail($id);
        $game->update($data);
        return $game;
    }

    public function delete($id)
    {
        $game = Game::findOrFail($id);
        return $game->delete();
    }

    public function all()
    {
        return Game::with('category')->get();
    }
}
