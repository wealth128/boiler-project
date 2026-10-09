<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * "My profile": name only. Username, role and office are set by Admin /
 * System Admin, and accounts have no email to manage.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/profile'));
    }

    public function test_name_can_be_updated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Juan Dela Cruz'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Juan Dela Cruz', $user->refresh()->name);
    }

    public function test_name_is_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_profile_update_ignores_username_and_role()
    {
        $user = User::factory()->create();
        $username = $user->username;

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Juan Dela Cruz',
                'username' => 'hacker',
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame($username, $user->username);
        $this->assertSame('encoder', $user->role->value);
    }

    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }
}
