<?php

use App\Http\Controllers\Api\BankAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies/{companyId}/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/companies/{companyId}/bank-accounts', [BankAccountController::class, 'store']);
    Route::put('/companies/{companyId}/bank-accounts/{accountId}', [BankAccountController::class, 'update']);
    Route::delete('/companies/{companyId}/bank-accounts/{accountId}', [BankAccountController::class, 'destroy']);
});
