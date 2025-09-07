<?php

use App\Http\Controllers\Spv\SpvController;
use App\Http\Controllers\Spv\SpvRequestsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('spv')->name('spv.')->group(function () {
    Route::get('/', [SpvController::class, 'index'])->name('index');
    Route::get('/requests', [SpvRequestsController::class, 'index'])->name('requests');
    Route::post('/requests', [SpvRequestsController::class, 'makeRequest'])->name('requests.make');
    Route::post('/sync-messages', [SpvController::class, 'syncMessages'])->name('sync-messages');
    Route::get('/download/{messageId}', [SpvController::class, 'downloadMessage'])->name('download-message');
    Route::get('/view/{messageId}', [SpvController::class, 'viewFile'])->name('view-file');
    Route::get('/viewer/{messageId}', [SpvController::class, 'showViewer'])->name('viewer');
    Route::post('/document-request', [SpvController::class, 'makeDocumentRequest'])->name('document-request');
    Route::post('/process-direct-anaf-data', [SpvController::class, 'processDirectAnafData'])->name('process-direct-anaf-data');
    Route::delete('/clear-data', [SpvController::class, 'clearData'])->name('clear-data');

    // Browser session authentication routes
    Route::get('/auth-status', [SpvController::class, 'getAuthenticationStatus'])->name('auth-status');

    // API call tracking routes
    Route::get('/api-call-status', [SpvController::class, 'getApiCallStatus'])->name('api-call-status');
    Route::post('/reset-api-counter', [SpvController::class, 'resetApiCounter'])->name('reset-api-counter');
});
