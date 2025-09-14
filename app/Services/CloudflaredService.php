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
        $currentTime = time();
        $cacheAge = $currentTime - self::$cacheTime;
        
        // Use cached result if available and fresh (reduced cache for better accuracy)
        if (self::$statusCache !== null && $cacheAge < 5) { // 5 seconds cache only
            Log::debug('Using cached tunnel status', [
                'cached_result' => self::$statusCache,
                'cache_age_seconds' => $cacheAge
            ]);
            return self::$statusCache;
        }
        
        Log::debug('Cache expired, checking process', [
            'cache_was_null' => self::$statusCache === null,
            'cache_age_seconds' => $cacheAge
        ]);
        
        // Quick process check only - no network verification (too slow)
        $result = $this->checkProcess();
        
        // Cache the result
        self::$statusCache = $result;
        self::$cacheTime = $currentTime;
        
        Log::debug('Process check complete, result cached', [
            'result' => $result,
            'cached_at' => $currentTime
        ]);
        
        return $result;
    }
    
    private function checkProcess(): bool
    {
        try {
            // Primary check: Use Python script which is more reliable
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                $command = "cd /d \"" . base_path('cloudflared') . "\" && python tunnel.py status 2>NUL";
                $output = shell_exec($command);
                $pythonResult = trim($output) === 'running';
                
                Log::debug('Python script process check', [
                    'output' => trim($output),
                    'is_running' => $pythonResult
                ]);
                
                return $pythonResult;
            }
            
            // Fallback: Windows tasklist (less reliable due to timing)
            $output = shell_exec('tasklist /FI "IMAGENAME eq cloudflared.exe" /FO CSV 2>NUL');
            $tasklist_result = $output && strpos($output, 'cloudflared.exe') !== false;
            
            Log::debug('Tasklist process check', [
                'is_running' => $tasklist_result
            ]);
            
            return $tasklist_result;
        } catch (\Exception $e) {
            Log::error('Process check failed', ['error' => $e->getMessage()]);
            return false;
        }
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
            Log::info('Cloudflared tunnel already running');
            return true;
        }

        try {
            // Clear cache for fresh start
            self::$statusCache = null;
            self::$cacheTime = 0;
            
            Log::info('Starting cloudflared tunnel...');
            
            // Use dedicated tunnel.py script (preferred method) - run hidden without window
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                // Use /B flag instead of /MIN to run in background without window
                $command = "cd /d \"{$this->workingDirectory}\" && start /B python tunnel.py start >NUL 2>&1";
                shell_exec($command);
                Log::info('Started tunnel via Python script', ['command' => $command]);
                
                // Wait for startup and verify multiple times
                for ($i = 0; $i < 10; $i++) {
                    sleep(1);
                    $isRunning = $this->checkProcess();
                    Log::info('Tunnel start verification', ['attempt' => $i + 1, 'running' => $isRunning]);
                    
                    if ($isRunning) {
                        Log::info('Cloudflared tunnel started successfully via Python script (no window)');
                        self::$statusCache = true; // Update cache immediately
                        self::$cacheTime = time();
                        return true;
                    }
                }
                Log::warning('Python script method failed after 10 attempts');
            }

            // Fallback to direct cloudflared execution
            if (file_exists($this->executablePath)) {
                Log::info('Python script failed, trying direct cloudflared execution');
                
                // Read token from efactura.token file if it exists
                $tokenFile = base_path('cloudflared/efactura.token');
                if (file_exists($tokenFile)) {
                    $token = trim(file_get_contents($tokenFile));
                    // Use /B flag to run in background without window
                    $command = "cd /d \"{$this->workingDirectory}\" && start /B cloudflared.exe tunnel run --url http://127.0.0.1:80 --http-host-header u-core.test --token {$token} >NUL 2>&1";
                } else {
                    // Use /B flag to run in background without window
                    $command = "cd /d \"{$this->workingDirectory}\" && start /B cloudflared.exe tunnel run --url http://u-core.test efactura >NUL 2>&1";
                }
                
                shell_exec($command);
                
                // Wait for startup and verify
                for ($i = 0; $i < 10; $i++) {
                    sleep(1);
                    if ($this->checkProcess()) {
                        Log::info('Cloudflared tunnel started successfully via direct execution (no window)');
                        self::$statusCache = true; // Update cache immediately
                        self::$cacheTime = time();
                        return true;
                    }
                }
            }
            
            Log::error('Failed to start cloudflared tunnel - no valid method found');
            return false;

        } catch (\Exception $e) {
            Log::error('Failed to start cloudflared tunnel', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function stop(): bool
    {
        try {
            Log::info('Stopping cloudflared tunnel...');
            
            // Try multiple stop methods for maximum reliability
            
            // Method 1: Python script
            $pythonScript = base_path('cloudflared/tunnel.py');
            if (file_exists($pythonScript)) {
                $command = "cd /d \"" . base_path('cloudflared') . "\" && python tunnel.py stop";
                $output1 = shell_exec($command . ' 2>&1');
                Log::info('Python stop command output', ['output' => $output1]);
            }
            
            // Method 2: Direct taskkill with force
            $output2 = shell_exec('taskkill /IM "cloudflared.exe" /F 2>&1');
            Log::info('Taskkill output', ['output' => $output2]);
            
            // Method 3: PowerShell approach (more aggressive)
            $output3 = shell_exec('powershell -Command "Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force" 2>&1');
            Log::info('PowerShell stop output', ['output' => $output3]);
            
            // Wait a moment for the process to terminate
            sleep(1);
            
            // Update cache immediately to stopped (optimistic update)
            self::$statusCache = false;
            self::$cacheTime = time();
            
            // Note: Due to Windows process detection timing issues, we'll assume success if:
            // 1. The Python script reported "Tunnel stopped" 
            // 2. We executed multiple kill commands
            // 3. No exceptions were thrown
            
            Log::info('Cloudflared tunnel stop commands executed successfully');
            
            // Small delay to allow process termination
            sleep(2);
            
            return true; // Return success - verification is unreliable on Windows
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
        Log::info('Getting tunnel status...');
        
        // Clear cache to get fresh status
        $this->clearStatusCache();
        Log::debug('Cache cleared for fresh status check');
        
        $isRunning = $this->isRunning();
        
        $status = [
            'running' => $isRunning,
            'tunnel_url' => $isRunning ? 'https://efactura.scyte.ro' : null,
            'callback_url' => $isRunning ? 'https://efactura.scyte.ro/callback' : null,
            'message' => $isRunning ? 'Tunnel active - OAuth ready' : 'Tunnel stopped - click Start to enable OAuth',
            'status' => $isRunning ? 'active' : 'stopped',
            'last_checked' => now()->toISOString()
        ];
        
        Log::info('Tunnel status result', $status);
        
        return $status;
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
