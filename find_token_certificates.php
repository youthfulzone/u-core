<?php
/**
 * Tool to find certificates on PKCS#11 tokens
 * Run with: php find_token_certificates.php
 */

// Available drivers to test
$drivers = [
    'C:\Program Files\SafeNet\Authentication\SAC\x64\IDPrimePKCS1164.dll',
    'C:\Program Files (x86)\Gemalto\IDGo 800 PKCS#11\IDPrimePKCS1164.dll',
    'C:\Windows\System32\eTPKCS11.dll',
];

echo "🔍 Searching for PKCS#11 certificates on your token...\n\n";

foreach ($drivers as $driver) {
    if (!file_exists($driver)) {
        echo "❌ Driver not found: $driver\n";
        continue;
    }
    
    echo "✅ Testing driver: $driver\n";
    
    // Test with pkcs11-tool if available
    $command = "pkcs11-tool --module \"$driver\" --list-objects --type cert";
    echo "Running: $command\n";
    
    $output = shell_exec($command . ' 2>&1');
    
    if ($output && !str_contains($output, 'error') && !str_contains($output, 'Error')) {
        echo "📜 Certificates found:\n";
        echo $output . "\n";
    } else {
        echo "ℹ️  pkcs11-tool not available or no certificates found\n";
        echo "Output: " . ($output ?: 'No output') . "\n";
    }
    
    echo str_repeat('-', 50) . "\n\n";
}

echo "📋 Manual Certificate Discovery:\n";
echo "1. Insert your token and enter your PIN when prompted\n";
echo "2. Look for certificate entries with 'label:' field\n";
echo "3. Use the label value for ANAF_CERTIFICATE_LABEL\n\n";

echo "🔧 Alternative Methods:\n";
echo "1. Windows Certificate Manager (certmgr.msc) - Personal certificates\n";
echo "2. Token vendor software to view certificate details\n";
echo "3. Browser certificate manager (if token integrates with browser)\n";