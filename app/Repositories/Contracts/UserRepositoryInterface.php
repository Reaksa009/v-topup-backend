<?php

namespace App\Repositories\Contracts;

interface UserRepositoryInterface
{
    public function find($id);
    public function findByEmail(string $email);
    public function create(array $data);
    public function update($id, array $data);
    public function all();
    public function allPaginated(int $perPage = 15);
}
