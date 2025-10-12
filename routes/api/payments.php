<?php

use Illuminate\Support\Facades\Route;

// Payment routes will be implemented here
Route::get('/', function () {
    return response()->json(['message' => 'List payments']);
});

Route::post('/', function () {
    return response()->json(['message' => 'Create payment']);
});

Route::get('/{id}', function ($id) {
    return response()->json(['message' => 'Get payment', 'id' => $id]);
});

Route::put('/{id}', function ($id) {
    return response()->json(['message' => 'Update payment', 'id' => $id]);
});

Route::delete('/{id}', function ($id) {
    return response()->json(['message' => 'Delete payment', 'id' => $id]);
});
