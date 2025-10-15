<?php

use App\Http\Controllers\Api\AfipVerificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Validate CUIT with AFIP
    Route::post('/validate-cuit', [AfipVerificationController::class, 'validateCuit']);
    
    // Company verification
    Route::post('/companies/{company}/verify-certificate', [AfipVerificationController::class, 'verifyCertificate']);
    Route::get('/companies/{company}/verification-status', [AfipVerificationController::class, 'getVerificationStatus']);
});
