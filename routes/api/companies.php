<?php

use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/companies/join', [CompanyController::class, 'join']);
    Route::get('/companies/{id}', [CompanyController::class, 'show']);
    Route::put('/companies/{id}', [CompanyController::class, 'update']);
    Route::post('/companies/{id}/regenerate-invite', [CompanyController::class, 'regenerateInvite']);
    Route::delete('/companies/{id}', [CompanyController::class, 'destroy']);
    
    // Company members routes
    Route::get('/companies/{companyId}/members', [CompanyMemberController::class, 'index']);
    Route::put('/companies/{companyId}/members/{memberId}/role', [CompanyMemberController::class, 'updateRole']);
    Route::delete('/companies/{companyId}/members/{memberId}', [CompanyMemberController::class, 'destroy']);
    
    // Audit logs routes
    Route::get('/companies/{companyId}/audit-logs', [App\Http\Controllers\Api\AuditLogController::class, 'index']);
    
    // AFIP certificate routes
    Route::get('/companies/{companyId}/afip/certificate', [App\Http\Controllers\Api\AfipCertificateController::class, 'show']);
    Route::post('/companies/{companyId}/afip/certificate/generate-csr', [App\Http\Controllers\Api\AfipCertificateController::class, 'generateCSR']);
    Route::post('/companies/{companyId}/afip/certificate/upload', [App\Http\Controllers\Api\AfipCertificateController::class, 'uploadCertificate']);
    Route::post('/companies/{companyId}/afip/certificate/upload-manual', [App\Http\Controllers\Api\AfipCertificateController::class, 'uploadManual']);
    Route::post('/companies/{companyId}/afip/certificate/test', [App\Http\Controllers\Api\AfipCertificateController::class, 'testConnection']);
    Route::delete('/companies/{companyId}/afip/certificate', [App\Http\Controllers\Api\AfipCertificateController::class, 'destroy']);
    
    // Client routes
    Route::get('/companies/{companyId}/clients', [App\Http\Controllers\Api\ClientController::class, 'index']);
    Route::post('/companies/{companyId}/clients', [App\Http\Controllers\Api\ClientController::class, 'store']);
    Route::put('/companies/{companyId}/clients/{clientId}', [App\Http\Controllers\Api\ClientController::class, 'update']);
    Route::delete('/companies/{companyId}/clients/{clientId}', [App\Http\Controllers\Api\ClientController::class, 'destroy']);
});
