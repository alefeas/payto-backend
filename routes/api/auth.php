<?php

use Illuminate\Support\Facades\Route;

// Auth routes will be implemented here
Route::post('/register', function () {
    return response()->json(['message' => 'Register endpoint']);
});

Route::post('/login', function () {
    return response()->json(['message' => 'Login endpoint']);
});

Route::post('/logout', function () {
    return response()->json(['message' => 'Logout endpoint']);
})->middleware('auth:sanctum');
