<?php

use App\Http\Controllers\Api\AccountsReceivableController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Accounts Receivable Invoices
    Route::get('/companies/{companyId}/accounts-receivable/invoices', [AccountsReceivableController::class, 'getInvoices']);
    
    // Accounts Receivable Balances
    Route::get('/companies/{companyId}/accounts-receivable/balances', [AccountsReceivableController::class, 'getBalances']);
});


