<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Middleware\EndSessionAtDailyCutoff;
use App\Models\AgeBracket;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BackupMark;
use App\Models\TestRestoreRun;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\ImportBatch;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Observers\AuditObserver;
use App\Policies\TrashPolicy;
use App\Policies\UserPolicy;
use App\Policies\VisitPolicy;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuditEvents();
        $this->configureRateLimits();
    }

    /**
     * Limits per signed-in user, per minute (a 429 shows "Too many requests"):
     * - report-files: Print, PDF and Excel together (20). Each one counts
     *   every report, so this stops a stuck button or script, not normal use.
     * - patient-search: the Encode search box (60). It searches while typing.
     * - import-actions: the downtime import (template download, upload,
     *   confirm, undo) shares 10 a minute per user. Each reads or writes a
     *   whole file, so a stuck button must not pile them up.
     * - import-page: opening the Import page, its preview and the paging of
     *   Past imports (60 a minute per user). Light, and clicked through, so it
     *   has its own, higher limit.
     * - backup-actions: Run backup now, Run test restore now and Copy to USB
     *   share 6 a minute per user. Each is heavy (mysqldump, a restore, a copy),
     *   so a stuck button or a script must not queue them up.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('report-files', fn (Request $request): Limit => Limit::perMinute(20)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('backup-actions', fn (Request $request): Limit => Limit::perMinute(6)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('import-actions', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('import-page', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('patient-search', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
    }

    /**
     * Gates and Policies (planning/rbac.md). Routes are guarded by the
     * role: middleware; these answer the same questions inside controllers
     * and add the per-record rules.
     */
    protected function configureAuthorization(): void
    {
        // One Gate per page-level permission, e.g. "manage-users".
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $permission->allows($user->role));
        }

        Gate::policy(Visit::class, VisitPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Trash covers several models, so its checks are named Gates.
        Gate::define('move-to-trash', [TrashPolicy::class, 'moveToTrash']);
        Gate::define('delete-month-report', [TrashPolicy::class, 'deleteMonthReport']);
        Gate::define('restore-from-trash', [TrashPolicy::class, 'restore']);
        Gate::define('delete-permanently', [TrashPolicy::class, 'forceDelete']);
    }

    /**
     * Audit entries for events that don't go through a model or a form.
     * Login, wrong password and lock are written by AuthenticateUser.
     */
    protected function configureAuditEvents(): void
    {
        // Create, update, Trash, restore and permanent delete of these.
        foreach ([Visit::class, Patient::class, User::class, Diagnosis::class, Rank::class, AgeBracket::class, MonthClosure::class] as $model) {
            $model::observe(AuditObserver::class);
        }

        // Login time, for the daily sign-out (EndSessionAtDailyCutoff), and a
        // value that is new on every login (shared as auth.loginAt: it brings
        // a closed red warning bar back at the next sign-in).
        Event::listen(function (Login $event): void {
            if (request()->hasSession()) {
                request()->session()->put(EndSessionAtDailyCutoff::SESSION_KEY, now()->getTimestamp());
                request()->session()->put(HandleInertiaRequests::LOGIN_AT_KEY, HandleInertiaRequests::newLoginAt());
            }
        });

        Event::listen(function (Logout $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->log('user.logout', $event->user, by: $event->user);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Short, stable names stored in audit_logs.subject_type (e.g. "visits")
        // instead of PHP class names.
        Relation::enforceMorphMap([
            'users' => User::class,
            'patients' => Patient::class,
            'visits' => Visit::class,
            'branches' => Branch::class,
            'ranks' => Rank::class,
            'diagnoses' => Diagnosis::class,
            'age_brackets' => AgeBracket::class,
            'month_closures' => MonthClosure::class,
            'month_deletions' => MonthDeletion::class,
            'import_batches' => ImportBatch::class,
            'report_snapshots' => ReportSnapshot::class,
            'backup_runs' => BackupRun::class,
            'backup_marks' => BackupMark::class,
            'test_restore_runs' => TestRestoreRun::class,
            'settings' => Setting::class,
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Same rule in every environment: at least 8 characters, with letters
        // and numbers. No uncompromised() check: the hospital network has
        // no internet.
        Password::defaults(fn (): Password => Password::min(8)->letters()->numbers());
    }
}
