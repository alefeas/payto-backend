<?php

use App\Http\Controllers\Api\AfipController;
use App\Http\Controllers\Api\AfipVerificationController;
use Illuminate\Support\Facades\Route;

// Rutas AFIP que requieren certificado activo
Route::middleware(['auth:sanctum', 'validate.afip.certificate'])->group(function () {
    
    // Validación de CUIT en padrón AFIP
    Route::post('/afip/validate-cuit', [AfipVerificationController::class, 'validateCuit']);
    
    // Verificación de certificado AFIP
    Route::post('/afip/companies/{companyId}/verify-certificate', [AfipVerificationController::class, 'verifyCertificate']);
    
    // Estado de verificación
    Route::get('/afip/companies/{companyId}/verification-status', [AfipVerificationController::class, 'getVerificationStatus']);
    
});

// Rutas AFIP que NO requieren certificado (para configuración inicial)
Route::middleware('auth:sanctum')->group(function () {
    
    // Estas rutas están en companies.php pero las documentamos aquí para referencia:
    // - GET /companies/{companyId}/afip/certificate (obtener certificado)
    // - POST /companies/{companyId}/afip/certificate/generate-csr (generar CSR)
    // - POST /companies/{companyId}/afip/certificate/upload (subir certificado)
    // - POST /companies/{companyId}/afip/certificate/upload-manual (subir manual)
    // - POST /companies/{companyId}/afip/certificate/test (probar conexión)
    // - DELETE /companies/{companyId}/afip/certificate (eliminar certificado)
    
});