<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\ChoosePasswordController;
use App\Http\Controllers\Auth\LandingController;
use App\Http\Controllers\Backup\BackupController;
use App\Http\Controllers\Encode\EncodeController;
use App\Http\Controllers\Encode\PatientSearchController;
use App\Http\Controllers\Encode\VisitController;
use App\Http\Controllers\Records\ImportController;
use App\Http\Controllers\Records\PatientRecordController;
use App\Http\Controllers\Records\VisitRecordController;
use App\Http\Controllers\Reports\MonthController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportPrintController;
use App\Http\Controllers\Reports\SignatureController;
use App\Http\Controllers\Settings\HospitalController;
use App\Http\Controllers\Settings\ListController;
use App\Http\Controllers\System\HealthController;
use App\Http\Controllers\Trash\TrashController;
use App\Http\Controllers\Users\UserAccountController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route map (planning/setup-and-structure.md, section 3)
|--------------------------------------------------------------------------
|
| Login, logout and password confirmation are registered by Fortify.
| Every route below requires a signed-in user, and each group lists the
| roles allowed in (role: middleware, planning/rbac.md). Other roles get
| the 403 page. The same rules exist as Gates (App\Enums\Permission) and
| in resources/js/lib/permissions.ts, which hides menu items.
|
| Per-record rules (own same-day edit, closed month, can't delete the
| Admin...) are checked in the Policies, called from the controllers.
|
*/

// Signed out: login page. Signed in: the role's landing page.
Route::get('/', LandingController::class)->name('home');

// Constraints shared by the routes below.
Route::pattern('period', '\d{4}-(0[1-9]|1[0-2])'); // e.g. 2026-09
// Whole numbers of at most 18 digits (fits a PHP integer): a longer one is a 404, not a type error.
Route::pattern('id', '[0-9]{1,18}');
Route::pattern('batch', '[0-9]{1,18}');

Route::middleware('auth')->group(function () {
    // --- Choose your new password -------------------------------- Everyone
    // After logging in with a temporary password (RequirePasswordChange).
    Route::get('choose-password', [ChoosePasswordController::class, 'edit'])->name('password.choose');
    Route::put('choose-password', [ChoosePasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.choose.update');

    // --- Encode ------------------------------------------------- Encoder, Admin
    Route::middleware('role:encoder,admin')->group(function () {
        Route::get('encode', [EncodeController::class, 'index'])->name('encode.index');
        Route::get('patients/search', PatientSearchController::class)->middleware('throttle:patient-search')->name('patients.search'); // JSON
        Route::post('visits', [VisitController::class, 'store'])->name('visits.store');
        Route::put('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
    });

    // --- Records ------------------------------------------------ Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('records/visits', [VisitRecordController::class, 'index'])->name('records.visits');
        Route::get('records/patients', [PatientRecordController::class, 'index'])->name('records.patients');
        Route::delete('visits/{visit}', [VisitRecordController::class, 'destroy'])->name('visits.destroy');
        Route::delete('patients/{patient}', [PatientRecordController::class, 'destroy'])->name('patients.destroy');

        // Downtime import: Records > Import (Step 6j). The template, the upload,
        // the confirm and the undo share one limit (10 a minute per user). The page
        // itself (and the paging of Past imports) has its own, 60 a minute.
        Route::get('records/import', [ImportController::class, 'index'])->middleware('throttle:import-page')->name('records.import');
        Route::middleware('throttle:import-actions')->group(function () {
            Route::get('records/import/template', [ImportController::class, 'template'])->name('records.import.template');
            Route::post('records/import', [ImportController::class, 'upload'])->name('records.import.upload');
            Route::post('records/import/confirm', [ImportController::class, 'confirm'])->name('records.import.confirm');
            Route::post('records/import/cancel', [ImportController::class, 'cancel'])->name('records.import.cancel');
            Route::post('records/import/{batch}/undo', [ImportController::class, 'undo'])->name('records.import.undo');
        });
    });

    // --- Reports ------------------------------------------------ Admin, Viewer (CO)
    Route::middleware('role:admin,viewer')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        // The page Print opens: this tab, or every category (?scope=all).
        // Print, PDF and Excel share one limit (20 a minute per user).
        Route::middleware('throttle:report-files')->group(function () {
            Route::get('reports/print', [ReportPrintController::class, 'show'])->name('reports.print');
            Route::get('reports/export/pdf', [ReportExportController::class, 'pdf'])->name('reports.export.pdf');
            Route::get('reports/export/excel', [ReportExportController::class, 'excel'])->name('reports.export.excel');
        });
        // Signature pictures frozen when the month was closed (private disk).
        // {span} is a month (2026-09), a quarter (2026-Q3) or a year (2026).
        Route::get('reports/signatures/{span}/{who}', [SignatureController::class, 'show'])
            ->where('span', '\d{4}(-(0[1-9]|1[0-2]|Q[1-4]))?')
            ->whereIn('who', ['prepared', 'noted'])
            ->name('reports.signature');
    });

    // --- Months (close / reopen / delete) ----------------------- Admin
    Route::middleware('role:admin')->group(function () {
        Route::post('months/{period}/close', [MonthController::class, 'close'])->name('months.close');
        Route::post('months/{period}/reopen', [MonthController::class, 'reopen'])->name('months.reopen');
        Route::delete('months/{period}', [MonthController::class, 'destroy'])->name('months.destroy');
    });

    // --- Lists (dropdowns) -------------------------------------- Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('settings/lists', [ListController::class, 'index'])->name('lists.index');
        Route::whereIn('list', ['diagnoses', 'ranks', 'age-brackets'])->group(function () {
            Route::post('settings/{list}', [ListController::class, 'store'])->name('lists.store');
            Route::put('settings/{list}/{id}', [ListController::class, 'update'])->name('lists.update');
            Route::delete('settings/{list}/{id}', [ListController::class, 'destroy'])->name('lists.destroy');
        });
        // Move up / Move down (rank seniority order).
        Route::put('settings/ranks/{id}/move', [ListController::class, 'move'])->name('lists.ranks.move');
    });

    // --- Hospital settings -------------------------------------- Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('settings/hospital', [HospitalController::class, 'edit'])->name('hospital.edit');
        Route::put('settings/hospital', [HospitalController::class, 'update'])->name('hospital.update');
        Route::delete('settings/hospital/logo', [HospitalController::class, 'destroyLogo'])->name('hospital.logo.destroy');
        Route::delete('settings/hospital/signature/{who}', [HospitalController::class, 'destroySignature'])
            ->whereIn('who', ['prepared', 'noted'])
            ->name('hospital.signature.destroy');
    });

    // The logo and signature pictures: signed-in Admin and Viewer (CO), who see
    // them on the report. They are kept outside public/ and only ever served here.
    Route::middleware('role:admin,viewer')->group(function () {
        Route::get('settings/hospital/logo', [HospitalController::class, 'logo'])->name('hospital.logo');
        Route::get('settings/hospital/signature/{who}', [HospitalController::class, 'signature'])
            ->whereIn('who', ['prepared', 'noted'])
            ->name('hospital.signature');
    });

    // --- Users -------------------------------------------------- Admin, System Admin
    Route::middleware('role:admin,system_admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('users/{user}/unlock', [UserAccountController::class, 'unlock'])->name('users.unlock');
        Route::post('users/{user}/reset-password', [UserAccountController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/toggle-active', [UserAccountController::class, 'toggleActive'])->name('users.toggle-active');
    });

    // --- Audit log ---------------------------------------------- Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');
    });

    // --- Trash -------------------------------------------------- Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
        Route::delete('trash', [TrashController::class, 'empty'])->name('trash.empty');
        Route::whereIn('type', ['visits', 'patients', 'users', 'diagnoses', 'ranks', 'age-brackets', 'months'])->group(function () {
            Route::post('trash/{type}/{id}/restore', [TrashController::class, 'restore'])->name('trash.restore');
            Route::delete('trash/{type}/{id}', [TrashController::class, 'destroy'])->name('trash.destroy');
        });
    });

    // --- Backups ------------------------------------------------ Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        // The three heavy actions share one limit (6 a minute per user).
        Route::post('backups/run', [BackupController::class, 'run'])->middleware('throttle:backup-actions')->name('backups.run');
        // Copy the latest backup to a USB drive plugged into the server, and test it by loading it into a scratch database.
        Route::post('backups/usb-copy', [BackupController::class, 'usbCopy'])->middleware('throttle:backup-actions')->name('backups.usb-copy');
        Route::post('backups/test-restore', [BackupController::class, 'testRestore'])->middleware('throttle:backup-actions')->name('backups.test-restore');
        // Mark the weekly USB copy / monthly test restore as done (a reminder only).
        Route::post('backups/marks/{type}', [BackupController::class, 'mark'])
            ->whereIn('type', ['usb-copy', 'test-restore'])
            ->name('backups.mark');
    });

    // --- System health ------------------------------------------ Admin
    Route::middleware('role:admin')->group(function () {
        Route::get('system/health', HealthController::class)->name('system.health');
    });
});

require __DIR__.'/settings.php';

// Unknown URLs get the friendly 404 page. As a route, it runs the web
// middleware, so the page knows whether someone is signed in. Any method,
// so a POST to an unknown URL is a 404 too, not "method not allowed".
Route::any('{fallbackPlaceholder}', fn () => abort(404))
    ->where('fallbackPlaceholder', '.*')
    ->fallback();
