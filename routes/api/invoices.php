<?php

use Illuminate\Support\Facades\Route;

// Invoice routes will be implemented here
Route::get('/', function () {
    return response()->json(['message' => 'List invoices']);
});

Route::post('/', function () {
    return response()->json(['message' => 'Create invoice']);
});

Route::get('/{id}', function ($id) {
    return response()->json(['message' => 'Get invoice', 'id' => $id]);
});

Route::put('/{id}', function ($id) {
    return response()->json(['message' => 'Update invoice', 'id' => $id]);
});

Route::delete('/{id}', function ($id) {
    return response()->json(['message' => 'Delete invoice', 'id' => $id]);
});
