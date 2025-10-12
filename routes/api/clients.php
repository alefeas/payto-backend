<?php

use Illuminate\Support\Facades\Route;

// Client routes will be implemented here
Route::get('/', function () {
    return response()->json(['message' => 'List clients']);
});

Route::post('/', function () {
    return response()->json(['message' => 'Create client']);
});

Route::get('/{id}', function ($id) {
    return response()->json(['message' => 'Get client', 'id' => $id]);
});

Route::put('/{id}', function ($id) {
    return response()->json(['message' => 'Update client', 'id' => $id]);
});

Route::delete('/{id}', function ($id) {
    return response()->json(['message' => 'Delete client', 'id' => $id]);
});
