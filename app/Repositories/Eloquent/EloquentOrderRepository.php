<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function create(array $data)
    {
        return Order::create($data);
    }

    public function find($id)
    {
        return Order::with(['user', 'game', 'package', 'payment'])->find($id);
    }

    public function findByOrderNo(string $orderNo)
    {
        return Order::with(['user', 'game', 'package', 'payment'])->where('order_no', $orderNo)->first();
    }

    public function getUserOrders($userId)
    {
        return Order::with(['game', 'package', 'payment'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function updateStatus(string $orderNo, string $status)
    {
        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $order->status = $status;
        $order->save();
        return $order;
    }

    public function getAnalytics()
    {
        return [
            'total_sales' => Order::where('status', 'completed')->sum('total_price_usd'),
            'orders_count' => Order::count(),
            'pending_count' => Order::where('status', 'waiting_verification')->count(),
            'completed_count' => Order::where('status', 'completed')->count(),
        ];
    }

    public function getSalesReport()
    {
        return Order::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(total_price_usd) as revenue'),
            DB::raw('COUNT(id) as orders')
        )
        ->groupBy('date')
        ->orderBy('date', 'desc')
        ->get();
    }

    public function all()
    {
        return Order::with(['user', 'game', 'package'])->orderBy('created_at', 'desc')->get();
    }
}
