<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\CouponRepositoryInterface;
use App\Models\Coupon;

class EloquentCouponRepository implements CouponRepositoryInterface
{
    public function findByCode(string $code)
    {
        return Coupon::where('code', $code)->first();
    }

    public function incrementUsage($id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->increment('usage_count');
        return $coupon;
    }

    public function create(array $data)
    {
        return Coupon::create($data);
    }

    public function update($id, array $data)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update($data);
        return $coupon;
    }

    public function delete($id)
    {
        $coupon = Coupon::findOrFail($id);
        return $coupon->delete();
    }

    public function all()
    {
        return Coupon::all();
    }
}
