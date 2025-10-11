<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// API v1 routes
Route::prefix('v1')->group(function () {
    // Auth routes
    Route::prefix('auth')->group(base_path('routes/api/auth.php'));
    
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('companies')->group(base_path('routes/api/companies.php'));
        Route::prefix('invoices')->group(base_path('routes/api/invoices.php'));
        Route::prefix('payments')->group(base_path('routes/api/payments.php'));
        Route::prefix('clients')->group(base_path('routes/api/clients.php'));
        Route::prefix('network')->group(base_path('routes/api/network.php'));
    });
});
