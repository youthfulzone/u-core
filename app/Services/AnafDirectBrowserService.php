<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AnafDirectBrowserService
{
    /**
     * Launch the default browser with ANAF URL to trigger certificate prompt
     * This forces Windows to prompt for ANY available certificate/token
     */
    public function launchBrowserWithCertificatePrompt(string $endpoint, array $params = []): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        Log::info('Launching browser with certificate prompt', [
            'url' => $url,
            'params' => $params,
        ]);

        try {
            // Method 1: Use start command to open default browser
            // This will trigger Windows certificate selection dialog
            $result = Process::run("start \"\" \"{$url}\"");
            
            if (!$result->successful()) {
                throw new \Exception('Failed to launch browser: ' . $result->errorOutput());
            }

            return [
                'success' => true,
                'message' => 'Browser launched with ANAF URL. Please select your certificate/token when prompted.',
                'url' => $url,
                'instructions' => [
                    '1. Windows should prompt you to select a certificate',
                    '2. Choose your ANAF token/certificate from the list',
                    '3. Enter your PIN if prompted',
                    '4. The browser will show the JSON response',
                    '5. Copy the JSON response and use the "Process Direct ANAF Data" feature'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Browser launch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Alternative method: Create an HTML file that forces certificate authentication
     */
    public function createCertificateAuthPage(string $endpoint, array $params = []): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        try {
            // Create an HTML page that makes an AJAX request requiring certificates
            $htmlContent = $this->createAuthHTML($url);
            $htmlPath = storage_path('app/temp/anaf_auth.html');
            
            // Ensure temp directory exists
            $tempDir = dirname($htmlPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            file_put_contents($htmlPath, $htmlContent);

            // Open the HTML file in the default browser
            $result = Process::run("start \"\" \"{$htmlPath}\"");
            
            if (!$result->successful()) {
                throw new \Exception('Failed to open auth page: ' . $result->errorOutput());
            }

            return [
                'success' => true,
                'message' => 'Authentication page opened. This will prompt for your certificate.',
                'html_path' => $htmlPath,
                'url' => $url,
                'instructions' => [
                    '1. The browser will open with an authentication page',
                    '2. Click "Authenticate with Certificate" button',
                    '3. Windows will prompt for certificate selection',
                    '4. Choose your ANAF token/certificate',
                    '5. Enter your PIN when prompted',
                    '6. The page will display the JSON response',
                    '7. Copy and use the "Process Direct ANAF Data" feature'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Auth page creation failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Create HTML page that forces certificate authentication
     */
    private function createAuthHTML(string $url): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>ANAF Certificate Authentication</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { background: #0066cc; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .result { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; white-space: pre-wrap; font-family: monospace; }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
        .loading { color: #856404; background: #fff3cd; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 ANAF Certificate Authentication</h1>
        <p>Click the button below to authenticate with your ANAF certificate/token.</p>
        <p><strong>This will prompt Windows to select your certificate.</strong></p>
        
        <button class="btn" onclick="authenticateWithCertificate()">Authenticate with Certificate</button>
        
        <div id="result" class="result" style="display:none;"></div>
        
        <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 4px;">
            <h3>Instructions:</h3>
            <ol>
                <li>Click "Authenticate with Certificate" button</li>
                <li>Windows will show a certificate selection dialog</li>
                <li>Choose your ANAF certificate/token from the list</li>
                <li>Enter your PIN when prompted</li>
                <li>The JSON response will appear below</li>
                <li>Copy the entire JSON response</li>
                <li>Go back to the SPV page and use "Process Direct ANAF Data"</li>
            </ol>
        </div>
    </div>

    <script>
        function authenticateWithCertificate() {
            const resultDiv = document.getElementById('result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result loading';
            resultDiv.textContent = 'Authenticating... Please select your certificate when prompted.';
            
            // Make an XMLHttpRequest that requires client certificates
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '{$url}', true);
            
            // Force credential prompt
            xhr.withCredentials = true;
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            resultDiv.className = 'result success';
                            resultDiv.textContent = 'SUCCESS! Copy the JSON below:\n\n' + JSON.stringify(response, null, 2);
                        } catch (e) {
                            resultDiv.className = 'result success';
                            resultDiv.textContent = 'SUCCESS! Copy the response below:\n\n' + xhr.responseText;
                        }
                    } else {
                        resultDiv.className = 'result error';
                        resultDiv.textContent = 'Authentication failed. Status: ' + xhr.status + '\nResponse: ' + xhr.responseText;
                    }
                }
            };
            
            xhr.onerror = function() {
                resultDiv.className = 'result error';
                resultDiv.textContent = 'Network error occurred. This might mean certificate authentication is required.';
            };
            
            try {
                xhr.send();
            } catch (e) {
                resultDiv.className = 'result error';
                resultDiv.textContent = 'Error: ' + e.message;
            }
        }
        
        // Auto-start authentication after 2 seconds
        setTimeout(function() {
            document.querySelector('.btn').click();
        }, 2000);
    </script>
</body>
</html>
HTML;
    }

    /**
     * Test the direct browser authentication
     */
    public function testDirectBrowser(): array
    {
        try {
            return $this->createCertificateAuthPage('listaMesaje', ['zile' => 1]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Simple browser launch test
     */
    public function testBrowserLaunch(): array
    {
        try {
            return $this->launchBrowserWithCertificatePrompt('listaMesaje', ['zile' => 1]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}