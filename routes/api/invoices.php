<?php

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InvoiceController::class, 'index']);
Route::post('/', [InvoiceController::class, 'store']);
Route::get('/{id}', [InvoiceController::class, 'show']);
Route::post('/{id}/cancel', [InvoiceController::class, 'cancel']);
Route::delete('/{id}', [InvoiceController::class, 'destroy']);
