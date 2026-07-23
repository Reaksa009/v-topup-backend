<?php

namespace App\Services;

use App\Models\StockNotification;
use App\Models\Package;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StockNotificationService
{
    /**
     * Subscribe customer to stock recovery alerts for a package.
     */
    public function subscribe(array $data): StockNotification
    {
        return StockNotification::create([
            'customer_id' => $data['customer_id'] ?? null,
            'email' => $data['email'] ?? null,
            'telegram_id' => $data['telegram_id'] ?? null,
            'package_id' => $data['package_id'],
            'game_id' => $data['game_id'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * Notify subscribers when a package recovers stock status to 'available'.
     */
    public function notifySubscribers(string $packageId): int
    {
        $package = Package::with('game')->find($packageId);
        if (!$package) {
            return 0;
        }

        $subscriptions = StockNotification::where('package_id', $packageId)
            ->where('status', 'pending')
            ->get();

        $count = 0;
        foreach ($subscriptions as $sub) {
            try {
                // Log notification event
                Log::info("Notifying customer {$sub->email} / Telegram {$sub->telegram_id} for package {$package->name_en} stock recovery.");

                $sub->status = 'notified';
                $sub->notified_at = now();
                $sub->save();
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to notify stock subscription ID {$sub->id}: " . $e->getMessage());
            }
        }

        return $count;
    }
}
