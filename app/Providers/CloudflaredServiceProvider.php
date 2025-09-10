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
            $this->startCloudflaredTunnelAsync();
        }
    }

    private function startCloudflaredTunnelAsync(): void
    {
        // Start tunnel check asynchronously to avoid blocking boot
        register_shutdown_function(function () {
            try {
                $service = $this->app->make(CloudflaredService::class);
                
                if (!$service->isRunning()) {
                    Log::info('Auto-starting cloudflared tunnel for OAuth callbacks');
                    // Start without the blocking sleep operations
                    $this->startTunnelNonBlocking();
                }
            } catch (\Exception $e) {
                Log::warning('Failed to auto-start cloudflared tunnel', [
                    'error' => $e->getMessage(),
                    'note' => 'OAuth functionality may be limited'
                ]);
            }
        });
    }

    private function startTunnelNonBlocking(): void
    {
        $pythonScript = base_path('cloudflared/e.py');
        if (file_exists($pythonScript)) {
            $command = "cd /d \"" . base_path('cloudflared') . "\" && start /B python e.py";
            shell_exec($command . ' > NUL 2>&1 &');
        }
    }
}
