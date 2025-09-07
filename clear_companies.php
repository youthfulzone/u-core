<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Get count before deletion
    $count = \App\Models\Company::count();
    echo "Found {$count} companies in database.\n";
    
    if ($count > 0) {
        // Delete all companies
        $deleted = \App\Models\Company::query()->delete();
        echo "Successfully deleted {$deleted} companies.\n";
    } else {
        echo "Database is already empty.\n";
    }
    
    // Verify deletion
    $remainingCount = \App\Models\Company::count();
    echo "Remaining companies: {$remainingCount}\n";
    
    if ($remainingCount === 0) {
        echo "✅ Database cleared successfully!\n";
    } else {
        echo "⚠️ Warning: {$remainingCount} companies still remain.\n";
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}