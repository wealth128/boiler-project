<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Middleware\EndSessionAtDailyCutoff;
use App\Models\AgeBracket;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Policies\TrashPolicy;
use App\Policies\UserPolicy;
use App\Policies\VisitPolicy;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('restore-from-trash', [TrashPolicy::class, 'restore']);
        Gate::define('delete-permanently', [TrashPolicy::class, 'forceDelete']);
    }

    /**
     * Audit entries for events that don't go through a model or a form.
     * Login, wrong password and lock are written by AuthenticateUser.
     */
    protected function configureAuditEvents(): void
    {
        // Login time, for the daily sign-out (EndSessionAtDailyCutoff).
        Event::listen(function (Login $event): void {
            if (request()->hasSession()) {
                request()->session()->put(EndSessionAtDailyCutoff::SESSION_KEY, now()->getTimestamp());
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
            'report_snapshots' => ReportSnapshot::class,
            'backup_runs' => BackupRun::class,
            'settings' => Setting::class,
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
