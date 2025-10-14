<?php

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InvoiceController::class, 'index']);
Route::post('/', [InvoiceController::class, 'store']);
Route::post('/received', [InvoiceController::class, 'storeReceived']);
Route::post('/validate-afip', [InvoiceController::class, 'validateWithAfip']);
Route::get('/{id}', [InvoiceController::class, 'show']);
Route::post('/{id}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/{id}/attachment', [InvoiceController::class, 'uploadAttachment']);
Route::get('/{id}/attachment', [InvoiceController::class, 'downloadAttachment']);
Route::delete('/{id}/attachment', [InvoiceController::class, 'deleteAttachment']);
Route::get('/{invoiceId}/payments', [App\Http\Controllers\Api\InvoicePaymentController::class, 'index']);
Route::post('/{invoiceId}/payments', [App\Http\Controllers\Api\InvoicePaymentController::class, 'store']);
Route::delete('/{invoiceId}/payments/{paymentId}', [App\Http\Controllers\Api\InvoicePaymentController::class, 'destroy']);
Route::delete('/{id}', [InvoiceController::class, 'destroy']);
