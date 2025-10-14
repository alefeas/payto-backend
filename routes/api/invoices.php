<?php

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InvoiceController::class, 'index']);
Route::post('/', [InvoiceController::class, 'store']);
Route::post('/validate-afip', [InvoiceController::class, 'validateWithAfip']);
Route::get('/{id}', [InvoiceController::class, 'show']);
Route::post('/{id}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/{id}/attachment', [InvoiceController::class, 'uploadAttachment']);
Route::get('/{id}/attachment', [InvoiceController::class, 'downloadAttachment']);
Route::delete('/{id}/attachment', [InvoiceController::class, 'deleteAttachment']);
Route::delete('/{id}', [InvoiceController::class, 'destroy']);
