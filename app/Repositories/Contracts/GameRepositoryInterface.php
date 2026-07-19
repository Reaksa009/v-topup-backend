<?php

namespace App\Repositories\Contracts;

interface GameRepositoryInterface
{
    public function allActive();
    public function findBySlug(string $slug);
    public function find($id);
    public function getPopular(int $limit);
    public function getFeatured(int $limit);
    public function searchAndFilter(string $search, string $categoryName);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function all();
}
