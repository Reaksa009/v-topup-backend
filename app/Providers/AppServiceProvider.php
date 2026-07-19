<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Auto-flush the frontend catalog cache whenever database records change
        $clearCache = function () {
            \Illuminate\Support\Facades\Cache::flush();
        };

        \App\Models\Game::saved($clearCache);
        \App\Models\Game::deleted($clearCache);
        
        \App\Models\Package::saved($clearCache);
        \App\Models\Package::deleted($clearCache);
        
        \App\Models\Setting::saved($clearCache);
        \App\Models\Setting::deleted($clearCache);
        
        \App\Models\Banner::saved($clearCache);
        \App\Models\Banner::deleted($clearCache);
    }
}
