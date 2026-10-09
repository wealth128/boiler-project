<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Backup\BackupController;
use App\Http\Controllers\Encode\EncodeController;
use App\Http\Controllers\Encode\PatientSearchController;
use App\Http\Controllers\Encode\VisitController;
use App\Http\Controllers\Records\PatientRecordController;
use App\Http\Controllers\Records\VisitRecordController;
use App\Http\Controllers\Reports\MonthController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Settings\ListController;
use App\Http\Controllers\Trash\TrashController;
use App\Http\Controllers\Users\UserAccountController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route map (planning/setup-and-structure.md, section 3)
|--------------------------------------------------------------------------
|
| Login, logout and the starter kit's auth pages are registered by Fortify.
| Every route below requires a signed-in user. The "Who" comment on each
| group is the role rule from planning/rbac.md; the role: middleware that
| enforces it is added in Step 4 (RBAC). Detailed rules (same-day edit,
| closed month) go in Policies.
|
*/

Route::inertia('/', 'welcome')->name('home');

// Constraints shared by the routes below.
Route::pattern('period', '\d{4}-(0[1-9]|1[0-2])'); // e.g. 2026-09
Route::pattern('id', '[0-9]+');

Route::middleware('auth')->group(function () {
    // Starter kit placeholder home. Replaced by role-based landing pages in Step 4.
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // --- Encode ------------------------------------------------- Who: Encoder, Admin
    Route::get('encode', [EncodeController::class, 'index'])->name('encode.index');
    Route::get('patients/search', PatientSearchController::class)->name('patients.search'); // JSON
    Route::post('visits', [VisitController::class, 'store'])->name('visits.store');
    Route::put('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');

    // --- Records ------------------------------------------------ Who: Admin
    Route::get('records/visits', [VisitRecordController::class, 'index'])->name('records.visits');
    Route::get('records/patients', [PatientRecordController::class, 'index'])->name('records.patients');
    Route::delete('visits/{visit}', [VisitRecordController::class, 'destroy'])->name('visits.destroy');
    Route::delete('patients/{patient}', [PatientRecordController::class, 'destroy'])->name('patients.destroy');

    // --- Reports ------------------------------------------------ Who: Admin, CO (viewer)
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export/pdf', [ReportExportController::class, 'pdf'])->name('reports.export.pdf');
    Route::get('reports/export/excel', [ReportExportController::class, 'excel'])->name('reports.export.excel');

    // --- Months (close / reopen / delete) ----------------------- Who: Admin
    Route::post('months/{period}/close', [MonthController::class, 'close'])->name('months.close');
    Route::post('months/{period}/reopen', [MonthController::class, 'reopen'])->name('months.reopen');
    Route::delete('months/{period}', [MonthController::class, 'destroy'])->name('months.destroy');

    // --- Lists (dropdowns) -------------------------------------- Who: Admin
    Route::get('settings/lists', [ListController::class, 'index'])->name('lists.index');
    Route::whereIn('list', ['diagnoses', 'ranks', 'age-brackets'])->group(function () {
        Route::post('settings/{list}', [ListController::class, 'store'])->name('lists.store');
        Route::put('settings/{list}/{id}', [ListController::class, 'update'])->name('lists.update');
        Route::delete('settings/{list}/{id}', [ListController::class, 'destroy'])->name('lists.destroy');
    });

    // --- Users -------------------------------------------------- Who: Admin, System Admin
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/unlock', [UserAccountController::class, 'unlock'])->name('users.unlock');
    Route::post('users/{user}/reset-password', [UserAccountController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/toggle-active', [UserAccountController::class, 'toggleActive'])->name('users.toggle-active');

    // --- Audit log ---------------------------------------------- Who: Admin
    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');

    // --- Trash -------------------------------------------------- Who: Admin
    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::delete('trash', [TrashController::class, 'empty'])->name('trash.empty');
    Route::whereIn('type', ['visits', 'patients', 'users', 'diagnoses', 'ranks', 'age-brackets', 'months'])->group(function () {
        Route::post('trash/{type}/{id}/restore', [TrashController::class, 'restore'])->name('trash.restore');
        Route::delete('trash/{type}/{id}', [TrashController::class, 'destroy'])->name('trash.destroy');
    });

    // --- Backups ------------------------------------------------ Who: Admin
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups/run', [BackupController::class, 'run'])->name('backups.run');
});

require __DIR__.'/settings.php';
