<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AnafIEAutomationService
{
    /**
     * Use Internet Explorer automation to trigger smart card authentication
     * IE properly integrates with Windows smart card middleware
     */
    public function makeIEAuthenticatedRequest(string $endpoint, array $params = []): array
    {
        $url = 'https://webserviced.anaf.ro/SPVWS2/rest/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        Log::info('Starting IE automation for ANAF request', [
            'url' => $url,
            'params' => $params,
        ]);

        try {
            // Create a VBScript that uses IE automation
            $vbScript = $this->createIEVBScript($url);
            $scriptPath = storage_path('app/temp/anaf_ie_automation.vbs');
            
            // Ensure temp directory exists
            $tempDir = dirname($scriptPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            file_put_contents($scriptPath, $vbScript);

            // Execute the VBScript
            $result = Process::run("cscript.exe //NoLogo \"{$scriptPath}\"");
            
            // Clean up
            unlink($scriptPath);

            if (!$result->successful()) {
                throw new \Exception('IE automation failed: ' . $result->errorOutput());
            }

            $output = $result->output();
            
            // Parse the JSON response
            $jsonStart = strpos($output, 'JSON_START:');
            $jsonEnd = strpos($output, ':JSON_END');
            
            if ($jsonStart === false || $jsonEnd === false) {
                throw new \Exception('No JSON response found in IE automation output: ' . substr($output, 0, 500));
            }
            
            $jsonData = substr($output, $jsonStart + 11, $jsonEnd - $jsonStart - 11);
            $data = json_decode($jsonData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            if (isset($data['eroare'])) {
                throw new \Exception('ANAF API Error: ' . $data['eroare']);
            }

            Log::info('IE automation successful', [
                'response_keys' => array_keys($data),
                'message_count' => isset($data['mesaje']) ? count($data['mesaje']) : 0,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('IE automation failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Create VBScript for IE automation
     */
    private function createIEVBScript(string $url): string
    {
        return <<<VBSCRIPT
' VBScript for ANAF IE automation with smart card support
Dim ie, response, startTime, timeout

On Error Resume Next

' Create IE object
Set ie = CreateObject("InternetExplorer.Application")
If Err.Number <> 0 Then
    WScript.Echo "ERROR: Could not create IE object"
    WScript.Quit 1
End If

' Configure IE
ie.Visible = False
ie.Silent = True

' Navigate to ANAF URL
WScript.Echo "Starting ANAF IE request..."
ie.Navigate "{$url}"

' Wait for page to load and handle certificate prompts
timeout = 60 ' 60 seconds timeout
startTime = Timer

Do While ie.Busy Or ie.ReadyState <> 4
    WScript.Sleep 500
    If Timer - startTime > timeout Then
        WScript.Echo "ERROR: Timeout waiting for page load"
        ie.Quit
        WScript.Quit 1
    End If
Loop

' Additional wait for certificate selection dialog
WScript.Sleep 3000

' Get page content
response = ie.Document.body.innerText

' Clean up
ie.Quit
Set ie = Nothing

' Check if we got JSON response
If InStr(response, "{") > 0 And InStr(response, "}") > 0 Then
    ' Extract JSON content
    Dim jsonStart, jsonEnd, jsonContent
    jsonStart = InStr(response, "{")
    jsonEnd = InStrRev(response, "}")
    
    If jsonStart > 0 And jsonEnd > jsonStart Then
        jsonContent = Mid(response, jsonStart, jsonEnd - jsonStart + 1)
        WScript.Echo "JSON_START:" & jsonContent & ":JSON_END"
    Else
        WScript.Echo "ERROR: Could not extract JSON from response"
    End If
Else
    WScript.Echo "ERROR: No JSON found in response"
    WScript.Echo "Response content: " & Left(response, 500)
End If
VBSCRIPT;
    }

    /**
     * Test IE automation
     */
    public function testIEAutomation(): array
    {
        try {
            $data = $this->makeIEAuthenticatedRequest('listaMesaje', ['zile' => 1]);
            
            return [
                'success' => true,
                'message' => 'IE automation successful',
                'test_response' => [
                    'cnp' => $data['cnp'] ?? '',
                    'cui' => $data['cui'] ?? '',
                    'serial' => $data['serial'] ?? '',
                    'message_count' => isset($data['mesaje']) ? count($data['mesaje']) : 0,
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if IE automation is available
     */
    public function isIEAvailable(): bool
    {
        // Check if we're on Windows and IE is available
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        try {
            // Create a simple VBScript to test IE availability
            $testScript = 'Set ie = CreateObject("InternetExplorer.Application"): ie.Quit: WScript.Echo "OK"';
            $scriptPath = storage_path('app/temp/ie_test.vbs');
            
            // Ensure temp directory exists
            $tempDir = dirname($scriptPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            file_put_contents($scriptPath, $testScript);
            $result = Process::run("cscript.exe //NoLogo \"{$scriptPath}\"");
            unlink($scriptPath);
            
            return $result->successful() && str_contains($result->output(), 'OK');
        } catch (\Exception $e) {
            Log::debug('IE availability check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}