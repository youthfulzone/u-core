<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class CloudflaredService
{
    private string $executablePath;
    private string $workingDirectory;
    private static $statusCache = null;
    private static $cacheTime = 0;
    private const CACHE_DURATION = 30; // Cache for 30 seconds

    public function __construct()
    {
        $this->executablePath = base_path('cloudflared/cloudflared.exe');
        $this->workingDirectory = base_path('cloudflared');
    }

    public function isRunning(): bool
    {
        // Use cached result if available and fresh
        if (self::$statusCache !== null && (time() - self::$cacheTime) < self::CACHE_DURATION) {
            return self::$statusCache;
        }
        
        // Clear cache for fresh check
        self::$statusCache = null;
        
        // Multiple check approaches for better reliability
        
        // 1. Check if process is running
        $processRunning = $this->checkProcess();
        
        // 2. If process is running, verify tunnel is actually accessible
        $result = false;
        if ($processRunning) {
            $result = $this->verifyTunnelAccess();
        }
        
        // Cache the result
        self::$statusCache = $result;
        self::$cacheTime = time();
        
        return $result;
    }
    
    private function checkProcess(): bool
    {
        try {
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                $output = shell_exec("cd /d \"" . base_path('cloudflared') . "\" && python tunnel.py status 2>NUL");
                return trim($output) === 'running';
            }
        } catch (\Exception $e) {
            // Fallback to process check
        }
        
        $output = shell_exec('tasklist /FI "IMAGENAME eq cloudflared.exe" /FO CSV 2>NUL');
        return $output && strpos($output, 'cloudflared.exe') !== false;
    }
    
    private function verifyTunnelAccess(): bool
    {
        // Quick check if tunnel URL is accessible (301, 404 are acceptable - means tunnel is working)
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'HEAD',
                'follow_location' => false  // Don't follow redirects, just check if tunnel responds
            ]
        ]);
        
        try {
            $headers = @get_headers('https://efactura.scyte.ro', false, $context);
            if ($headers) {
                // 200, 301, 404, or any HTTP response means tunnel is working
                // 502/503 means tunnel is not working properly
                $statusCode = (int) substr($headers[0], 9, 3);
                return $statusCode < 500;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function start(): bool
    {
        if ($this->isRunning()) {
            return true;
        }

        try {
            // Use dedicated tunnel.py script
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                $command = "cd /d \"{$this->workingDirectory}\" && start /B python tunnel.py start";
                shell_exec($command);
                
                // Wait a moment and check if it started
                sleep(3);
                return $this->isRunning();
            }

            // Fallback to direct cloudflared execution if tunnel.py doesn't exist
            if (!file_exists($this->executablePath)) {
                Log::error('Cloudflared executable not found', ['path' => $this->executablePath]);
                return false;
            }

            $command = "cd /d \"{$this->workingDirectory}\" && start /B cloudflared.exe tunnel run --url http://u-core.test efactura";
            shell_exec($command);
            
            sleep(3);
            return $this->isRunning();

        } catch (\Exception $e) {
            Log::error('Failed to start cloudflared tunnel', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function stop(): bool
    {
        try {
            // Use dedicated tunnel.py script for stopping
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                shell_exec("cd /d \"" . base_path('cloudflared') . "\" && python tunnel.py stop 2>NUL");
            } else {
                // Fallback to direct process kill
                shell_exec('taskkill /IM "cloudflared.exe" /F 2>NUL');
            }
            
            // Clear cache
            self::$statusCache = null;
            self::$cacheTime = 0;
            
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
            'callback_url' => $isRunning ? 'https://efactura.scyte.ro/callback' : null,
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

    public function clearStatusCache(): void
    {
        self::$statusCache = null;
        self::$cacheTime = 0;
    }
}
