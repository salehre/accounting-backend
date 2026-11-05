<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingSetupController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DetailAccountController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\YearEndController;
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

    // Chart of accounts
    Route::get('accounts', [AccountController::class, 'index'])
        ->middleware('permission:accounts.read');
    Route::post('accounts', [AccountController::class, 'store'])
        ->middleware('permission:accounts.manage');
    Route::patch('accounts/{id}', [AccountController::class, 'update'])
        ->whereNumber('id')->middleware('permission:accounts.manage');
    Route::delete('accounts/{id}', [AccountController::class, 'destroy'])
        ->whereNumber('id')->middleware('permission:accounts.manage');

    // Detail accounts (customers, suppliers, banks, cost centers...)
    Route::get('detail-accounts', [DetailAccountController::class, 'index'])
        ->middleware('permission:accounts.read');
    Route::post('detail-accounts', [DetailAccountController::class, 'store'])
        ->middleware('permission:accounts.manage');
    Route::patch('detail-accounts/{id}', [DetailAccountController::class, 'update'])
        ->whereNumber('id')->middleware('permission:accounts.manage');
    Route::delete('detail-accounts/{id}', [DetailAccountController::class, 'destroy'])
        ->whereNumber('id')->middleware('permission:accounts.manage');

    // Account types (asset, liability...) and the technical accounts used at year end
    Route::get('account-categories', [AccountingSetupController::class, 'categories'])
        ->middleware('permission:accounts.read');
    Route::put('account-categories/{id}', [AccountingSetupController::class, 'setCategory'])
        ->whereNumber('id')->middleware('permission:accounts.manage');
    Route::get('accounting-settings', [AccountingSetupController::class, 'settings'])
        ->middleware('permission:accounts.read');
    Route::put('accounting-settings', [AccountingSetupController::class, 'updateSettings'])
        ->middleware('permission:accounts.manage');

    // Fiscal years: accountants manage them, only administrators close, reopen and post year-end entries
    Route::get('fiscal-years', [FiscalYearController::class, 'index'])
        ->middleware('permission:entries.read');
    Route::post('fiscal-years', [FiscalYearController::class, 'store'])
        ->middleware('permission:fiscal-years.manage');
    Route::patch('fiscal-years/{id}', [FiscalYearController::class, 'update'])
        ->whereNumber('id')->middleware('permission:fiscal-years.manage');
    Route::delete('fiscal-years/{id}', [FiscalYearController::class, 'destroy'])
        ->whereNumber('id')->middleware('permission:fiscal-years.manage');
    Route::get('fiscal-years/{id}/close-check', [FiscalYearController::class, 'closeCheck'])
        ->whereNumber('id')->middleware('permission:fiscal-years.close');
    Route::post('fiscal-years/{id}/close', [FiscalYearController::class, 'close'])
        ->whereNumber('id')->middleware('permission:fiscal-years.close');
    Route::post('fiscal-years/{id}/reopen', [FiscalYearController::class, 'reopen'])
        ->whereNumber('id')->middleware('permission:fiscal-years.close');

    // Year-end entries: accountants prepare, administrators post and reverse
    Route::get('fiscal-years/{id}/year-end', [YearEndController::class, 'preview'])
        ->whereNumber('id')->middleware('permission:fiscal-years.manage');
    Route::post('fiscal-years/{id}/year-end/generate', [YearEndController::class, 'generate'])
        ->whereNumber('id')->middleware('permission:fiscal-years.manage');
    Route::delete('fiscal-years/{id}/year-end', [YearEndController::class, 'discard'])
        ->whereNumber('id')->middleware('permission:fiscal-years.manage');
    Route::post('fiscal-years/{id}/year-end/post', [YearEndController::class, 'post'])
        ->whereNumber('id')->middleware('permission:fiscal-years.close');
    Route::post('fiscal-years/{id}/year-end/reverse', [YearEndController::class, 'reverse'])
        ->whereNumber('id')->middleware('permission:fiscal-years.close');

    // Journal entries
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

    // Reports
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])
        ->middleware('permission:reports.read');
    Route::get('reports/ledger', [ReportController::class, 'ledger'])
        ->middleware('permission:reports.read');
    Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])
        ->middleware('permission:reports.read');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])
        ->middleware('permission:reports.read');
    Route::get('reports/journal', [ReportController::class, 'journal'])
        ->middleware('permission:reports.read');

    // Excel exports of the reports (a separate permission: the data leaves the system)
    Route::get('reports/trial-balance/export', [ReportExportController::class, 'trialBalance'])
        ->middleware('permission:reports.export');
    Route::get('reports/ledger/export', [ReportExportController::class, 'ledger'])
        ->middleware('permission:reports.export');
    Route::get('reports/income-statement/export', [ReportExportController::class, 'incomeStatement'])
        ->middleware('permission:reports.export');
    Route::get('reports/balance-sheet/export', [ReportExportController::class, 'balanceSheet'])
        ->middleware('permission:reports.export');
    Route::get('reports/journal/export', [ReportExportController::class, 'journal'])
        ->middleware('permission:reports.export');

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
