<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CheckoutService;
use App\Repositories\Contracts\CouponRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    protected CheckoutService $checkoutService;
    protected CouponRepositoryInterface $couponRepository;
    protected OrderRepositoryInterface $orderRepository;

    public function __construct(
        CheckoutService $checkoutService,
        CouponRepositoryInterface $couponRepository,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->checkoutService = $checkoutService;
        $this->couponRepository = $couponRepository;
        $this->orderRepository = $orderRepository;
    }

    public function validateCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'subtotal' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $coupon = $this->couponRepository->findByCode($request->code);

        if (!$coupon || !$coupon->isValidForAmount($request->subtotal)) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon code is invalid, expired, or minimum purchase threshold not met.'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Coupon is valid.',
            'data' => $coupon
        ]);
    }

    public function checkout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|string', // JSON string containing game_id, package_id, player_id, server_id, qty
            'payment_method' => 'required|string|in:khqr_bakong,aba_qr,wing',
            'transaction_no' => 'required|string|max:100|unique:payments,transaction_no',
            'receipt' => 'nullable|image|mimes:jpeg,png,jpg|max:5120', // Max 5MB
            'coupon_code' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $items = json_decode($request->items, true);
        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid items array structure.'
            ], 422);
        }

        try {
            $user = null;
            if ($token = $request->bearerToken()) {
                $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                if ($accessToken) {
                    $user = $accessToken->tokenable;
                }
            }
            
            $order = $this->checkoutService->processCheckout(
                $user,
                $items,
                $request->payment_method,
                $request->transaction_no,
                $request->file('receipt'),
                $request->coupon_code
            );

            return response()->json([
                'success' => true,
                'message' => 'Order checkout processed successfully. Awaiting receipt verification.',
                'data' => $order
            ], 201);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'code' => 'OUT_OF_STOCK',
                'message' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Checkout failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function myOrders(Request $request)
    {
        $orders = $this->orderRepository->getUserOrders($request->user()->id);
        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function generateKhqr(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|string',
            'coupon_code' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $items = json_decode($request->items, true);
        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid items array structure.'
            ], 422);
        }

        try {
            $khqrResponse = $this->checkoutService->generateKhqrPayment(
                $items,
                $request->coupon_code
            );

            return response()->json([
                'success' => true,
                'data' => $khqrResponse
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'code' => 'OUT_OF_STOCK',
                'message' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate KHQR: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkKhqrStatus(Request $request, $md5)
    {
        try {
            $statusResponse = $this->checkoutService->checkKhqrStatus($md5);

            return response()->json([
                'success' => true,
                'data' => $statusResponse
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check KHQR status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function trackOrder($orderNo)
    {
        $orders = \App\Models\Order::where('order_no', $orderNo)->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        $payment = \App\Models\Payment::where('order_no', $orderNo)->first();
        $firstOrder = $orders->first();

        $items = $orders->map(function ($ord) {
            return [
                'id' => $ord->id,
                'game_id' => $ord->game_id,
                'package_id' => $ord->package_id,
                'game_name_en' => $ord->game_name,
                'game_name_kh' => $ord->game_name,
                'package_name_en' => $ord->package_name,
                'package_name_kh' => $ord->package_name,
                'player_id' => $ord->player_id,
                'server_id' => $ord->server_id,
                'qty' => $ord->qty,
                'price_usd' => (float) $ord->price_usd,
                'status' => $ord->status,
                'g2b_order_id' => $ord->g2b_order_id ?? null,
            ];
        });

        $noticeMessage = null;
        if ($firstOrder->status === \App\Enums\OrderStatus::WAITING_PROVIDER_BALANCE) {
            $noticeMessage = "Your payment has been received successfully. Your order is temporarily waiting to be processed because our service provider is currently unavailable. Your order will be processed automatically once the service resumes. No further action is required.";
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_no' => $firstOrder->order_no,
                'status' => strtoupper($firstOrder->status),
                'raw_status' => $firstOrder->status,
                'customer_notice' => $noticeMessage,
                'payment_method' => strtoupper(str_replace('_', ' ', $firstOrder->payment_method)),
                'transaction_no' => $payment ? $payment->transaction_no : ($firstOrder->order_no),
                'total_amount_usd' => (float) $firstOrder->total_price_usd,
                'total_amount_khr' => (int) $firstOrder->total_price_khr,
                'created_at' => $firstOrder->created_at ? $firstOrder->created_at->format('n/j/Y, g:i:s A') : now()->format('n/j/Y, g:i:s A'),
                'items' => $items,
            ]
        ]);
    }
}
