<?php

namespace App\Repositories\Contracts;

interface OrderRepositoryInterface
{
    public function create(array $data);
    public function find($id);
    public function findByOrderNo(string $orderNo);
    public function getUserOrders($userId);
    public function updateStatus(string $orderNo, string $status);
    public function getAnalytics();
    public function getSalesReport();
    public function all();
}
