<?php

namespace App\Console\Commands;

use App\Services\CloudflaredService;
use Illuminate\Console\Command;

class CloudflaredTunnelCommand extends Command
{
    protected $signature = 'cloudflared:tunnel {action : start|stop|restart|status}';

    protected $description = 'Manage cloudflared tunnel for e-Factura OAuth callbacks';

    public function handle(CloudflaredService $service): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'start' => $this->startTunnel($service),
            'stop' => $this->stopTunnel($service),
            'restart' => $this->restartTunnel($service),
            'status' => $this->showStatus($service),
            default => $this->handleInvalidAction()
        };
    }

    private function startTunnel(CloudflaredService $service): int
    {
        $this->info('Starting cloudflared tunnel...');
        
        if ($service->start()) {
            $this->info('✅ Cloudflared tunnel started successfully');
            return Command::SUCCESS;
        }
        
        $this->error('❌ Failed to start cloudflared tunnel');
        return Command::FAILURE;
    }

    private function stopTunnel(CloudflaredService $service): int
    {
        $this->info('Stopping cloudflared tunnel...');
        
        if ($service->stop()) {
            $this->info('✅ Cloudflared tunnel stopped successfully');
            return Command::SUCCESS;
        }
        
        $this->error('❌ Failed to stop cloudflared tunnel');
        return Command::FAILURE;
    }

    private function restartTunnel(CloudflaredService $service): int
    {
        $this->info('Restarting cloudflared tunnel...');
        
        if ($service->restart()) {
            $this->info('✅ Cloudflared tunnel restarted successfully');
            return Command::SUCCESS;
        }
        
        $this->error('❌ Failed to restart cloudflared tunnel');
        return Command::FAILURE;
    }

    private function showStatus(CloudflaredService $service): int
    {
        $status = $service->getStatus();
        
        $this->info('Cloudflared Tunnel Status:');
        $this->line("Running: " . ($status['running'] ? '✅ Yes' : '❌ No'));
        $this->line("Message: " . $status['message']);
        
        if ($status['tunnel_url']) {
            $this->line("Tunnel URL: " . $status['tunnel_url']);
        }
        
        if ($status['callback_url']) {
            $this->line("Callback URL: " . $status['callback_url']);
        }
        
        return Command::SUCCESS;
    }

    private function handleInvalidAction(): int
    {
        $this->error('Invalid action. Use: start, stop, restart, or status');
        return Command::FAILURE;
    }
}
