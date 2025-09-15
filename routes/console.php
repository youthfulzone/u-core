<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for auto-sync every minute (web-based scheduler)
Schedule::command('efactura:check-auto-sync')
    ->everyMinute()
    ->withoutOverlapping(7200) // Prevent overlapping for up to 2 hours
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/efactura-auto-sync.log'));
