<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']); // <--- NEW
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:sanctum');
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
//    Route::get('/user', [UserController::class, 'me'])->name('user.me');

    // Organization Management
    Route::prefix('organization')->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])->name('organization.show');
        Route::put('/', [OrganizationController::class, 'update'])->name('organization.update');
    });

});
