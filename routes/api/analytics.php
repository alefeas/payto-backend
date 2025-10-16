<?php

use App\Http\Controllers\Api\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('companies/{companyId}')->group(function () {
        Route::get('/analytics/summary', [AnalyticsController::class, 'getSummary']);
        Route::get('/analytics/revenue-trend', [AnalyticsController::class, 'getRevenueTrend']);
        Route::get('/analytics/top-clients', [AnalyticsController::class, 'getTopClients']);
        Route::get('/analytics/pending-invoices', [AnalyticsController::class, 'getPendingInvoices']);
    });
});
