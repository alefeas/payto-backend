<?php

use App\Http\Controllers\Api\AfipVerificationController;
use App\Http\Controllers\Api\AfipCertificateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Validate CUIT with AFIP
    Route::post('/validate-cuit', [AfipVerificationController::class, 'validateCuit']);
    
    // Company verification
    Route::post('/companies/{company}/verify-certificate', [AfipVerificationController::class, 'verifyCertificate']);
    Route::get('/companies/{company}/verification-status', [AfipVerificationController::class, 'getVerificationStatus']);
    
    // Certificate management
    Route::get('/companies/{company}/certificate', [AfipCertificateController::class, 'show']);
    Route::post('/companies/{company}/certificate/generate-csr', [AfipCertificateController::class, 'generateCSR']);
    Route::post('/companies/{company}/certificate/upload', [AfipCertificateController::class, 'uploadCertificate']);
    Route::post('/companies/{company}/certificate/upload-manual', [AfipCertificateController::class, 'uploadManual']);
    Route::post('/companies/{company}/certificate/test', [AfipCertificateController::class, 'testConnection']);
    Route::delete('/companies/{company}/certificate', [AfipCertificateController::class, 'destroy']);
    
    // Update tax condition from AFIP
    Route::post('/companies/{company}/update-tax-condition', [AfipCertificateController::class, 'updateTaxCondition']);
});
