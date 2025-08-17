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
});

// Extension API endpoint (needs to be outside middleware)
Route::post('/api/anaf/extension-cookies', [AnafBrowserSessionController::class, 'receiveExtensionCookies'])
    ->name('anaf.extension.cookies');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/spv.php';
