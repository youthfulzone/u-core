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

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/spv.php';
