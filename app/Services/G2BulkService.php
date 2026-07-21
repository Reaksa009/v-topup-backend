<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ApiLog;

class G2BulkService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('G2BULK_API_KEY', '5fdcdd6b1a6d04645af01f89d21cd68a55b839ae8b36308f1ccab8f6cf982bfe');
        $this->baseUrl = 'https://api.g2bulk.com/v1';
    }

    /**
     * Log the API transaction to MongoDB.
     */
    protected function logTransaction(string $url, string $method, $payload, $response, int $statusCode, ?string $error = null)
    {
        try {
            ApiLog::create([
                'url' => $url,
                'method' => $method,
                'payload' => is_array($payload) ? $payload : (json_decode($payload, true) ?: ['raw' => $payload]),
                'response' => is_array($response) ? $response : (json_decode($response, true) ?: ['raw' => $response]),
                'status_code' => $statusCode,
                'error' => $error,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save API Log to MongoDB: ' . $e->getMessage());
        }
    }

    /**
     * Validate Player ID.
     */
    public function checkPlayerId(string $gameCode, string $playerId, ?string $serverId): array
    {
        $url = "{$this->baseUrl}/games/checkPlayerId";
        
        // G2Bulk Free Fire verification requires region code, freefire_sg covers global accounts
        if ($gameCode === 'freefire_global') {
            $gameCode = 'freefire_sg';
        }

        $payload = [
            'game' => $gameCode,
            'user_id' => $playerId,
            'server_id' => $serverId ?: '',
            'charname' => '',
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(8)->post($url, $payload);

            $resBody = $response->json();
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode);

            if ($response->successful() && isset($resBody['valid']) && $resBody['valid'] === 'valid') {
                return [
                    'success' => true,
                    'nickname' => $resBody['name'] ?? 'Verified Account'
                ];
            }

            return [
                'success' => false,
                'message' => $resBody['message'] ?? 'Invalid player details or game mapping.'
            ];
        } catch (\Exception $e) {
            Log::error("G2Bulk checkPlayerId exception: " . $e->getMessage());
            $this->logTransaction($url, 'POST', $payload, null, 500, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection to validation gateway timed out.'
            ];
        }
    }

    /**
     * Place Order.
     */
    public function placeOrder(string $gameCode, string $catalogueName, string $playerId, ?string $serverId, string $orderNo): array
    {
        // Resolve and normalize database package name (e.g. "86 Diamond") to G2Bulk catalog name (e.g. "86")
        try {
            $catUrl = "{$this->baseUrl}/games/{$gameCode}/catalogue";
            $catRes = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(6)->get($catUrl);

            if ($catRes->successful() && isset($catRes->json()['catalogues'])) {
                $dbName = strtolower(trim($catalogueName));
                foreach ($catRes->json()['catalogues'] as $cat) {
                    $catName = strtolower(trim($cat['name']));
                    $isMatch = false;

                    if ($catName === $dbName) {
                        $isMatch = true;
                    } elseif (str_contains($dbName, 'weekly pass') && str_contains($catName, 'weekly') && !str_contains($catName, 'elite')) {
                        $isMatch = true;
                    } elseif (str_contains($dbName, 'weekly elite') && str_contains($catName, 'weekly elite')) {
                        $isMatch = true;
                    } elseif (str_contains($dbName, 'monthly') && str_contains($catName, 'monthly')) {
                        $isMatch = true;
                    } else {
                        $dbNumOnly = preg_replace('/[^0-9]/', '', $dbName);
                        $catNumOnly = preg_replace('/[^0-9]/', '', $catName);
                        if (!empty($dbNumOnly) && !empty($catNumOnly) && $dbNumOnly === $catNumOnly) {
                            $isMatch = true;
                        }
                    }

                    if ($isMatch) {
                        $catalogueName = $cat['name']; // Set resolved name
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("G2Bulk catalogue resolution failed: " . $e->getMessage());
        }

        $url = "{$this->baseUrl}/games/{$gameCode}/order";
        $payload = [
            'catalogue_name' => $catalogueName,
            'player_id' => $playerId,
            'server_id' => $serverId ?: '',
            'charname' => '',
            'remark' => 'Order No: ' . $orderNo,
            'callback_url' => url('/api/webhooks/g2bulk'),
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(12)->post($url, $payload);

            $resBody = $response->json();
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'order_id' => $resBody['order']['order_id'] ?? null,
                    'message' => 'Order submitted to G2Bulk successfully.'
                ];
            }

            return [
                'success' => false,
                'message' => $resBody['message'] ?? 'Failed to submit order to G2Bulk wholesaler.'
            ];
        } catch (\Exception $e) {
            Log::error("G2Bulk placeOrder exception: " . $e->getMessage());
            $this->logTransaction($url, 'POST', $payload, null, 500, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection to wholesaler order placement timed out.'
            ];
        }
    }

    /**
     * Check Order Status.
     */
    public function checkOrderStatus(string $g2bOrderId): array
    {
        $url = "{$this->baseUrl}/games/order/status";
        $payload = [
            'order_id' => (int)$g2bOrderId
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(8)->post($url, $payload);

            $resBody = $response->json();
            $statusCode = $response->status();

            $this->logTransaction($url, 'POST', $payload, $resBody, $statusCode);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $resBody['status'] ?? 'processing',
                    'remark' => $resBody['remark'] ?? '',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to check order status.'
            ];
        } catch (\Exception $e) {
            Log::error("G2Bulk checkOrderStatus exception: " . $e->getMessage());
            $this->logTransaction($url, 'POST', $payload, null, 500, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection to status gateway timed out.'
            ];
        }
    }

    /**
     * Get Wallet Balance.
     */
    public function getWalletBalance(): array
    {
        $url = "{$this->baseUrl}/getMe";

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(8)->get($url);

            $resBody = $response->json();
            $statusCode = $response->status();

            // Log this action to MongoDB
            $this->logTransaction($url, 'GET', [], $resBody, $statusCode);

            if ($response->successful() && isset($resBody['success']) && $resBody['success']) {
                return [
                    'success' => true,
                    'balance' => (float)($resBody['balance'] ?? 0.0),
                    'currency' => 'USD',
                    'username' => $resBody['username'] ?? '',
                ];
            }

            // Fallback for mock if connection fails during development
            return [
                'success' => true,
                'balance' => 384.50, // Deterministic mock for balance
                'currency' => 'USD',
                'is_mocked' => true
            ];
        } catch (\Exception $e) {
            Log::error("G2Bulk getWalletBalance exception: " . $e->getMessage());
            $this->logTransaction($url, 'GET', [], null, 500, $e->getMessage());
            return [
                'success' => true,
                'balance' => 384.50,
                'currency' => 'USD',
                'is_mocked' => true
            ];
        }
    }
}
