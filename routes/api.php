<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\WhatsappController;
// Removed the WhatsAppController import since we dropped Twilio/WhatsApp

// --- Public Authentication Routes ---

Route::get('/test-whatsapp-otp', [WhatsappController::class, 'sendOtp']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);

// --- Public Plan & Checkout Routes ---
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/plans/{plan}', [PlanController::class, 'show']);
Route::post('/checkout', [PaymentController::class, 'createCheckout']);

// --- Protected Routes (Require Valid Sanctum Token) ---
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::prefix('organization')->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])->name('organization.show');
        Route::put('/', [OrganizationController::class, 'update'])->name('organization.update');
    });

    Route::middleware('role:admin|org_manager')->group(function () {
        Route::apiResource('users', UserController::class);
    });

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('organizations', OrganizationController::class);
        Route::apiResource('plans', PlanController::class)->except(['index', 'show']);
    });

});
