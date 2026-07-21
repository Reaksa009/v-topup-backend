<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Models\Payment;

class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function create(array $data)
    {
        return Payment::create($data);
    }

    public function find($id)
    {
        return Payment::with('order')->find($id);
    }

    public function findByOrderNo(string $orderNo)
    {
        return Payment::where('order_no', $orderNo)->first();
    }

    public function updateStatus($id, string $status, $verifiedByUserId = null, ?string $rejectionReason = null)
    {
        $payment = Payment::findOrFail($id);
        $payment->status = $status;
        if ($verifiedByUserId) {
            $payment->verified_by_user_id = $verifiedByUserId;
            $payment->verified_at = now();
        }
        if ($rejectionReason) {
            $payment->rejection_reason = $rejectionReason;
        }
        $payment->save();
        return $payment;
    }

    public function getPending()
    {
        return Payment::with('order')->where('status', 'pending')->orderBy('created_at', 'asc')->get();
    }

    public function all()
    {
        return Payment::with('order')->orderBy('created_at', 'desc')->get();
    }

    public function allPaginated(int $perPage = 15)
    {
        return Payment::with('order')->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
