<?php
/**
 * Test browser automation approach for ANAF
 * This uses a real browser instance that can handle certificate authentication
 */

echo "Testing browser automation for ANAF access...\n\n";

// Option 1: PowerShell with Internet Explorer COM automation
$psScript = <<<'POWERSHELL'
# Create Internet Explorer COM object
$ie = New-Object -ComObject InternetExplorer.Application
$ie.Visible = $true
$ie.Navigate("https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60")

# Wait for page to load and certificate prompt
Start-Sleep -Seconds 5

# The browser will prompt for certificate selection and PIN
# User must manually select certificate

# Wait for page to fully load
while ($ie.Busy -or $ie.ReadyState -ne 4) {
    Start-Sleep -Milliseconds 500
}

# Get the page content
$content = $ie.Document.body.innerText

# Check if we got JSON
if ($content -match '"mesaje"') {
    Write-Host "SUCCESS: Got ANAF JSON response"
    Write-Host "JSON_DATA_START"
    Write-Host $content
    Write-Host "JSON_DATA_END"
} else {
    Write-Host "ERROR: Did not get JSON response"
    Write-Host "Response: $content"
}

# Close IE
$ie.Quit()
POWERSHELL;

file_put_contents('anaf_browser_test.ps1', $psScript);

echo "Running browser automation test...\n";
echo "IMPORTANT: You will see Internet Explorer open.\n";
echo "1. Select your certificate when prompted\n";
echo "2. Enter your PIN\n";
echo "3. Wait for the page to load\n\n";

$output = shell_exec('powershell -ExecutionPolicy Bypass -File anaf_browser_test.ps1');
echo $output;

// Parse the JSON from output
if (preg_match('/JSON_DATA_START\s*(.*?)\s*JSON_DATA_END/s', $output, $matches)) {
    $jsonData = trim($matches[1]);
    $data = json_decode($jsonData, true);
    
    if ($data && isset($data['mesaje'])) {
        echo "\n\n✅ SUCCESS! Found " . count($data['mesaje']) . " messages\n";
        echo "CNP: " . ($data['cnp'] ?? 'not set') . "\n";
        echo "CUI: " . ($data['cui'] ?? 'not set') . "\n";
        
        if (count($data['mesaje']) > 0) {
            echo "\nFirst message:\n";
            print_r($data['mesaje'][0]);
        }
    }
}

// Clean up
unlink('anaf_browser_test.ps1');

echo "\n\nAlternative Option: Manual Copy-Paste Method\n";
echo "=====================================\n";
echo "Since automated methods are failing, here's a semi-automated approach:\n\n";
echo "1. Open this URL in your browser:\n";
echo "   https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60\n\n";
echo "2. Authenticate with your certificate\n\n";
echo "3. Once you see the JSON, press Ctrl+A, Ctrl+C to copy all\n\n";
echo "4. Save it to a file: anaf_response.json\n\n";
echo "5. Run this PHP code to process it:\n\n";

$processCode = <<<'PHP'
$json = file_get_contents('anaf_response.json');
$data = json_decode($json, true);

echo "Messages found: " . count($data['mesaje'] ?? []) . "\n";
echo "CNP: " . ($data['cnp'] ?? '') . "\n";
echo "CUI: " . ($data['cui'] ?? '') . "\n";

// Process and save messages to database
foreach ($data['mesaje'] ?? [] as $message) {
    // Save to database or process as needed
    echo "Processing message ID: " . $message['id'] . "\n";
}
PHP;

echo $processCode;
echo "\n\nThis manual approach ensures you get the real data while we solve the authentication issue.\n";