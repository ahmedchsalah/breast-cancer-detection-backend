<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-session', function () {
    return response()->json(['session' => session()->all()]);
});
