<?php

use App\Http\Controllers\AnafBrowserSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('spv-test', function () {
        return Inertia::render('spv/Index', [
            'messages' => [],
            'requests' => [],
            'sessionActive' => false,
            'sessionExpiry' => null,
            'documentTypes' => [],
            'incomeReasons' => [],
        ]);
    })->name('spv-test');

    // Firme routes
    Route::get('firme', [\App\Http\Controllers\FirmeController::class, 'index'])
        ->name('firme.index');

    Route::post('firme/process', [\App\Http\Controllers\FirmeController::class, 'processQueue'])
        ->name('firme.process');

    Route::post('firme/approve', [\App\Http\Controllers\FirmeController::class, 'approve'])
        ->name('firme.approve');

    Route::post('firme/reject', [\App\Http\Controllers\FirmeController::class, 'reject'])
        ->name('firme.reject');

    Route::post('firme/mass-action', [\App\Http\Controllers\FirmeController::class, 'massAction'])
        ->name('firme.mass-action');

    Route::get('firme/status', [\App\Http\Controllers\FirmeController::class, 'getStatus'])
        ->name('firme.status');

    Route::post('firme/process-next', [\App\Http\Controllers\FirmeController::class, 'processNext'])
        ->name('firme.process-next');

    Route::post('firme/lock', [\App\Http\Controllers\FirmeController::class, 'lock'])
        ->name('firme.lock');

    Route::post('firme/unlock', [\App\Http\Controllers\FirmeController::class, 'unlock'])
        ->name('firme.unlock');


    // ANAF routes removed - authentication handled directly in SPV
});

// ANAF Browser Session API Routes
Route::prefix('api/anaf')->group(function () {
    Route::post('/session/import', [AnafBrowserSessionController::class, 'importSession'])
        ->name('anaf.session.import');

    Route::get('/session/status', [AnafBrowserSessionController::class, 'sessionStatus'])
        ->name('anaf.session.status');

    Route::delete('/session', [AnafBrowserSessionController::class, 'clearSession'])
        ->name('anaf.session.clear');

    Route::post('/session/refresh', [AnafBrowserSessionController::class, 'refreshSession'])
        ->name('anaf.session.refresh');

    Route::post('/session/capture', [AnafBrowserSessionController::class, 'captureFromResponse'])
        ->name('anaf.session.capture');

    Route::get('/simple-fetch', [AnafBrowserSessionController::class, 'simpleFetch'])
        ->name('anaf.simple.fetch');

    Route::get('/proxy/{endpoint}', [AnafBrowserSessionController::class, 'proxyRequest'])
        ->name('anaf.proxy')
        ->where('endpoint', 'listaMesaje|descarcare');

    // Global cookie management routes
    Route::get('/global-cookies', [AnafBrowserSessionController::class, 'getGlobalAnafCookies'])
        ->name('anaf.global.get');

    Route::post('/global-cookies/use', [AnafBrowserSessionController::class, 'useGlobalCookies'])
        ->name('anaf.global.use');

});

// ANAF Cookie Helper (accessible without auth for browser window)
Route::get('/anaf/cookie-helper', function () {
    return view('anaf-cookie-helper');
})->name('anaf.cookie.helper');

// Extension API endpoint (needs to be outside middleware)
Route::post('/api/anaf/extension-cookies', [AnafBrowserSessionController::class, 'receiveExtensionCookies'])
    ->name('anaf.extension.cookies');

// Test VIES API (temporary route for testing)
Route::get('/test-vies/{cui?}', function ($cui = '23681054') {
    $service = new \App\Services\AnafCompanyService();
    
    // Use reflection to test the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('fetchCompanyFromVIES');
    $method->setAccessible(true);
    
    $result = $method->invoke($service, $cui);
    
    return response()->json([
        'cui' => $cui,
        'vies_result' => $result,
        'success' => !empty($result),
    ]);
})->name('test.vies');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/spv.php';
