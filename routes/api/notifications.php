<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Company notifications
    Route::prefix('companies/{companyId}')->group(function () {
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    });
    
    // Individual notification actions
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
});
