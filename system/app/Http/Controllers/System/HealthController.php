<?php

namespace App\Http\Controllers\System;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\HealthChecker;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System health (Admin only): role:admin on the route and the "view-health"
 * Gate here. Shows each check as OK, Warning or Problem with one plain
 * "What to do" line. The same checks run in the terminal with
 * php artisan census:health. Nothing here shows a password, the .env
 * contents or connection details (App\Services\HealthChecker).
 */
class HealthController extends Controller
{
    public function __invoke(HealthChecker $health): Response
    {
        Gate::authorize(Permission::ViewHealth->value);

        $checks = $health->checks();

        return Inertia::render('system/health', [
            'checks' => $checks,
            'overall' => HealthChecker::overall($checks),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
