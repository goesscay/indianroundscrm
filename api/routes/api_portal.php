<?php

use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

// Supplier portal. Its own cookie session (see SupplierPortalAuth); no ERP route is reachable with it.
Route::prefix('portal')->group(function () {
    Route::post('/auth/code', [PortalController::class, 'requestCode'])->middleware('throttle:10,1');
    Route::post('/auth/verify', [PortalController::class, 'verify'])->middleware('throttle:20,1');

    Route::middleware('supplier.auth')->group(function () {
        Route::get('/me', [PortalController::class, 'me']);
        Route::post('/auth/logout', [PortalController::class, 'logout']);
        Route::get('/requests', [PortalController::class, 'requests']);
        Route::get('/requests/{leadId}', [PortalController::class, 'show']);
        Route::post('/requests/{leadId}/quote', [PortalController::class, 'quote'])->middleware('throttle:30,1');
        Route::post('/requests/{leadId}/decline', [PortalController::class, 'decline'])->middleware('throttle:30,1');
        Route::post('/requests/{leadId}/messages', [PortalController::class, 'message'])->middleware('throttle:30,1');
        Route::get('/files/{id}', [PortalController::class, 'download']);
    });
});
