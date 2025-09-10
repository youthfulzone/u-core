<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class CloudflaredService
{
    private string $executablePath;
    private string $workingDirectory;

    public function __construct()
    {
        $this->executablePath = base_path('cloudflared/cloudflared.exe');
        $this->workingDirectory = base_path('cloudflared');
    }

    public function isRunning(): bool
    {
        $output = shell_exec('tasklist /FI "IMAGENAME eq cloudflared.exe" 2>NUL');
        return $output && strpos($output, 'cloudflared.exe') !== false;
    }

    public function start(): bool
    {
        if ($this->isRunning()) {
            return true;
        }

        if (!file_exists($this->executablePath)) {
            Log::error('Cloudflared executable not found', ['path' => $this->executablePath]);
            return false;
        }

        try {
            // Start cloudflared tunnel in background using Python script
            $pythonScript = base_path('cloudflared/e.py');
            if (file_exists($pythonScript)) {
                $command = "cd /d \"{$this->workingDirectory}\" && start /B python e.py";
                shell_exec($command);
                
                // Wait a moment and check if it started
                sleep(2);
                return $this->isRunning();
            }

            // Fallback to direct cloudflared execution
            $command = "cd /d \"{$this->workingDirectory}\" && start /B cloudflared.exe tunnel run --url http://u-core.test efactura";
            shell_exec($command);
            
            sleep(2);
            return $this->isRunning();

        } catch (\Exception $e) {
            Log::error('Failed to start cloudflared tunnel', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function stop(): bool
    {
        try {
            shell_exec('taskkill /IM "cloudflared.exe" /F 2>NUL');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to stop cloudflared tunnel', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function restart(): bool
    {
        $this->stop();
        sleep(1);
        return $this->start();
    }

    public function getStatus(): array
    {
        $isRunning = $this->isRunning();
        
        return [
            'running' => $isRunning,
            'tunnel_url' => $isRunning ? 'https://efactura.scyte.ro' : null,
            'callback_url' => $isRunning ? 'https://efactura.scyte.ro/efactura/oauth/callback' : null,
            'message' => $isRunning ? 'Tunnel active - OAuth ready' : 'Starting tunnel...',
            'required' => false, // Laravel manages this automatically
            'setup_command' => null
        ];
    }

    public function ensureRunning(): bool
    {
        if (!$this->isRunning()) {
            Log::info('Cloudflared tunnel not running, starting automatically');
            return $this->start();
        }
        
        return true;
    }
}
