<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InvoiceController::class, 'index']);
Route::get('/next-number', [InvoiceController::class, 'getNextNumber']);
Route::get('/associable-for-emit', [InvoiceController::class, 'getAssociableInvoicesForEmit']);
Route::get('/associable-for-received', [InvoiceController::class, 'getAssociableInvoicesForReceived']);
Route::get('/associable-for-issued', [InvoiceController::class, 'getAssociableInvoicesForIssued']);
Route::delete('/delete-all', [InvoiceController::class, 'deleteAll']);
// Emisión de facturas electrónicas (requiere certificado AFIP)
Route::middleware('validate.afip.certificate')->post('/', [InvoiceController::class, 'store']);
Route::post('/manual-issued', [InvoiceController::class, 'storeManualIssued']);
Route::post('/manual-received', [InvoiceController::class, 'storeManualReceived']);
Route::post('/received', [InvoiceController::class, 'storeReceived']);
// Rutas que requieren certificado AFIP activo
Route::middleware('validate.afip.certificate')->group(function () {
    Route::post('/validate-afip', [InvoiceController::class, 'validateWithAfip']);
    Route::post('/sync-from-afip', [InvoiceController::class, 'syncFromAfip']);
});
Route::post('/download-bulk', [InvoiceController::class, 'downloadBulk']);
Route::get('/{id}', [InvoiceController::class, 'show']);
Route::put('/{id}/synced', [InvoiceController::class, 'updateSyncedInvoice']);
Route::post('/{id}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/{id}/attachment', [InvoiceController::class, 'uploadAttachment']);
Route::get('/{id}/attachment', [InvoiceController::class, 'downloadAttachment']);
Route::delete('/{id}/attachment', [InvoiceController::class, 'deleteAttachment']);
Route::get('/{id}/pdf', [InvoiceController::class, 'downloadPDF']);
Route::get('/{id}/txt', [InvoiceController::class, 'downloadTXT']);
Route::get('/{invoiceId}/payments', [App\Http\Controllers\Api\InvoicePaymentController::class, 'index']);
Route::post('/{invoiceId}/payments', [App\Http\Controllers\Api\InvoicePaymentController::class, 'store']);
Route::delete('/{invoiceId}/payments/{paymentId}', [App\Http\Controllers\Api\InvoicePaymentController::class, 'destroy']);
Route::get('/{invoiceId}/approvals', [InvoiceApprovalController::class, 'getApprovals']);
Route::post('/{invoiceId}/approve', [InvoiceApprovalController::class, 'approve']);
Route::post('/{invoiceId}/reject', [InvoiceApprovalController::class, 'reject']);
Route::post('/{id}/archive', [InvoiceController::class, 'archive']);
Route::delete('/{id}', [InvoiceController::class, 'destroy']);
