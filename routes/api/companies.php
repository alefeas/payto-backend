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
    
    // Sales points routes
    Route::get('/companies/{companyId}/sales-points', [App\Http\Controllers\Api\SalesPointController::class, 'index']);
    Route::post('/companies/{companyId}/sales-points', [App\Http\Controllers\Api\SalesPointController::class, 'store']);
    Route::put('/companies/{companyId}/sales-points/{salesPointId}', [App\Http\Controllers\Api\SalesPointController::class, 'update']);
    Route::delete('/companies/{companyId}/sales-points/{salesPointId}', [App\Http\Controllers\Api\SalesPointController::class, 'destroy']);
    
    // Client routes
    Route::get('/companies/{companyId}/clients', [App\Http\Controllers\Api\ClientController::class, 'index']);
    Route::post('/companies/{companyId}/clients', [App\Http\Controllers\Api\ClientController::class, 'store']);
    Route::put('/companies/{companyId}/clients/{clientId}', [App\Http\Controllers\Api\ClientController::class, 'update']);
    Route::delete('/companies/{companyId}/clients/{clientId}', [App\Http\Controllers\Api\ClientController::class, 'destroy']);
    
    // Supplier routes
    Route::get('/companies/{companyId}/suppliers', [App\Http\Controllers\Api\SupplierController::class, 'index']);
    Route::post('/companies/{companyId}/suppliers', [App\Http\Controllers\Api\SupplierController::class, 'store']);
    Route::put('/companies/{companyId}/suppliers/{id}', [App\Http\Controllers\Api\SupplierController::class, 'update']);
    Route::delete('/companies/{companyId}/suppliers/{id}', [App\Http\Controllers\Api\SupplierController::class, 'destroy']);
    
    // Invoice routes
    Route::prefix('/companies/{companyId}/invoices')->group(base_path('routes/api/invoices.php'));
    
    // Payment routes
    Route::get('/companies/{companyId}/payments', [App\Http\Controllers\Api\PaymentController::class, 'index']);
    Route::post('/companies/{companyId}/payments', [App\Http\Controllers\Api\PaymentController::class, 'store']);
    Route::put('/companies/{companyId}/payments/{paymentId}', [App\Http\Controllers\Api\PaymentController::class, 'update']);
    Route::delete('/companies/{companyId}/payments/{paymentId}', [App\Http\Controllers\Api\PaymentController::class, 'destroy']);
    Route::post('/companies/{companyId}/payments/{paymentId}/confirm', [App\Http\Controllers\Api\PaymentController::class, 'confirm']);
    Route::post('/companies/{companyId}/payments/generate-txt', [App\Http\Controllers\Api\PaymentController::class, 'generateTxt']);
    
    // Collection routes
    Route::get('/companies/{companyId}/collections', [App\Http\Controllers\Api\CollectionController::class, 'index']);
    Route::post('/companies/{companyId}/collections', [App\Http\Controllers\Api\CollectionController::class, 'store']);
    Route::put('/companies/{companyId}/collections/{collectionId}', [App\Http\Controllers\Api\CollectionController::class, 'update']);
    Route::post('/companies/{companyId}/collections/{collectionId}/confirm', [App\Http\Controllers\Api\CollectionController::class, 'confirm']);
    Route::post('/companies/{companyId}/collections/{collectionId}/reject', [App\Http\Controllers\Api\CollectionController::class, 'reject']);
});
