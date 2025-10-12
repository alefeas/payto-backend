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
    Route::prefix('auth')->group(function () {
        require base_path('routes/api/auth.php');
    });
    
    // Company routes
    require base_path('routes/api/companies.php');
    
    // Bank account routes
    require base_path('routes/api/bank_accounts.php');
});
