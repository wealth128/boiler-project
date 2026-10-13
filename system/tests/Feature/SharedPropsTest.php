<?php

use App\Models\Setting;
use App\Models\User;

/*
 * Props every page receives (HandleInertiaRequests). The top bar and the
 * login panel show the hospital name from the settings table.
 */

it('shares the hospital name and subtitle from settings', function () {
    Setting::create(['key' => 'hospital_name', 'value' => 'AFP General Hospital']);
    Setting::create(['key' => 'hospital_subtitle', 'value' => 'Armed Forces of the Philippines Medical Facility']);

    $this->actingAs(User::factory()->create())
        ->get('/encode')
        ->assertInertia(fn ($page) => $page
            ->where('hospital.name', 'AFP General Hospital')
            ->where('hospital.subtitle', 'Armed Forces of the Philippines Medical Facility'));
});

it('falls back to a placeholder hospital name when settings are empty', function () {
    $this->actingAs(User::factory()->create())
        ->get('/encode')
        ->assertInertia(fn ($page) => $page
            ->where('hospital.name', '[Hospital Name]')
            ->where('hospital.subtitle', ''));
});

it('shares the hospital name on the login page too', function () {
    Setting::create(['key' => 'hospital_name', 'value' => 'AFP General Hospital']);

    $this->get('/login')
        ->assertInertia(fn ($page) => $page->where('hospital.name', 'AFP General Hospital'));
});

it('shares only id, name, username, role and office of the signed-in user', function () {
    // A locked account is signed out (EndSessionWhenAccessRemoved), so the
    // hidden fields are shown with a wrong-password count instead.
    $admin = User::factory()->admin()->create(['name' => 'Ana Reyes', 'username' => 'areyes', 'failed_attempts' => 3]);

    $this->actingAs($admin)
        ->get('/encode')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user', [
                'id' => $admin->id,
                'name' => 'Ana Reyes',
                'username' => 'areyes',
                'role' => 'admin',
                'office' => 'Admin',
            ]));
});

it('shares no user on the login page', function () {
    $this->get('/login')
        ->assertInertia(fn ($page) => $page->where('auth.user', null));
});
