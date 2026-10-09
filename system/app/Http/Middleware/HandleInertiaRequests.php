<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
            ],
            'hospital' => fn (): array => $this->hospital(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Only what the menu and top bar need. The rest of the user record
     * (lock counter, delete info, email...) never reaches the browser.
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
     * @return array{name: string, subtitle: string}
     */
    private function hospital(): array
    {
        $values = Setting::query()
            ->whereIn('key', ['hospital_name', 'hospital_subtitle'])
            ->pluck('value', 'key');

        return [
            'name' => $values->get('hospital_name') ?: '[Hospital Name]',
            'subtitle' => $values->get('hospital_subtitle') ?: '',
        ];
    }
}
