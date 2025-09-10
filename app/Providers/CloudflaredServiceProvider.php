<?php

namespace App\Providers;

use App\Services\CloudflaredService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class CloudflaredServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudflaredService::class);
    }

    public function boot(): void
    {
        // Only auto-start in production or when explicitly configured
        if (config('app.env') === 'production' || config('services.cloudflared.auto_start', false)) {
            $this->startCloudflaredTunnel();
        }
    }

    private function startCloudflaredTunnel(): void
    {
        try {
            $service = $this->app->make(CloudflaredService::class);
            
            if (!$service->isRunning()) {
                Log::info('Auto-starting cloudflared tunnel for OAuth callbacks');
                $service->start();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to auto-start cloudflared tunnel', [
                'error' => $e->getMessage(),
                'note' => 'OAuth functionality may be limited'
            ]);
        }
    }
}
