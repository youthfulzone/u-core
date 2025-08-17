<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->boot();

echo "Testing ANAF download debug...\n";

try {
    // Test session status first
    $spvService = app(\App\Services\AnafSpvService::class);
    $sessionStatus = $spvService->getSessionStatus();
    
    echo "Session Status:\n";
    echo "- Active: " . ($sessionStatus['active'] ? 'YES' : 'NO') . "\n";
    echo "- Cookie count: " . count($sessionStatus['cookie_names']) . "\n";
    echo "- Expires: " . ($sessionStatus['expires_at'] ?? 'unknown') . "\n";
    echo "- Source: " . ($sessionStatus['source'] ?? 'unknown') . "\n";
    echo "\n";
    
    if (!$sessionStatus['active']) {
        echo "❌ No active session found. Please sync cookies from the extension first.\n";
        exit(1);
    }
    
    // Try to get messages first to get a valid message ID
    echo "Getting recent messages to find a download ID...\n";
    $messages = $spvService->getMessagesList(60);
    
    if (empty($messages['mesaje'])) {
        echo "❌ No messages found. Cannot test download.\n";
        exit(1);
    }
    
    $testMessage = $messages['mesaje'][0];
    $messageId = $testMessage['id'];
    
    echo "Testing download with message ID: {$messageId}\n";
    echo "Message details: {$testMessage['detalii']}\n";
    echo "Message type: {$testMessage['tip']}\n";
    echo "Message CIF: {$testMessage['cif']}\n\n";
    
    // Test the download
    echo "Attempting download...\n";
    $response = $spvService->downloadMessage($messageId);
    
    $contentType = $response->header('Content-Type', 'unknown');
    $bodyContent = $response->body();
    $bodyLength = strlen($bodyContent);
    
    echo "Download Response:\n";
    echo "- Status: " . $response->status() . "\n";
    echo "- Content-Type: {$contentType}\n";
    echo "- Content-Length: {$bodyLength} bytes\n";
    echo "- Is PDF: " . (str_starts_with($bodyContent, '%PDF') ? 'YES' : 'NO') . "\n";
    echo "- Body preview (first 200 chars): " . substr($bodyContent, 0, 200) . "\n";
    
    if (str_starts_with($bodyContent, '%PDF')) {
        echo "✅ SUCCESS: Downloaded a valid PDF!\n";
        
        // Save to test file
        $testFile = 'test_download.pdf';
        file_put_contents($testFile, $bodyContent);
        echo "✅ Saved test PDF to: {$testFile}\n";
    } else {
        echo "❌ ERROR: Not a PDF file\n";
        echo "First 500 chars of response:\n";
        echo substr($bodyContent, 0, 500) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Check laravel.log for detailed error information.\n";
}