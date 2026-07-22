<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- Public Authentication & Catalogs ---
Route::get('/health', function () {
    try {
        $cursor = \Illuminate\Support\Facades\DB::connection()->getMongoDB()->command(['ping' => 1]);
        $mongoActive = $cursor ? 'connected' : 'error';
    } catch (\Exception $e) {
        $mongoActive = 'error: ' . $e->getMessage();
    }

    try {
        $redisActive = \Illuminate\Support\Facades\Redis::ping() ? 'connected' : 'error';
    } catch (\Exception $e) {
        $redisActive = 'error: ' . $e->getMessage();
    }

    try {
        $queueDriver = config('queue.default');
        $queueActive = !empty($queueDriver) ? 'connected' : 'error';
    } catch (\Exception $e) {
        $queueActive = 'error: ' . $e->getMessage();
    }

    try {
        $g2bService = app(\App\Services\G2BulkService::class);
        $g2bRes = $g2bService->getWalletBalance();
        $g2bActive = $g2bRes['success'] ? 'connected' : 'degraded';
    } catch (\Exception $e) {
        $g2bActive = 'error: ' . $e->getMessage();
    }

    $isHealthy = (
        str_contains($mongoActive, 'connected') &&
        str_contains($redisActive, 'connected') &&
        str_contains($queueActive, 'connected')
    );

    return response()->json([
        'status' => $isHealthy ? 'healthy' : 'degraded',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'mongodb' => $mongoActive,
            'redis' => $redisActive,
            'queue' => $queueActive,
            'g2bulk' => $g2bActive,
        ]
    ], $isHealthy ? 200 : 500);
});

// --- Public Authentication & Catalogs ---
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::get('/home', [GameController::class, 'home']);
Route::get('/games/active', [GameController::class, 'index']);
Route::get('/games/categories', [GameController::class, 'categories']);
Route::get('/games/slug/{slug}', [GameController::class, 'show']);
Route::get('/games/search', [GameController::class, 'search']);
Route::get('/games/popular', [GameController::class, 'popular']);
Route::get('/games/featured', [GameController::class, 'featured']);
Route::get('/banners/active', [AdminController::class, 'activeBanners']);
Route::get('/news/latest', [GameController::class, 'latestNews']);

Route::get('/settings', [GameController::class, 'getSettings']);
Route::post('/games/verify-player', [GameController::class, 'verifyPlayer'])->middleware('throttle:30,1');
Route::post('/webhooks/g2bulk', [GameController::class, 'g2bulkWebhook']);
Route::post('/coupons/validate', [CheckoutController::class, 'validateCoupon']);

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/orders/checkout', [CheckoutController::class, 'checkout']);
    Route::get('/orders/tracking/{orderNo}', [CheckoutController::class, 'trackOrder']);
    Route::post('/payments/generate-khqr', [CheckoutController::class, 'generateKhqr']);
    Route::get('/payments/check-khqr/{md5}', [CheckoutController::class, 'checkKhqrStatus']);
});

// --- Protected Customer Area ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user/profile', [AuthController::class, 'profile']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/change-password', [AuthController::class, 'changePassword']);
    Route::get('/orders/my-orders', [CheckoutController::class, 'myOrders']);
});

// --- Protected Admin Dashboard Area ---
Route::middleware(['auth:sanctum', \App\Http\Middleware\IsAdmin::class])->group(function () {
    Route::get('/admin/orders', [AdminController::class, 'orders']);
    Route::get('/admin/payments', [AdminController::class, 'payments']);
    Route::post('/admin/payments/{id}/verify', [AdminController::class, 'verifyPayment']);
    Route::get('/admin/analytics', [AdminController::class, 'analytics']);
    Route::get('/admin/reports', [AdminController::class, 'reports']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateUserRole']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);
    
    // Administrative Catalog & Coupon CRUDs
    Route::post('/admin/games', [AdminController::class, 'createGame']);
    Route::put('/admin/games/{id}', [AdminController::class, 'toggleGame']);
    Route::put('/admin/games/{id}/update', [AdminController::class, 'updateGame']);
    Route::delete('/admin/games/{id}', [AdminController::class, 'deleteGame']);
    
    Route::get('/admin/packages', [AdminController::class, 'packages']);
    Route::post('/admin/packages', [AdminController::class, 'createPackage']);
    Route::put('/admin/packages/{id}', [AdminController::class, 'updatePackage']);
    Route::delete('/admin/packages/{id}', [AdminController::class, 'deletePackage']);
    Route::get('/admin/price-audit-logs', [AdminController::class, 'priceAuditLogs']);
    
    Route::get('/admin/coupons', [AdminController::class, 'coupons']);
    Route::post('/admin/coupons', [AdminController::class, 'createCoupon']);
    Route::delete('/admin/coupons/{id}', [AdminController::class, 'deleteCoupon']);

    Route::get('/admin/banners', [AdminController::class, 'banners']);
    Route::post('/admin/banners', [AdminController::class, 'createBanner']);
    Route::put('/admin/banners/{id}', [AdminController::class, 'updateBanner']);
    Route::put('/admin/banners/{id}/toggle', [AdminController::class, 'toggleBanner']);
    Route::delete('/admin/banners/{id}', [AdminController::class, 'deleteBanner']);
    Route::post('/admin/upload', [AdminController::class, 'uploadImage']);
    Route::post('/admin/settings', [AdminController::class, 'updateSettings']);
    
    // G2Bulk API wholesaler additions & Provider Queue Management
    Route::get('/admin/api-logs', [AdminController::class, 'apiLogs']);
    Route::get('/admin/g2bulk-balance', [AdminController::class, 'walletBalance']);
    Route::get('/admin/provider-queue', [AdminController::class, 'providerQueue']);
    Route::post('/admin/g2bulk/sync', [AdminController::class, 'syncG2BulkCatalog']);
    Route::post('/admin/orders/{id}/retry', [AdminController::class, 'retryOrder']);
    Route::post('/admin/orders/retry-all', [AdminController::class, 'retryAllWaitingOrders']);
    Route::post('/admin/orders/{id}/refund', [AdminController::class, 'refundOrder']);
});
