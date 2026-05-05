<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatAppController;
Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-session', function () {
    return response()->json(['session' => session()->all()]);
});
//Route::post('/send-otp', [WhatsAppController::class, 'sendOtp']);
