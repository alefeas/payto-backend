<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InvoiceController::class, 'index']);
Route::get('/next-number', [InvoiceController::class, 'getNextNumber']);
Route::delete('/delete-all', [InvoiceController::class, 'deleteAll']);
Route::post('/', [InvoiceController::class, 'store']);
Route::post('/received', [InvoiceController::class, 'storeReceived']);
Route::post('/validate-afip', [InvoiceController::class, 'validateWithAfip']);
Route::post('/sync-from-afip', [InvoiceController::class, 'syncFromAfip']);
Route::post('/download-bulk', [InvoiceController::class, 'downloadBulk']);
Route::get('/{id}', [InvoiceController::class, 'show']);
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
