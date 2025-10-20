<?php

use App\Http\Controllers\Api\AccountsPayableController;
use App\Http\Controllers\Api\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Accounts Payable Dashboard
    Route::get('/companies/{companyId}/accounts-payable/dashboard', [AccountsPayableController::class, 'getDashboard']);
    Route::get('/companies/{companyId}/accounts-payable/invoices', [AccountsPayableController::class, 'getInvoices']);
    Route::get('/companies/{companyId}/accounts-payable/suppliers/{supplierId}', [AccountsPayableController::class, 'getSupplierSummary']);
    Route::get('/companies/{companyId}/accounts-payable/default-retentions', [AccountsPayableController::class, 'getDefaultRetentions']);
    Route::post('/companies/{companyId}/accounts-payable/generate-txt', [AccountsPayableController::class, 'generatePaymentTxt']);
    
    // Supplier Payments
    Route::get('/companies/{companyId}/supplier-payments', [SupplierPaymentController::class, 'index']);
    Route::post('/companies/{companyId}/supplier-payments', [SupplierPaymentController::class, 'store']);
    Route::post('/companies/{companyId}/supplier-payments/{paymentId}/confirm', [SupplierPaymentController::class, 'confirm']);
    Route::get('/companies/{companyId}/supplier-payments/invoices/{invoiceId}/calculate-retentions', [SupplierPaymentController::class, 'calculateRetentions']);
    Route::post('/companies/{companyId}/supplier-payments/generate-txt', [SupplierPaymentController::class, 'generateTxt']);
});
