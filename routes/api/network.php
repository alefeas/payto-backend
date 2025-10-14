<?php

use App\Http\Controllers\Api\CompanyConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('companies/{companyId}/network')->group(function () {
        Route::get('/', [CompanyConnectionController::class, 'index']);
        Route::get('/stats', [CompanyConnectionController::class, 'stats']);
        Route::get('/requests', [CompanyConnectionController::class, 'pendingRequests']);
        Route::post('/connect', [CompanyConnectionController::class, 'store']);
        Route::post('/requests/{connectionId}/accept', [CompanyConnectionController::class, 'accept']);
        Route::post('/requests/{connectionId}/reject', [CompanyConnectionController::class, 'reject']);
    });
});
