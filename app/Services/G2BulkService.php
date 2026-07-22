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
     * Submit Topup Order.
     */
    public function placeOrder(string $gameCode, string $catalogueName, string $playerId, ?string $serverId, string $orderNo): array
    {
        // 0. Normalize game code mapping
        $gameMapping = [
            'mobile-legends' => 'mlbb',
            'mobile-khmer' => 'mlbb',
            'free-fire' => 'freefire_global',
            'pubg-mobile' => 'pubgm',
            'valorant' => 'valorant_sg',
            'honor-of-kings' => 'hok',
            'roblox' => 'roblox',
        ];
        $gameCode = $gameMapping[strtolower(trim($gameCode))] ?? strtolower(trim($gameCode));

        // 1. Resolve catalog name
        $isResolved = false;
        try {
            $catUrl = "{$this->baseUrl}/games/{$gameCode}/catalogue";
            $catRes = Http::retry(2, 500)
                ->timeout(8)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])->get($catUrl);

            if ($catRes->successful() && isset($catRes->json()['catalogues'])) {
                $dbName = strtolower(trim($catalogueName));
                foreach ($catRes->json()['catalogues'] as $cat) {
                    $catName = strtolower(trim($cat['name']));
                    $isMatch = false;

                    if ($catName === $dbName) {
                        $isMatch = true;
                    } elseif ((str_contains($dbName, 'weekly pass') || str_contains($dbName, 'weekly')) && !str_contains($dbName, 'elite')) {
                        if ($catName === 'weekly' || (str_contains($catName, 'weekly') && !str_contains($catName, 'elite'))) {
                            $isMatch = true;
                        }
                    } elseif (str_contains($dbName, 'weekly elite') && str_contains($catName, 'weekly elite')) {
                        $isMatch = true;
                    } elseif (str_contains($dbName, 'twilight') && str_contains($catName, 'twilight')) {
                        $isMatch = true;
                    } elseif (str_contains($dbName, 'monthly') && str_contains($catName, 'monthly')) {
                        $isMatch = true;
                    } else {
                        // Extract leading numbers (e.g. "86 Diamonds" -> "86", "250 Diamonds" -> "250")
                        $dbNumOnly = preg_replace('/[^0-9]/', '', $dbName);
                        $catNumOnly = preg_replace('/[^0-9]/', '', $catName);
                        if (!empty($dbNumOnly) && !empty($catNumOnly) && $dbNumOnly === $catNumOnly) {
                            $isMatch = true;
                        }
                    }

                    if ($isMatch) {
                        $catalogueName = $cat['name'];
                        $isResolved = true;
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("G2Bulk catalogue resolution error for {$orderNo}: " . $e->getMessage());
        }

        // Fallback string sanitization if catalogue resolution was not completed
        if (!$isResolved) {
            $lowerName = strtolower(trim($catalogueName));
            if ((str_contains($lowerName, 'weekly pass') || str_contains($lowerName, 'weekly')) && !str_contains($lowerName, 'elite')) {
                $catalogueName = 'Weekly';
            } elseif (str_contains($lowerName, 'weekly elite')) {
                $catalogueName = 'Weekly Elite Pack';
            } elseif (str_contains($lowerName, 'twilight')) {
                $catalogueName = 'Twilight';
            } elseif (str_contains($lowerName, 'monthly')) {
                $catalogueName = 'Monthly Elite Pack';
            } else {
                $numOnly = preg_replace('/[^0-9]/', '', $lowerName);
                if (!empty($numOnly)) {
                    $catalogueName = $numOnly; // e.g. "86 Diamonds" -> "86"
                }
            }
        }

        // 2. Submit order to G2Bulk
        $url = "{$this->baseUrl}/games/{$gameCode}/order";
        $payload = [
            'catalogue_name' => $catalogueName,
            'player_id' => $playerId,
            'server_id' => $serverId ?: '',
            'charname' => '',
            'remark' => 'Order No: ' . $orderNo,
            'callback_url' => url('/api/v1/webhooks/g2bulk'),
        ];

        $startTime = microtime(true);

        try {
            $response = Http::retry(3, 1000)
                ->timeout(12)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($url, $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $resBody = $response->json() ?? [];
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode, $latencyMs, null, $orderNo, $playerId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'code' => 'SUCCESS',
                    'message' => 'Order submitted to G2Bulk successfully.',
                    'data' => [
                        'order_id' => $resBody['order']['order_id'] ?? null,
                    ],
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            $rawMsg = strtolower($resBody['message'] ?? '');

            // Out of stock detection
            if (str_contains($rawMsg, 'stock') || str_contains($rawMsg, 'insufficient') || str_contains($rawMsg, 'unavailable')) {
                return [
                    'success' => false,
                    'code' => 'OUT_OF_STOCK',
                    'message' => $resBody['message'] ?? 'Wholesaler package is currently out of stock.',
                    'data' => $resBody,
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            // Invalid player detection
            if (str_contains($rawMsg, 'invalid') || str_contains($rawMsg, 'user not found') || str_contains($rawMsg, 'character')) {
                return [
                    'success' => false,
                    'code' => 'INVALID_PLAYER',
                    'message' => $resBody['message'] ?? 'Invalid player ID or server ID.',
                    'data' => $resBody,
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            // Duplicate order detection
            if (str_contains($rawMsg, 'duplicate') || str_contains($rawMsg, 'already exists')) {
                return [
                    'success' => false,
                    'code' => 'DUPLICATE_ORDER',
                    'message' => $resBody['message'] ?? 'Duplicate order submission detected.',
                    'data' => $resBody,
                    'latency_ms' => $latencyMs,
                    'http_status' => $statusCode,
                ];
            }

            return [
                'success' => false,
                'code' => 'ERROR',
                'message' => $resBody['message'] ?? 'Failed to submit order to G2Bulk wholesaler.',
                'data' => $resBody,
                'latency_ms' => $latencyMs,
                'http_status' => $statusCode,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 504, $latencyMs, $e->getMessage(), $orderNo, $playerId);

            return [
                'success' => false,
                'code' => 'TIMEOUT',
                'message' => 'Connection to G2Bulk order gateway timed out.',
                'data' => null,
                'latency_ms' => $latencyMs,
                'http_status' => 504,
            ];
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logTransaction($url, 'POST', $payload, null, 500, $latencyMs, $e->getMessage(), $orderNo, $playerId);

            return [
                'success' => false,
                'code' => 'ERROR',
                'message' => 'Order placement exception: ' . $e->getMessage(),
                'data' => null,
                'latency_ms' => $latencyMs,
                'http_status' => 500,
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
     * Get Wallet Balance (with Redis Caching).
     */
    public function getWalletBalance(): array
    {
        return Cache::remember('g2bulk_wallet_balance', 300, function () {
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
                    return [
                        'success' => true,
                        'code' => 'SUCCESS',
                        'balance' => (float) ($resBody['balance'] ?? 0.0),
                        'currency' => 'USD',
                        'username' => $resBody['username'] ?? '',
                        'latency_ms' => $latencyMs,
                    ];
                }

                return [
                    'success' => true,
                    'code' => 'MOCK',
                    'balance' => 384.50,
                    'currency' => 'USD',
                    'is_mocked' => true,
                    'latency_ms' => $latencyMs,
                ];
            } catch (\Exception $e) {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->logTransaction($url, 'GET', [], null, 500, $latencyMs, $e->getMessage());

                return [
                    'success' => true,
                    'code' => 'MOCK',
                    'balance' => 384.50,
                    'currency' => 'USD',
                    'is_mocked' => true,
                    'latency_ms' => $latencyMs,
                ];
            }
        });
    }
}
