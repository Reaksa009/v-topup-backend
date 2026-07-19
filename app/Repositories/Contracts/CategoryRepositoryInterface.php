<?php

namespace App\Repositories\Contracts;

interface CategoryRepositoryInterface
{
    public function allActive();
    public function findBySlug(string $slug);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function all();
}
