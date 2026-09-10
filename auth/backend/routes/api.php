<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public.
Route::get('/v1/health', [HealthController::class, 'show']);
Route::post('/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Authenticated via the opaque auth_token cookie.
Route::middleware(['auth.token'])->group(function () {
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']);
    Route::get('/v1/auth/me', [AuthController::class, 'me']);

    // OWNER only.
    Route::middleware(['owner'])->group(function () {
        Route::get('/v1/users', [UserController::class, 'index']);
        Route::post('/v1/users', [UserController::class, 'store']);
        Route::get('/v1/users/{user}', [UserController::class, 'show']);
        Route::patch('/v1/users/{user}', [UserController::class, 'update']);
        Route::delete('/v1/users/{user}', [UserController::class, 'destroy']);
        Route::post('/v1/users/{user}/activate', [UserController::class, 'activate']);

        Route::get('/v1/audit-logs', [AuditLogController::class, 'index']);
    });
});
