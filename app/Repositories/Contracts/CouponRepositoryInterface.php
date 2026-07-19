<?php

namespace App\Repositories\Contracts;

interface CouponRepositoryInterface
{
    public function findByCode(string $code);
    public function incrementUsage($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function all();
}
