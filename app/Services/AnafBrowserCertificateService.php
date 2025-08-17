<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;

class AnafBrowserCertificateService
{
    /**
     * Use browser certificate authentication in backend by leveraging Windows certificate store
     */
    public function makeBackendRequestWithBrowserCert(string $endpoint, array $params = []): array
    {
        Log::info('Starting backend request with browser certificate authentication', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);

        // Try multiple methods to leverage browser certificate authentication
        $methods = [
            'windowsCertificateStore',
            'powerShellWithCertificateDialog',
            'ieAutomationBackend',
            'curlWithSystemCertificates'
        ];

        foreach ($methods as $method) {
            try {
                Log::info("Trying backend certificate method: {$method}");
                $result = $this->$method($endpoint, $params);
                
                if ($result && isset($result['success']) && $result['success']) {
                    Log::info("Successfully authenticated using method: {$method}");
                    return $result;
                }
            } catch (\Exception $e) {
                Log::warning("Method {$method} failed", ['error' => $e->getMessage()]);
                continue;
            }
        }

        throw new \Exception('All browser certificate authentication methods failed');
    }

    /**
     * Method 1: Use Windows Certificate Store (same as browser)
     */
    private function windowsCertificateStore(string $endpoint, array $params): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Create PowerShell script that uses Windows Certificate Store
        $psScript = $this->createWindowsCertificateScript($url);
        $scriptPath = storage_path('app/temp/windows_cert_auth.ps1');
        
        // Ensure temp directory exists
        $tempDir = dirname($scriptPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        file_put_contents($scriptPath, $psScript);
        
        // Execute with timeout
        $command = "powershell.exe -ExecutionPolicy Bypass -File \"{$scriptPath}\"";
        $result = Process::timeout(60)->run($command);
        
        // Clean up
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }
        
        if (!$result->successful()) {
            throw new \Exception('Windows Certificate Store authentication failed: ' . $result->errorOutput());
        }
        
        return $this->parseBackendAuthOutput($result->output());
    }

    /**
     * Method 2: PowerShell with Certificate Dialog (browser-like experience)
     */
    private function powerShellWithCertificateDialog(string $endpoint, array $params): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $psScript = $this->createCertificateDialogScript($url);
        $scriptPath = storage_path('app/temp/cert_dialog_auth.ps1');
        
        file_put_contents($scriptPath, $psScript);
        
        $command = "powershell.exe -ExecutionPolicy Bypass -File \"{$scriptPath}\"";
        $result = Process::timeout(90)->run($command);
        
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }
        
        if (!$result->successful()) {
            throw new \Exception('Certificate dialog authentication failed: ' . $result->errorOutput());
        }
        
        return $this->parseBackendAuthOutput($result->output());
    }

    /**
     * Method 3: IE Automation Backend (leverages browser certificate handling)
     */
    private function ieAutomationBackend(string $endpoint, array $params): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $psScript = $this->createIEBackendScript($url);
        $scriptPath = storage_path('app/temp/ie_backend_auth.ps1');
        
        file_put_contents($scriptPath, $psScript);
        
        $command = "powershell.exe -ExecutionPolicy Bypass -File \"{$scriptPath}\"";
        $result = Process::timeout(120)->run($command);
        
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }
        
        if (!$result->successful()) {
            throw new \Exception('IE backend authentication failed: ' . $result->errorOutput());
        }
        
        return $this->parseBackendAuthOutput($result->output());
    }

    /**
     * Method 4: cURL with system certificates
     */
    private function curlWithSystemCertificates(string $endpoint, array $params): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Try to use system certificate store
        $curlCommand = sprintf(
            'curl -k -L --cert-type PEM --cert "Windows Certificate Store" ' .
            '-A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" ' .
            '-H "Accept: application/json, text/html, */*" ' .
            '--connect-timeout 30 --max-time 60 ' .
            '"%s"',
            $url
        );
        
        $result = Process::timeout(60)->run($curlCommand);
        
        if (!$result->successful()) {
            throw new \Exception('cURL system certificates failed: ' . $result->errorOutput());
        }
        
        return $this->parseHttpResponse($result->output());
    }

    /**
     * Create Windows Certificate Store authentication script
     */
    private function createWindowsCertificateScript(string $url): string
    {
        return <<<POWERSHELL
# Windows Certificate Store Authentication (Browser-Compatible)
try {
    Write-Output "BACKEND_AUTH_START: Using Windows Certificate Store"
    
    # Load certificate from Windows store (same as browser)
    \$store = New-Object System.Security.Cryptography.X509Certificates.X509Store([System.Security.Cryptography.X509Certificates.StoreName]::My, [System.Security.Cryptography.X509Certificates.StoreLocation]::CurrentUser)
    \$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadOnly)
    
    # Find ANAF certificate (same logic as browser)
    \$anafCerts = \$store.Certificates | Where-Object { 
        \$_.Subject -like "*GRAD MARIUS-BENIAMIN*" -and \$_.HasPrivateKey 
    }
    
    if (\$anafCerts.Count -eq 0) {
        # Fallback: any Romanian certificate with private key
        \$anafCerts = \$store.Certificates | Where-Object { 
            \$_.Subject -like "*C=RO*" -and \$_.HasPrivateKey 
        }
    }
    
    if (\$anafCerts.Count -eq 0) {
        throw "No suitable ANAF certificate found in Windows store"
    }
    
    \$cert = \$anafCerts[0]
    Write-Output "BACKEND_AUTH_LOG: Using certificate: \$(\$cert.Subject)"
    
    \$store.Close()
    
    # Create HTTP request with certificate (browser-style)
    \$request = [System.Net.HttpWebRequest]::Create("{$url}")
    \$request.Method = "GET"
    \$request.Timeout = 30000
    \$request.UserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    \$request.Accept = "application/json, text/html, */*"
    \$request.Headers.Add("Accept-Language", "ro-RO,ro;q=0.9,en;q=0.8")
    
    # Add certificate (same as browser)
    \$request.ClientCertificates.Add(\$cert)
    
    # Disable SSL validation (like browser with self-signed certs)
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { \$true }
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    
    Write-Output "BACKEND_AUTH_LOG: Making authenticated request to ANAF"
    
    \$response = \$request.GetResponse()
    \$responseStream = \$response.GetResponseStream()
    \$reader = New-Object System.IO.StreamReader(\$responseStream)
    \$content = \$reader.ReadToEnd()
    
    \$reader.Close()
    \$responseStream.Close()
    \$response.Close()
    
    Write-Output "BACKEND_AUTH_SUCCESS: Authentication successful"
    Write-Output "BACKEND_AUTH_DATA_START:"
    Write-Output \$content
    Write-Output "BACKEND_AUTH_DATA_END:"
    
} catch {
    Write-Output "BACKEND_AUTH_ERROR: \$(\$_.Exception.Message)"
}

Write-Output "BACKEND_AUTH_END: Windows Certificate Store authentication complete"
POWERSHELL;
    }

    /**
     * Create certificate dialog script (browser-like experience)
     */
    private function createCertificateDialogScript(string $url): string
    {
        return <<<POWERSHELL
# Certificate Dialog Authentication (Browser Experience)
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Security

try {
    Write-Output "CERT_DIALOG_START: Showing certificate selection dialog"
    
    # Load certificates from Windows store
    \$store = New-Object System.Security.Cryptography.X509Certificates.X509Store([System.Security.Cryptography.X509Certificates.StoreName]::My, [System.Security.Cryptography.X509Certificates.StoreLocation]::CurrentUser)
    \$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadOnly)
    
    # Filter for certificates with private keys (browser-compatible)
    \$certs = \$store.Certificates | Where-Object { \$_.HasPrivateKey }
    
    if (\$certs.Count -eq 0) {
        throw "No certificates with private keys found"
    }
    
    # Show certificate selection dialog (same as browser)
    \$selectedCert = [System.Security.Cryptography.X509Certificates.X509Certificate2UI]::SelectFromCollection(
        \$certs,
        "Select ANAF Certificate",
        "Choose the certificate to authenticate with ANAF (same as browser)",
        [System.Security.Cryptography.X509Certificates.X509SelectionFlag]::SingleSelection
    )
    
    if (\$selectedCert.Count -eq 0) {
        throw "No certificate selected"
    }
    
    \$cert = \$selectedCert[0]
    Write-Output "CERT_DIALOG_LOG: Selected certificate: \$(\$cert.Subject)"
    
    \$store.Close()
    
    # Make authenticated request (browser-style)
    \$request = [System.Net.HttpWebRequest]::Create("{$url}")
    \$request.Method = "GET"
    \$request.Timeout = 30000
    \$request.UserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    \$request.Accept = "application/json, text/html, */*"
    \$request.ClientCertificates.Add(\$cert)
    
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { \$true }
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    
    Write-Output "CERT_DIALOG_LOG: Authenticating with selected certificate"
    
    \$response = \$request.GetResponse()
    \$responseStream = \$response.GetResponseStream()
    \$reader = New-Object System.IO.StreamReader(\$responseStream)
    \$content = \$reader.ReadToEnd()
    
    \$reader.Close()
    \$responseStream.Close()
    \$response.Close()
    
    Write-Output "CERT_DIALOG_SUCCESS: Authentication successful"
    Write-Output "CERT_DIALOG_DATA_START:"
    Write-Output \$content
    Write-Output "CERT_DIALOG_DATA_END:"
    
} catch {
    Write-Output "CERT_DIALOG_ERROR: \$(\$_.Exception.Message)"
}

Write-Output "CERT_DIALOG_END: Certificate dialog authentication complete"
POWERSHELL;
    }

    /**
     * Create IE backend script (leverages browser certificate handling)
     */
    private function createIEBackendScript(string $url): string
    {
        return <<<POWERSHELL
# IE Backend Authentication (Browser Certificate Handling)
try {
    Write-Output "IE_BACKEND_START: Starting IE backend authentication"
    
    \$ie = New-Object -ComObject "InternetExplorer.Application"
    \$ie.Visible = \$false
    \$ie.Silent = \$true
    
    Write-Output "IE_BACKEND_LOG: Navigating to ANAF (will use browser certificate handling)"
    \$ie.Navigate("{$url}")
    
    # Wait for navigation and certificate authentication
    \$timeout = 90
    \$elapsed = 0
    
    while ((\$ie.Busy -or \$ie.ReadyState -ne 4) -and \$elapsed -lt \$timeout) {
        Start-Sleep -Seconds 1
        \$elapsed++
    }
    
    if (\$elapsed -ge \$timeout) {
        \$ie.Quit()
        throw "IE backend authentication timeout"
    }
    
    # Additional wait for certificate processing
    Start-Sleep -Seconds 5
    
    Write-Output "IE_BACKEND_LOG: Extracting response content"
    \$document = \$ie.Document
    
    if (\$document.body) {
        \$content = \$document.body.innerText
        
        if (\$content -match '\\{.*"mesaje".*\\}') {
            Write-Output "IE_BACKEND_SUCCESS: Found JSON response"
            Write-Output "IE_BACKEND_DATA_START:"
            Write-Output \$content
            Write-Output "IE_BACKEND_DATA_END:"
        } else {
            Write-Output "IE_BACKEND_ERROR: No JSON content found"
            Write-Output "IE_BACKEND_HTML: \$(\$document.body.innerHTML)"
        }
    } else {
        Write-Output "IE_BACKEND_ERROR: No document body found"
    }
    
    \$ie.Quit()
    
} catch {
    Write-Output "IE_BACKEND_ERROR: \$(\$_.Exception.Message)"
    if (\$ie) {
        try { \$ie.Quit() } catch { }
    }
}

Write-Output "IE_BACKEND_END: IE backend authentication complete"
POWERSHELL;
    }

    /**
     * Parse backend authentication output
     */
    private function parseBackendAuthOutput(string $output): array
    {
        // Look for data markers from different methods
        $patterns = [
            '/BACKEND_AUTH_DATA_START:(.*?)BACKEND_AUTH_DATA_END:/s',
            '/CERT_DIALOG_DATA_START:(.*?)CERT_DIALOG_DATA_END:/s',
            '/IE_BACKEND_DATA_START:(.*?)IE_BACKEND_DATA_END:/s'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                $jsonContent = trim($matches[1]);
                return $this->parseJsonContent($jsonContent);
            }
        }

        // Check for authentication errors
        if (str_contains($output, 'BACKEND_AUTH_ERROR') || 
            str_contains($output, 'CERT_DIALOG_ERROR') || 
            str_contains($output, 'IE_BACKEND_ERROR')) {
            
            return [
                'success' => false,
                'requires_auth' => true,
                'message' => 'Backend certificate authentication failed - certificate may need browser setup'
            ];
        }

        return [
            'success' => false,
            'message' => 'No valid response from backend certificate authentication',
            'output_preview' => substr($output, 0, 500)
        ];
    }

    /**
     * Parse HTTP response
     */
    private function parseHttpResponse(string $content): array
    {
        return $this->parseJsonContent($content);
    }

    /**
     * Parse JSON content from any source
     */
    private function parseJsonContent(string $content): array
    {
        // Try to find and parse JSON
        if (preg_match('/\{[^{}]*"mesaje"[^{}]*\{.*?\}[^{}]*\}/s', $content, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData && isset($jsonData['mesaje'])) {
                return [
                    'success' => true,
                    'method' => 'backend_certificate_auth',
                    'data' => $jsonData,
                    'message_count' => count($jsonData['mesaje'])
                ];
            }
        }

        // Check if entire content is JSON
        $jsonData = json_decode(trim($content), true);
        if ($jsonData && is_array($jsonData) && isset($jsonData['mesaje'])) {
            return [
                'success' => true,
                'method' => 'backend_certificate_auth',
                'data' => $jsonData,
                'message_count' => count($jsonData['mesaje'])
            ];
        }

        return [
            'success' => false,
            'message' => 'No valid JSON found in backend certificate response',
            'content_preview' => substr($content, 0, 500)
        ];
    }

    /**
     * Test backend certificate authentication
     */
    public function testBackendCertificateAuth(): array
    {
        try {
            $result = $this->makeBackendRequestWithBrowserCert('listaMesaje', ['zile' => 1]);
            
            return [
                'success' => $result['success'] ?? false,
                'method' => $result['method'] ?? 'unknown',
                'message_count' => $result['message_count'] ?? 0,
                'message' => $result['success'] ? 'Backend certificate authentication successful' : ($result['message'] ?? 'Authentication failed')
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}