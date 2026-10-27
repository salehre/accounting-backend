<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    DB::select('select 1');

    return ['ok' => true];
});

// Public: login
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);   // SPA (cookie session)
    Route::post('token', [AuthController::class, 'token']);   // mobile app (bearer token)
});

// Everything below requires a logged-in, active user
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // Every route here must also declare the permission it needs.
    Route::get('accounts', [AccountController::class, 'index'])
        ->middleware('permission:accounts.read');
});
