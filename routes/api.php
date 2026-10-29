<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\UserController;
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

    // Every route below must also declare the permission it needs
    // (a test fails if one is missing or uses a name that is not in App\Support\Permissions).
    Route::get('accounts', [AccountController::class, 'index'])
        ->middleware('permission:accounts.read');
    Route::get('detail-accounts', [AccountController::class, 'detailAccounts'])
        ->middleware('permission:accounts.read');
    Route::get('fiscal-years', [FiscalYearController::class, 'index'])
        ->middleware('permission:entries.read');

    Route::get('entries', [JournalEntryController::class, 'index'])
        ->middleware('permission:entries.read');
    Route::post('entries', [JournalEntryController::class, 'store'])
        ->middleware('permission:entries.write');
    Route::get('entries/{id}', [JournalEntryController::class, 'show'])
        ->whereNumber('id')->middleware('permission:entries.read');
    Route::delete('entries/{id}', [JournalEntryController::class, 'destroy'])
        ->whereNumber('id')->middleware('permission:entries.write');
    Route::post('entries/{id}/post', [JournalEntryController::class, 'post'])
        ->whereNumber('id')->middleware('permission:entries.post');
    Route::post('entries/{id}/reverse', [JournalEntryController::class, 'reverse'])
        ->whereNumber('id')->middleware('permission:entries.reverse');

    // User management (administrators only)
    Route::get('roles', [UserController::class, 'roles'])
        ->middleware('permission:users.manage');
    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:users.manage');
    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:users.manage');
    Route::get('users/{id}', [UserController::class, 'show'])
        ->whereNumber('id')->middleware('permission:users.manage');
    Route::patch('users/{id}', [UserController::class, 'update'])
        ->whereNumber('id')->middleware('permission:users.manage');
    Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword'])
        ->whereNumber('id')->middleware('permission:users.manage');
});
