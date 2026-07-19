<?php

namespace App\Repositories\Contracts;

interface PaymentRepositoryInterface
{
    public function create(array $data);
    public function find($id);
    public function findByOrderNo(string $orderNo);
    public function updateStatus($id, string $status, $verifiedByUserId = null, ?string $rejectionReason = null);
    public function getPending();
    public function all();
}
