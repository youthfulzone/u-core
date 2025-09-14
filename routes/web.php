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
    Route::post('firme/add', [\App\Http\Controllers\FirmeController::class, 'addCompany'])
        ->name('firme.add');
    Route::post('firme/delete', [\App\Http\Controllers\FirmeController::class, 'deleteCompany'])
        ->name('firme.delete');

    Route::post('firme/verify', [\App\Http\Controllers\FirmeController::class, 'verifyCompany'])
        ->name('firme.verify');
        
    Route::delete('firme/clear-all', [\App\Http\Controllers\FirmeController::class, 'clearAllCompanies'])
        ->name('firme.clear-all');

    // E-factura routes
    Route::prefix('efactura')->name('efactura.')->group(function () {
        Route::get('/', [\App\Http\Controllers\EfacturaController::class, 'index'])->name('index');
        Route::post('/authenticate', [\App\Http\Controllers\EfacturaController::class, 'authenticate'])->name('authenticate');
        Route::get('/status', [\App\Http\Controllers\EfacturaController::class, 'status'])->name('status');
        Route::post('/tunnel-control', [\App\Http\Controllers\EfacturaController::class, 'tunnelControl'])->name('tunnel-control');
        Route::get('/tunnel-status', [\App\Http\Controllers\EfacturaController::class, 'getTunnelStatus'])->name('tunnel-status');
        Route::post('/revoke', [\App\Http\Controllers\EfacturaController::class, 'revoke'])->name('revoke');
        Route::post('/refresh-token', [\App\Http\Controllers\EfacturaController::class, 'refreshToken'])->name('refresh-token');
        Route::post('/mark-compromised', [\App\Http\Controllers\EfacturaController::class, 'markTokenCompromised'])->name('mark-compromised');
        Route::post('/sync-messages', [\App\Http\Controllers\EfacturaController::class, 'syncMessages'])->name('sync-messages');
        Route::post('/download-pdf', [\App\Http\Controllers\EfacturaController::class, 'downloadPDF'])->name('download-pdf');
        Route::post('/view-xml', [\App\Http\Controllers\EfacturaController::class, 'viewXML'])->name('view-xml');
        Route::delete('/clear-database', [\App\Http\Controllers\EfacturaController::class, 'clearDatabase'])->name('clear-database');
        Route::get('/sync-status', [\App\Http\Controllers\EfacturaController::class, 'getSyncStatus'])->name('sync-status');
        Route::get('/recent-invoices', [\App\Http\Controllers\EfacturaController::class, 'getRecentInvoices'])->name('recent-invoices');
        Route::post('/generate-pdf', [\App\Http\Controllers\EfacturaController::class, 'generatePDF'])->name('generate-pdf');
    });
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

// E-factura OAuth callback - MUST be accessible via cloudflared tunnel
// Primary callback route matching Python script configuration
Route::get('/callback', [\App\Http\Controllers\EfacturaController::class, 'callback'])
    ->name('efactura.oauth.callback');

// Legacy callback route for compatibility
Route::get('/efactura/oauth/callback', [\App\Http\Controllers\EfacturaController::class, 'callback'])
    ->name('efactura.oauth.callback.legacy');

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
