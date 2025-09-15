<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule automatic e-Factura sync at 3:00 AM daily
Schedule::command('efactura:auto-sync --days=60')
    ->dailyAt('03:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping(7200) // Prevent overlapping for up to 2 hours
    ->runInBackground()
    ->emailOutputOnFailure('admin@youthfulzone.ro')
    ->appendOutputTo(storage_path('logs/efactura-auto-sync.log'));
