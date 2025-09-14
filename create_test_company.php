<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$app->boot();

// Create a test company
$company = \App\Models\Company::create([
    'cui' => '49423933',
    'denumire' => 'STETCO MARIANA-FLORENTINA II'
]);

echo "Test company created: {$company->cui} - {$company->denumire}\n";
echo "Total companies: " . \App\Models\Company::count() . "\n";