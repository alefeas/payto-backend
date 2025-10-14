<?php

use App\Http\Controllers\Api\UserTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tasks', [UserTaskController::class, 'index']);
    Route::post('/tasks', [UserTaskController::class, 'store']);
    Route::put('/tasks/{id}', [UserTaskController::class, 'update']);
    Route::delete('/tasks/{id}', [UserTaskController::class, 'destroy']);
});
