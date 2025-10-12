<?php

use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/companies/join', [CompanyController::class, 'join']);
    Route::get('/companies/{id}', [CompanyController::class, 'show']);
    Route::put('/companies/{id}', [CompanyController::class, 'update']);
    Route::post('/companies/{id}/regenerate-invite', [CompanyController::class, 'regenerateInvite']);
    Route::delete('/companies/{id}', [CompanyController::class, 'destroy']);
});
