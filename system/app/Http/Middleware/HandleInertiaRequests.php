<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupMonitor;
use App\Services\HospitalSettings;
use App\Services\SchedulerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Session key of the value shared as auth.loginAt. */
    public const LOGIN_AT_KEY = 'login_at';

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $this->authUser($request),
                'loginAt' => $this->loginAt($request),
            ],
            'hospital' => fn (): array => $this->hospital($request),
            // Backup warnings at the top of every page: Admin only.
            'backupAlerts' => fn (): array => $request->user()?->role === Role::Admin ? app(BackupMonitor::class)->alerts() : [],
            // Red bar when the scheduler stopped (no backup, no 8 PM sign-out): Admin only.
            'schedulerAlert' => fn (): ?string => $request->user()?->role === Role::Admin ? app(SchedulerHeartbeat::class)->alert() : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** The sign-in time with a random tail, so two sign-ins never share a value. */
    public static function newLoginAt(): string
    {
        return now()->format('Y-m-d\TH:i:s.u').'-'.Str::lower(Str::random(6));
    }

    /**
     * A value that is new on every sign-in, or null when nobody is signed in.
     * The browser stores it with a closed red warning bar: a different value
     * means a new sign-in, so the bar shows again. A session that began
     * before this existed gets its first value now.
     */
    private function loginAt(Request $request): ?string
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return null;
        }

        $session = $request->session();

        if (! $session->has(self::LOGIN_AT_KEY)) {
            $session->put(self::LOGIN_AT_KEY, self::newLoginAt());
        }

        return (string) $session->get(self::LOGIN_AT_KEY);
    }

    /**
     * Only what the menu and top bar need. The rest of the user record
     * (lock counter, delete info...) never reaches the browser.
     *
     * @return array{id: int, name: string, username: string, role: string, office: string}|null
     */
    private function authUser(Request $request): ?array
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'office' => $user->office->value,
        ];
    }

    /**
     * Hospital name and subtitle for the top bar and login screen, read in
     * one query from the settings table (seeded by SettingSeeder).
     *
     * @return array{name: string, subtitle: string, logo_url: string|null, login_logo_url: string|null}
     */
    private function hospital(Request $request): array
    {
        $values = Setting::query()
            ->whereIn('key', ['hospital_name', 'hospital_subtitle'])
            ->pluck('value', 'key');

        $pictures = app(HospitalSettings::class);
        $user = $request->user();

        return [
            'name' => $values->get('hospital_name') ?: '[Hospital Name]',
            'subtitle' => $values->get('hospital_subtitle') ?: '',
            // Top bar logo, for every signed-in role: the signed-in route for the roles that may load it
            // (Admin, Viewer), the public logo-only route for the others (Encoder, System Admin).
            'logo_url' => $user === null
                ? null
                : (Gate::forUser($user)->allows(Permission::ViewReports->value)
                    ? $pictures->imageUrl('logo')
                    : $pictures->publicLogoUrl()),
            // Login page logo: from the public, logo-only route. Null when no logo is set.
            'login_logo_url' => $pictures->publicLogoUrl(),
        ];
    }
}
