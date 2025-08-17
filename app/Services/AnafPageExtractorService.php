<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;

class AnafPageExtractorService
{
    /**
     * Automatically extract content from ANAF page using browser automation
     */
    public function extractAnafPageContent(string $url): array
    {
        Log::info('Starting automatic ANAF page extraction', ['url' => $url]);

        // Try multiple extraction methods in order of preference
        $methods = [
            'headlessBrowser',
            'powerShellBrowser', 
            'curlWithSSL',
            'httpClientWithRetry'
        ];

        foreach ($methods as $method) {
            try {
                Log::info("Trying extraction method: {$method}");
                $result = $this->$method($url);
                
                if ($result && isset($result['success']) && $result['success']) {
                    Log::info("Successfully extracted content using method: {$method}");
                    return $result;
                }
            } catch (\Exception $e) {
                Log::warning("Method {$method} failed", ['error' => $e->getMessage()]);
                continue;
            }
        }

        throw new \Exception('All extraction methods failed');
    }

    /**
     * Method 1: Headless browser automation (Chrome/Edge)
     */
    private function headlessBrowser(string $url): array
    {
        // Create a PowerShell script that uses Edge WebView2 for automation
        $psScript = $this->createHeadlessBrowserScript($url);
        $scriptPath = storage_path('app/temp/anaf_extractor.ps1');
        
        // Ensure temp directory exists
        $tempDir = dirname($scriptPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        file_put_contents($scriptPath, $psScript);
        
        // Execute PowerShell script with execution policy bypass
        $command = "powershell.exe -ExecutionPolicy Bypass -File \"{$scriptPath}\"";
        $result = Process::timeout(60)->run($command);
        
        // Clean up
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }
        
        if (!$result->successful()) {
            throw new \Exception('Headless browser failed: ' . $result->errorOutput());
        }
        
        $output = $result->output();
        return $this->parseExtractorOutput($output);
    }

    /**
     * Method 2: PowerShell with Internet Explorer automation
     */
    private function powerShellBrowser(string $url): array
    {
        $psScript = $this->createIEAutomationScript($url);
        $scriptPath = storage_path('app/temp/anaf_ie_extractor.ps1');
        
        file_put_contents($scriptPath, $psScript);
        
        $command = "powershell.exe -ExecutionPolicy Bypass -File \"{$scriptPath}\"";
        $result = Process::timeout(90)->run($command);
        
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }
        
        if (!$result->successful()) {
            throw new \Exception('PowerShell IE automation failed: ' . $result->errorOutput());
        }
        
        $output = $result->output();
        return $this->parseExtractorOutput($output);
    }

    /**
     * Method 3: Advanced cURL with certificate handling
     */
    private function curlWithSSL(string $url): array
    {
        // Create a cURL command that mimics browser behavior exactly
        $curlCommand = $this->createAdvancedCurlCommand($url);
        
        $result = Process::timeout(30)->run($curlCommand);
        
        if (!$result->successful()) {
            throw new \Exception('Advanced cURL failed: ' . $result->errorOutput());
        }
        
        $output = $result->output();
        return $this->parseHttpResponse($output);
    }

    /**
     * Method 4: HTTP client with retry and certificate handling
     */
    private function httpClientWithRetry(string $url): array
    {
        $maxRetries = 3;
        $delay = 2;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 30,
                    'allow_redirects' => true,
                    'http_errors' => false,
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'application/json, text/html, */*',
                    'Accept-Language' => 'ro-RO,ro;q=0.9,en;q=0.8',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Cache-Control' => 'no-cache',
                ])
                ->get($url);
                
                return $this->parseHttpResponse($response->body());
                
            } catch (\Exception $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                sleep($delay);
                $delay *= 2;
            }
        }
        
        throw new \Exception('HTTP client retry failed');
    }

    /**
     * Create headless browser automation script using Edge WebView2
     */
    private function createHeadlessBrowserScript(string $url): string
    {
        return <<<POWERSHELL
# Advanced ANAF Page Extractor using Edge WebView2
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Web

try {
    Write-Output "EXTRACTOR_START: Starting Edge WebView2 automation"
    
    # Create a hidden form to host WebView2
    \$form = New-Object System.Windows.Forms.Form
    \$form.WindowState = [System.Windows.Forms.FormWindowState]::Minimized
    \$form.ShowInTaskbar = \$false
    \$form.Visible = \$false
    
    # Try to create WebView2 control (requires Edge WebView2 runtime)
    try {
        \$webView = New-Object -ComObject "Shell.Application"
        \$ie = \$webView.Windows() | Where-Object { \$_.Name -eq "Internet Explorer" } | Select-Object -First 1
        
        if (-not \$ie) {
            \$ie = New-Object -ComObject "InternetExplorer.Application"
            \$ie.Visible = \$false
            \$ie.Silent = \$true
        }
        
        Write-Output "EXTRACTOR_LOG: Navigating to ANAF URL"
        \$ie.Navigate("{$url}")
        
        # Wait for page to load
        while (\$ie.Busy -or \$ie.ReadyState -ne 4) {
            Start-Sleep -Milliseconds 500
        }
        
        # Additional wait for JavaScript execution
        Start-Sleep -Seconds 3
        
        Write-Output "EXTRACTOR_LOG: Page loaded, extracting content"
        \$document = \$ie.Document
        \$content = \$document.body.innerText
        
        # Look for JSON content
        if (\$content -match '\\{.*"mesaje".*\\}') {
            Write-Output "EXTRACTOR_SUCCESS: Found JSON content"
            Write-Output "EXTRACTOR_DATA_START:"
            Write-Output \$content
            Write-Output "EXTRACTOR_DATA_END:"
        } else {
            Write-Output "EXTRACTOR_ERROR: No JSON content found"
            Write-Output "EXTRACTOR_HTML_START:"
            Write-Output \$document.body.innerHTML
            Write-Output "EXTRACTOR_HTML_END:"
        }
        
        \$ie.Quit()
        
    } catch {
        Write-Output "EXTRACTOR_ERROR: WebView2/IE automation failed: \$(\$_.Exception.Message)"
        
        # Fallback: Try with System.Net.WebClient
        \$webClient = New-Object System.Net.WebClient
        \$webClient.Headers.Add("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
        \$webClient.Headers.Add("Accept", "application/json, text/html, */*")
        
        try {
            \$content = \$webClient.DownloadString("{$url}")
            Write-Output "EXTRACTOR_SUCCESS: Retrieved content via WebClient"
            Write-Output "EXTRACTOR_DATA_START:"
            Write-Output \$content
            Write-Output "EXTRACTOR_DATA_END:"
        } catch {
            Write-Output "EXTRACTOR_ERROR: WebClient also failed: \$(\$_.Exception.Message)"
        }
    }
    
} catch {
    Write-Output "EXTRACTOR_ERROR: Script execution failed: \$(\$_.Exception.Message)"
}

Write-Output "EXTRACTOR_END: Automation complete"
POWERSHELL;
    }

    /**
     * Create IE automation script with certificate handling
     */
    private function createIEAutomationScript(string $url): string
    {
        return <<<POWERSHELL
# ANAF Page Extractor using Internet Explorer with Certificate Support
try {
    Write-Output "IE_EXTRACTOR_START: Starting Internet Explorer automation"
    
    \$ie = New-Object -ComObject "InternetExplorer.Application"
    \$ie.Visible = \$false
    \$ie.Silent = \$true
    
    Write-Output "IE_EXTRACTOR_LOG: Navigating to ANAF URL"
    \$ie.Navigate("{$url}")
    
    # Wait for page to load (longer timeout for certificate authentication)
    \$timeout = 60
    \$elapsed = 0
    
    while ((\$ie.Busy -or \$ie.ReadyState -ne 4) -and \$elapsed -lt \$timeout) {
        Start-Sleep -Seconds 1
        \$elapsed++
    }
    
    if (\$elapsed -ge \$timeout) {
        Write-Output "IE_EXTRACTOR_ERROR: Page load timeout"
        \$ie.Quit()
        exit 1
    }
    
    # Additional wait for certificate dialogs and JavaScript
    Start-Sleep -Seconds 5
    
    Write-Output "IE_EXTRACTOR_LOG: Page loaded, extracting content"
    \$document = \$ie.Document
    
    if (\$document.body) {
        \$content = \$document.body.innerText
        \$html = \$document.body.innerHTML
        
        # Check for JSON response
        if (\$content -match '\\{.*"mesaje".*\\}') {
            Write-Output "IE_EXTRACTOR_SUCCESS: Found JSON content"
            Write-Output "IE_EXTRACTOR_DATA_START:"
            Write-Output \$content
            Write-Output "IE_EXTRACTOR_DATA_END:"
        } elseif (\$html -match '\\{.*"mesaje".*\\}') {
            Write-Output "IE_EXTRACTOR_SUCCESS: Found JSON in HTML"
            Write-Output "IE_EXTRACTOR_DATA_START:"
            Write-Output \$html
            Write-Output "IE_EXTRACTOR_DATA_END:"
        } else {
            Write-Output "IE_EXTRACTOR_INFO: No JSON found, returning HTML content"
            Write-Output "IE_EXTRACTOR_HTML_START:"
            Write-Output \$html
            Write-Output "IE_EXTRACTOR_HTML_END:"
        }
    } else {
        Write-Output "IE_EXTRACTOR_ERROR: No document body found"
    }
    
    \$ie.Quit()
    
} catch {
    Write-Output "IE_EXTRACTOR_ERROR: Script execution failed: \$(\$_.Exception.Message)"
    if (\$ie) {
        try { \$ie.Quit() } catch { }
    }
}

Write-Output "IE_EXTRACTOR_END: Automation complete"
POWERSHELL;
    }

    /**
     * Create advanced cURL command with certificate support
     */
    private function createAdvancedCurlCommand(string $url): string
    {
        // Use system curl with advanced options
        return sprintf(
            'curl -k -L -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" ' .
            '-H "Accept: application/json, text/html, */*" ' .
            '-H "Accept-Language: ro-RO,ro;q=0.9,en;q=0.8" ' .
            '-H "Cache-Control: no-cache" ' .
            '--connect-timeout 30 --max-time 60 ' .
            '--retry 2 --retry-delay 2 ' .
            '"%s"',
            $url
        );
    }

    /**
     * Parse output from browser automation scripts
     */
    private function parseExtractorOutput(string $output): array
    {
        // Look for JSON data markers
        if (preg_match('/EXTRACTOR_DATA_START:(.*?)EXTRACTOR_DATA_END:/s', $output, $matches) ||
            preg_match('/IE_EXTRACTOR_DATA_START:(.*?)IE_EXTRACTOR_DATA_END:/s', $output, $matches)) {
            
            $jsonContent = trim($matches[1]);
            return $this->parseJsonContent($jsonContent);
        }
        
        // Look for HTML content that might contain JSON
        if (preg_match('/EXTRACTOR_HTML_START:(.*?)EXTRACTOR_HTML_END:/s', $output, $matches) ||
            preg_match('/IE_EXTRACTOR_HTML_START:(.*?)IE_EXTRACTOR_HTML_END:/s', $output, $matches)) {
            
            $htmlContent = trim($matches[1]);
            return $this->parseJsonContent($htmlContent);
        }
        
        // Try to parse the entire output as potential JSON
        return $this->parseJsonContent($output);
    }

    /**
     * Parse HTTP response for JSON content
     */
    private function parseHttpResponse(string $content): array
    {
        return $this->parseJsonContent($content);
    }

    /**
     * Extract and parse JSON content from any text
     */
    private function parseJsonContent(string $content): array
    {
        // Method 1: Look for complete JSON objects with "mesaje"
        if (preg_match('/\{[^{}]*"mesaje"[^{}]*\{.*?\}[^{}]*\}/s', $content, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData && isset($jsonData['mesaje'])) {
                return [
                    'success' => true,
                    'method' => 'json_extraction',
                    'data' => $jsonData,
                    'message_count' => count($jsonData['mesaje'])
                ];
            }
        }

        // Method 2: Look for any JSON-like structure
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData && is_array($jsonData)) {
                return [
                    'success' => true,
                    'method' => 'partial_json_extraction',
                    'data' => $jsonData,
                    'message_count' => isset($jsonData['mesaje']) ? count($jsonData['mesaje']) : 0
                ];
            }
        }

        // Method 3: Check if entire content is JSON
        $jsonData = json_decode(trim($content), true);
        if ($jsonData && is_array($jsonData)) {
            return [
                'success' => true,
                'method' => 'full_content_json',
                'data' => $jsonData,
                'message_count' => isset($jsonData['mesaje']) ? count($jsonData['mesaje']) : 0
            ];
        }

        // Check for authentication errors
        if (str_contains($content, 'Certificatul nu a fost prezentat') ||
            str_contains($content, 'Pagina logout') ||
            str_contains($content, 'autentificare')) {
            
            return [
                'success' => false,
                'requires_auth' => true,
                'message' => 'Authentication required - ANAF returned login page'
            ];
        }

        return [
            'success' => false,
            'message' => 'No valid JSON content found in response',
            'content_preview' => substr($content, 0, 500)
        ];
    }

    /**
     * Test the automatic page extraction
     */
    public function testPageExtraction(): array
    {
        try {
            $testUrl = 'https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=1';
            $result = $this->extractAnafPageContent($testUrl);
            
            return [
                'success' => $result['success'] ?? false,
                'method' => $result['method'] ?? 'unknown',
                'message_count' => $result['message_count'] ?? 0,
                'requires_auth' => $result['requires_auth'] ?? false,
                'message' => $result['message'] ?? 'Extraction completed'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}