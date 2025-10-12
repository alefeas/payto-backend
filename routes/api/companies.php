<?php

use Illuminate\Support\Facades\Route;

// Company routes will be implemented here
Route::get('/', function () {
    return response()->json(['message' => 'List companies']);
});

Route::post('/', function () {
    return response()->json(['message' => 'Create company']);
});

Route::get('/{id}', function ($id) {
    return response()->json(['message' => 'Get company', 'id' => $id]);
});

Route::put('/{id}', function ($id) {
    return response()->json(['message' => 'Update company', 'id' => $id]);
});

Route::delete('/{id}', function ($id) {
    return response()->json(['message' => 'Delete company', 'id' => $id]);
});
