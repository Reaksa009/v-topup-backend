<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule 5-minute G2Bulk Wholesaler Wallet Balance Monitoring & Circuit Breaker Check
Schedule::command('g2bulk:check-balance')->everyFiveMinutes();
