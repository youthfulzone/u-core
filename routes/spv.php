<?php

use App\Http\Controllers\Spv\SpvController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('spv')->name('spv.')->group(function () {
    Route::get('/', [SpvController::class, 'index'])->name('index');
    Route::post('/sync-messages', [SpvController::class, 'syncMessages'])->name('sync-messages');
    Route::get('/download/{messageId}', [SpvController::class, 'downloadMessage'])->name('download-message');
    Route::post('/document-request', [SpvController::class, 'makeDocumentRequest'])->name('document-request');
    Route::post('/process-direct-anaf-data', [SpvController::class, 'processDirectAnafData'])->name('process-direct-anaf-data');
    
    // Browser session authentication routes
    Route::get('/auth-status', [SpvController::class, 'getAuthenticationStatus'])->name('auth-status');
});

