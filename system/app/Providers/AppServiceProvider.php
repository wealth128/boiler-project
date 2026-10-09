<?php

namespace App\Providers;

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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
