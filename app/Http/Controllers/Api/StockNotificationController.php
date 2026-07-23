<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\StockNotificationService;

class StockNotificationController extends Controller
{
    protected StockNotificationService $stockNotificationService;

    public function __construct(StockNotificationService $stockNotificationService)
    {
        $this->stockNotificationService = $stockNotificationService;
    }

    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|string',
            'game_id' => 'nullable|string',
            'email' => 'required_without:telegram_id|nullable|email',
            'telegram_id' => 'required_without:email|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = $request->user();
        $subscription = $this->stockNotificationService->subscribe([
            'customer_id' => $user ? $user->id : null,
            'package_id' => $request->package_id,
            'game_id' => $request->game_id,
            'email' => $request->email ?? ($user ? $user->email : null),
            'telegram_id' => $request->telegram_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription saved! We will notify you as soon as this package is back in stock.',
            'data' => $subscription
        ], 201);
    }
}
