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
    
    // Network routes
    require base_path('routes/api/network.php');
    
    // Tasks routes
    require base_path('routes/api/tasks.php');
    
    // IVA Book routes
    require base_path('routes/api/iva-book.php');
    
    // Voucher routes (NC, ND, Receipts, etc.)
    require base_path('routes/api/vouchers.php');
    
    // Analytics routes
    require base_path('routes/api/analytics.php');
    
    // Accounts Payable routes
    require base_path('routes/api/accounts-payable.php');
});
