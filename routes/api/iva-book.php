<?php

use App\Http\Controllers\Api\IvaBookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies/{company}/iva-book/sales', [IvaBookController::class, 'getSalesBook']);
    Route::get('/companies/{company}/iva-book/purchases', [IvaBookController::class, 'getPurchasesBook']);
    Route::get('/companies/{company}/iva-book/summary', [IvaBookController::class, 'getSummary']);
    // Exportaciones AFIP (requieren certificado activo)
    Route::middleware('validate.afip.certificate')->group(function () {
        Route::get('/companies/{company}/iva-book/export/sales', [IvaBookController::class, 'exportSalesAfip']);
        Route::get('/companies/{company}/iva-book/export/purchases', [IvaBookController::class, 'exportPurchasesAfip']);
    });
});
