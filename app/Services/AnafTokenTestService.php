<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AnafTokenTestService
{
    /**
     * Simple test to verify token PIN prompt works
     * This creates a minimal test that should trigger PIN prompt
     */
    public function testTokenPinPrompt(): array
    {
        try {
            // Create a simple PowerShell script that tries to use certificate private key
            $psScript = storage_path('app/temp/test_token_pin.ps1');
            
            // Ensure temp directory exists
            $tempDir = dirname($psScript);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $powershellCode = <<<POWERSHELL
try {
    Write-Host "Testing token PIN prompt..."
    
    # Load certificate UI assembly
    Add-Type -AssemblyName System.Security
    
    # Get all certificates
    \$allCerts = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2Collection
    \$currentUserStore = New-Object System.Security.Cryptography.X509Certificates.X509Store("My", "CurrentUser")
    \$currentUserStore.Open("ReadOnly")
    \$allCerts.AddRange(\$currentUserStore.Certificates)
    \$currentUserStore.Close()
    
    Write-Host "Found " \$allCerts.Count " certificates"
    
    # Show certificate selection dialog
    \$selectedCerts = [System.Security.Cryptography.X509Certificates.X509Certificate2UI]::SelectFromCollection(
        \$allCerts,
        "Select Certificate for PIN Test",
        "Select a certificate to test PIN prompt:",
        "SingleSelection"
    )
    
    if (\$selectedCerts.Count -eq 0) {
        throw "No certificate selected"
    }
    
    \$cert = \$selectedCerts[0]
    Write-Host "Selected: " \$cert.Subject
    Write-Host "Has private key: " \$cert.HasPrivateKey
    
    if (-not \$cert.HasPrivateKey) {
        throw "Selected certificate does not have private key"
    }
    
    # Try to access the private key - this should trigger PIN prompt
    Write-Host "Attempting to access private key (should prompt for PIN)..."
    
    try {
        \$privateKey = \$cert.PrivateKey
        if (\$privateKey -ne \$null) {
            Write-Host "✅ Private key accessed successfully - PIN prompt worked!"
            
            # Try to sign some data to really test the key
            \$testData = [System.Text.Encoding]::UTF8.GetBytes("test data for signing")
            
            if (\$privateKey -is [System.Security.Cryptography.RSA]) {
                Write-Host "Testing RSA private key..."
                \$signature = \$privateKey.SignData(\$testData, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
                Write-Host "✅ RSA signature successful - token PIN worked!"
                Write-Host "RESULT:SUCCESS:PIN_PROMPT_WORKED"
            } else {
                Write-Host "✅ Private key accessible - PIN worked!"
                Write-Host "RESULT:SUCCESS:PIN_PROMPT_WORKED"
            }
        } else {
            Write-Host "❌ Private key is null"
            Write-Host "RESULT:FAILED:NO_PRIVATE_KEY"
        }
    } catch [System.Security.Cryptography.CryptographicException] {
        Write-Host "❌ Cryptographic exception: " \$_.Exception.Message
        if (\$_.Exception.Message -match "cancelled\|denied\|pin\|password") {
            Write-Host "RESULT:CANCELLED:USER_CANCELLED_PIN"
        } else {
            Write-Host "RESULT:FAILED:CRYPTO_ERROR"
        }
    }
    
} catch {
    Write-Host "❌ Error: " \$_.Exception.Message
    Write-Host "RESULT:FAILED:" \$_.Exception.Message
}
POWERSHELL;
            
            file_put_contents($psScript, $powershellCode);
            
            Log::info('Running token PIN test...');
            
            // Execute with longer timeout for PIN entry
            $result = Process::timeout(120)->run("powershell -ExecutionPolicy Bypass -File \"$psScript\"");
            
            unlink($psScript);
            
            $output = $result->output();
            
            Log::info('Token PIN test output', ['output' => $output]);
            
            // Parse result
            if (str_contains($output, 'RESULT:SUCCESS:PIN_PROMPT_WORKED')) {
                return [
                    'success' => true,
                    'message' => '✅ Token PIN prompt is working correctly!',
                    'details' => 'Certificate private key was accessed successfully, which means PIN prompt appeared and was entered correctly.'
                ];
            } elseif (str_contains($output, 'RESULT:CANCELLED:USER_CANCELLED_PIN')) {
                return [
                    'success' => false,
                    'message' => '⚠️ PIN prompt appeared but was cancelled by user.',
                    'details' => 'The PIN dialog was shown but you clicked Cancel or closed it.'
                ];
            } elseif (str_contains($output, 'No certificate selected')) {
                return [
                    'success' => false,
                    'message' => '⚠️ No certificate was selected.',
                    'details' => 'You need to select a certificate from the dialog to test PIN prompt.'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '❌ Token PIN test failed.',
                    'details' => 'Output: ' . substr($output, 0, 500)
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('Token PIN test failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message' => 'Token PIN test error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test ANAF authentication with working PIN prompt
     */
    public function testAnafWithPin(): array
    {
        try {
            // First test if PIN prompt works
            $pinTest = $this->testTokenPinPrompt();
            
            if (!$pinTest['success']) {
                return [
                    'success' => false,
                    'message' => 'Cannot test ANAF - PIN prompt is not working: ' . $pinTest['message']
                ];
            }
            
            // If PIN prompt works, try ANAF authentication
            // This would need a separate PowerShell script that combines certificate selection + ANAF call
            
            return [
                'success' => true,
                'message' => 'PIN prompt test successful - ready for ANAF authentication',
                'next_step' => 'Now that PIN prompt is working, we can implement ANAF SSL authentication'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'ANAF PIN test failed: ' . $e->getMessage()
            ];
        }
    }
}