<?php

namespace App\Console\Commands;

use App\Services\CloudflaredService;
use Illuminate\Console\Command;

class StartCloudflaredTunnel extends Command
{
    protected $signature = 'tunnel:start {--stop : Stop the tunnel instead}';
    protected $description = 'Start or stop the cloudflared tunnel for ANAF OAuth';

    public function handle(): int
    {
        $cloudflaredService = app(CloudflaredService::class);
        
        if ($this->option('stop')) {
            $this->info('Stopping cloudflared tunnel...');
            $result = $cloudflaredService->stop();
            
            if ($result) {
                $this->info('✅ Tunnel stopped successfully');
            } else {
                $this->error('❌ Failed to stop tunnel');
                return self::FAILURE;
            }
            
            return self::SUCCESS;
        }
        
        $this->info('Starting cloudflared tunnel...');
        
        // Check if already running
        if ($cloudflaredService->isRunning()) {
            $this->info('✅ Tunnel is already running');
            $status = $cloudflaredService->getStatus();
            if (isset($status['tunnel_url'])) {
                $this->info('Tunnel URL: ' . $status['tunnel_url']);
            }
            return self::SUCCESS;
        }
        
        // Start the tunnel
        $result = $cloudflaredService->start();
        
        if ($result) {
            $this->info('✅ Tunnel started successfully');
            $status = $cloudflaredService->getStatus();
            if (isset($status['tunnel_url'])) {
                $this->info('Tunnel URL: ' . $status['tunnel_url']);
                $this->info('Callback URL: ' . $status['callback_url']);
            }
        } else {
            $this->error('❌ Failed to start tunnel');
            $this->info('You can try:');
            $this->info('1. Check if cloudflared.exe exists in cloudflared/ directory');
            $this->info('2. Check if tunnel.py exists in cloudflared/ directory');
            $this->info('3. Run manually: cd cloudflared && python tunnel.py start');
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
}