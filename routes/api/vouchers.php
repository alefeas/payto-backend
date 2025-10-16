<?php

use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('companies/{id}')->group(function () {
        // Voucher types
        Route::get('/vouchers/types', [VoucherController::class, 'getAvailableTypes']);
        
        // Compatible invoices for NC/ND
        Route::get('/vouchers/compatible-invoices', [VoucherController::class, 'getCompatibleInvoices']);
        
        // Invoice balance
        Route::get('/invoices/{invoiceId}/available-balance', [VoucherController::class, 'getInvoiceBalance']);
        
        // Create voucher
        Route::post('/vouchers', [VoucherController::class, 'store']);
    });
});
