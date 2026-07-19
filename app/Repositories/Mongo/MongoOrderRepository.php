<?php

namespace App\Repositories\Mongo;

use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Models\Order;

class MongoOrderRepository extends EloquentOrderRepository
{
    /**
     * Get Sales report grouped by date (MongoDB compatible).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getSalesReport()
    {
        // Load all orders ordered by creation time
        $orders = Order::orderBy('created_at', 'desc')->get();

        // Group by Y-m-d format of created_at
        $grouped = $orders->groupBy(function ($order) {
            return $order->created_at ? $order->created_at->format('Y-m-d') : now()->format('Y-m-d');
        });

        $report = [];
        foreach ($grouped as $date => $dateOrders) {
            $report[] = [
                'date' => $date,
                'revenue' => (float)$dateOrders->where('status', 'completed')->sum('total_price_usd'),
                'orders' => $dateOrders->count()
            ];
        }

        return collect($report);
    }
}
