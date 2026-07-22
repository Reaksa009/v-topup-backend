<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\ApiLog;

class G2BulkService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.g2bulk.api_key');
        $this->baseUrl = (string) config('services.g2bulk.base_url', 'https://api.g2bulk.com/v1');
    }

    /**
     * Log structured transaction to MongoDB api_logs collection.
     */
    protected function logTransaction(
        string $url,
        string $method,
        $payload,
        $response,
        int $statusCode,
        int $latencyMs,
        ?string $error = null,
        ?string $orderNo = null,
        ?string $playerId = null
    ): void {
        try {
            $requestId = request()->header('X-Request-ID') ?? (string) \Illuminate\Support\Str::uuid();

            ApiLog::create([
                'request_id' => $requestId,
                'order_no' => $orderNo,
                'player_id' => $playerId,
                'provider' => 'G2Bulk',
                'url' => $url,
                'method' => $method,
                'payload' => is_array($payload) ? $payload : (json_decode($payload, true) ?: ['raw' => $payload]),
                'response' => is_array($response) ? $response : (json_decode($response, true) ?: ['raw' => $response]),
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error' => $error,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write ApiLog to MongoDB: ' . $e->getMessage());
        }
    }

    /**
     * Verify Player ID.
     */
    public function checkPlayerId(string $gameCode, string $playerId, ?string $serverId): array
    {
        $url = "{$this->baseUrl}/games/checkPlayerId";

        if ($gameCode === 'freefire_global') {
            $gameCode = 'freefire_sg';
        }

        $payload = [
            'game' => $gameCode,
            'user_id' => $playerId,
            'server_id' => $serverId ?: '',
            'charname' => '',
        ];

        $startTime = microtime(true);

        try {
            $response = Http::retry(3, 1000)
                ->timeout(10)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($url, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $resBody = $response->json() ?? [];
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode, $latencyMs, null, null, $playerId);

            if ($response->successful() && isset($resBody['valid']) && $resBody['valid'] === 'valid') {
                return [
                    'success' => true,
                    'code' => 'SUCCESS',
                    'message' => 'Player details verified.',
                    'nickname' => $resBody['name'] ?? 'Verified Account',
                    'data' => $resBody,
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            $message = $resBody['message'] ?? 'Invalid player details or game mapping.';
            return [
                'success' => false,
                'code' => 'INVALID_PLAYER',
                'message' => $message,
                'data' => $resBody,
                'latency_ms' => $latencyMs,
                'http_status' => $statusCode,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 504, $latencyMs, $e->getMessage(), null, $playerId);

            return [
                'success' => false,
                'code' => 'TIMEOUT',
                'message' => 'Connection to player validation gateway timed out.',
                'data' => null,
                'latency_ms' => $latencyMs,
                'http_status' => 504,
            ];
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 500, $latencyMs, $e->getMessage(), null, $playerId);

            return [
                'success' => false,
                'code' => 'ERROR',
                'message' => 'Player validation error: ' . $e->getMessage(),
                'data' => null,
                'latency_ms' => $latencyMs,
                'http_status' => 500,
            ];
        }
    }

    /**
     * Submit Topup Order using explicit provider mapping fields.
     */
    public function placeOrder(string $gameCode, string $catalogueName, string $playerId, ?string $serverId, string $orderNo): array
    {
        // 0. Pre-Order Validation
        if (empty(trim($playerId))) {
            return [
                'success' => false,
                'code' => 'INVALID_PLAYER_ID',
                'message' => 'Player ID is required.',
            ];
        }

        // 0.1 Circuit Breaker: Block requests when G2Bulk wallet balance is $0.00
        $walletRes = $this->getWalletBalance();
        if (isset($walletRes['balance']) && (float)$walletRes['balance'] <= 0.0) {
            Log::warning("Circuit breaker activated: G2Bulk wallet balance is $0.00. Order {$orderNo} submission blocked.");
            return [
                'success' => false,
                'code' => 'PROVIDER_UNAVAILABLE',
                'message' => 'Top-up service is temporarily unavailable due to provider maintenance. Please try again later.',
                'status_code' => 503,
            ];
        }

        // 1. Normalize provider game code mapping
        $gameMapping = [
            'mobile-legends' => 'mlbb',
            'mobile-khmer' => 'mlbb',
            'free-fire' => 'freefire_global',
            'pubg-mobile' => 'pubgm',
            'valorant' => 'valorant_sg',
            'honor-of-kings' => 'hok',
            'roblox' => 'roblox',
        ];
        $finalGameCode = $gameMapping[strtolower(trim($gameCode))] ?? strtolower(trim($gameCode));

        // 2. Determine catalogue name (use direct provider_catalogue_name if matching G2Bulk format)
        $finalCatalogueName = trim($catalogueName);

        // If catalogueName contains display suffixes (e.g. "86 Diamonds" or "Weekly pass x1"), sanitize/resolve it
        $isDirectCode = preg_match('/^\d+$|^Weekly$|^Twilight$|^Weekly Elite Pack$|^Monthly Elite Pack$/i', $finalCatalogueName);
        if (!$isDirectCode) {
            $lowerName = strtolower($finalCatalogueName);
            if ((str_contains($lowerName, 'weekly pass') || str_contains($lowerName, 'weekly')) && !str_contains($lowerName, 'elite')) {
                $finalCatalogueName = 'Weekly';
            } elseif (str_contains($lowerName, 'weekly elite')) {
                $finalCatalogueName = 'Weekly Elite Pack';
            } elseif (str_contains($lowerName, 'twilight')) {
                $finalCatalogueName = 'Twilight';
            } elseif (str_contains($lowerName, 'monthly')) {
                $finalCatalogueName = 'Monthly Elite Pack';
            } else {
                $numOnly = preg_replace('/[^0-9]/', '', $lowerName);
                if (!empty($numOnly)) {
                    $finalCatalogueName = $numOnly; // e.g. "86 Diamonds" -> "86"
                }
            }
        }

        // 3. Submit order to G2Bulk
        $url = "{$this->baseUrl}/games/{$finalGameCode}/order";
        $payload = [
            'catalogue_name' => $finalCatalogueName,
            'player_id' => trim($playerId),
            'server_id' => $serverId ? trim($serverId) : '',
            'charname' => '',
            'remark' => 'Order No: ' . $orderNo,
            'callback_url' => url('/api/v1/webhooks/g2bulk'),
        ];

        $startTime = microtime(true);

        try {
            $response = Http::retry(2, 800)
                ->timeout(12)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($url, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $resBody = $response->json() ?? [];
            $statusCode = $response->status();

            // Log transaction silently to MongoDB api_logs collection
            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode, $latencyMs, null, $orderNo, $playerId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'code' => 'SUCCESS',
                    'message' => 'Order submitted to G2Bulk successfully.',
                    'data' => [
                        'order_id' => $resBody['order_id'] ?? $resBody['id'] ?? null,
                        'status' => $resBody['status'] ?? 'PROCESSING',
                        'raw_response' => $resBody,
                    ]
                ];
            }

            // Structured G2Bulk error handling
            $errorMsg = $resBody['message'] ?? 'Provider error occurred.';
            $errorCode = 'PROVIDER_ERROR';

            $lowerMsg = strtolower($errorMsg);
            if (str_contains($lowerMsg, 'balance') || str_contains($lowerMsg, 'insufficient')) {
                $errorCode = 'INSUFFICIENT_BALANCE';
            } elseif ($statusCode === 404) {
                $errorCode = 'CATALOGUE_NOT_FOUND';
            } elseif ($statusCode === 400 && (str_contains($lowerMsg, 'player') || str_contains($lowerMsg, 'user'))) {
                $errorCode = 'INVALID_PLAYER_ID';
            } elseif ($statusCode === 401) {
                $errorCode = 'UNAUTHORIZED';
            }

            return [
                'success' => false,
                'code' => $errorCode,
                'message' => "G2Bulk Error: {$errorMsg}",
                'status_code' => $statusCode,
                'data' => $resBody
            ];

        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 500, $latencyMs, $e->getMessage(), $orderNo, $playerId);

            return [
                'success' => false,
                'code' => 'NETWORK_ERROR',
                'message' => 'Provider timeout or network connectivity issue: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Order Status on G2Bulk.
     */
    public function checkOrderStatus(string $g2bOrderId): array
    {
        $url = "{$this->baseUrl}/games/order/status";
        $payload = [
            'order_id' => (int) $g2bOrderId,
        ];

        $startTime = microtime(true);

        try {
            $response = Http::retry(3, 1000)
                ->timeout(8)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($url, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $resBody = $response->json() ?? [];
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode, $latencyMs);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'code' => 'SUCCESS',
                    'status' => $resBody['status'] ?? 'processing',
                    'remark' => $resBody['remark'] ?? '',
                    'data' => $resBody,
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            return [
                'success' => false,
                'code' => 'ERROR',
                'message' => $resBody['message'] ?? 'Failed to check order status.',
                'data' => $resBody,
                'latency_ms' => $latencyMs,
                'http_status' => $statusCode,
            ];
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 500, $latencyMs, $e->getMessage());

            return [
                'success' => false,
                'code' => 'ERROR',
                'message' => 'Status check exception: ' . $e->getMessage(),
                'data' => null,
                'latency_ms' => $latencyMs,
                'http_status' => 500,
            ];
        }
    }

    /**
     * Get Wallet Balance with Redis 5-min caching and MongoDB trend tracking.
     */
    public function getWalletBalance(bool $forceRefresh = false): array
    {
        $cacheKey = 'g2bulk_wallet_balance';
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 300, function () {
            $url = "{$this->baseUrl}/getMe";
            $startTime = microtime(true);

            try {
                $response = Http::retry(2, 500)
                    ->timeout(8)
                    ->withHeaders([
                        'X-API-Key' => $this->apiKey,
                        'Accept' => 'application/json',
                    ])->get($url);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
                $resBody = $response->json() ?? [];
                $statusCode = $response->status();

                $this->logTransaction($url, 'GET', [], $resBody, $statusCode, $latencyMs);

                if ($response->successful() && isset($resBody['success']) && $resBody['success']) {
                    $balance = (float) ($resBody['balance'] ?? 0.0);
                    $status = $balance <= 0 ? 'CRITICAL' : ($balance < (float) config('services.g2bulk.low_balance_threshold', 20.0) ? 'LOW' : 'OK');

                    // Save balance snapshot history to MongoDB for trend reporting
                    try {
                        \App\Models\WalletBalance::create([
                            'provider' => 'g2bulk',
                            'balance' => $balance,
                            'currency' => 'USD',
                            'status' => $status,
                            'raw_response' => [
                                'user_id' => $resBody['user_id'] ?? null,
                                'username' => $resBody['username'] ?? '',
                            ],
                        ]);
                    } catch (\Exception $e) {
                        Log::warning("Failed to save WalletBalance history: " . $e->getMessage());
                    }

                    return [
                        'success' => true,
                        'code' => 'SUCCESS',
                        'balance' => $balance,
                        'currency' => 'USD',
                        'status' => $status,
                        'username' => $resBody['username'] ?? '',
                        'latency_ms' => $latencyMs,
                    ];
                }

                return [
                    'success' => false,
                    'code' => 'PROVIDER_ERROR',
                    'balance' => 0.0,
                    'currency' => 'USD',
                    'status' => 'CRITICAL',
                    'latency_ms' => $latencyMs,
                ];
            } catch (\Exception $e) {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->logTransaction($url, 'GET', [], null, 500, $latencyMs, $e->getMessage());

                return [
                    'success' => false,
                    'code' => 'NETWORK_ERROR',
                    'balance' => 0.0,
                    'currency' => 'USD',
                    'status' => 'CRITICAL',
                    'latency_ms' => $latencyMs,
                ];
            }
        });
    }
}
