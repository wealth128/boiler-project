<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/*
 * Starter-kit login tests. Login still uses the email field until Step 3
 * switches Fortify to username.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_the_login_throttle_allows_more_tries_than_the_account_lock()
    {
        // The account locks after 5 wrong passwords (Step 3), so the throttle
        // must not stop the 6th try with a generic "too many attempts".
        $user = User::factory()->create();
        $key = md5('login'.implode('|', [$user->email, '127.0.0.1']));

        RateLimiter::increment($key, amount: 9);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(302);
    }

    public function test_users_are_rate_limited_after_ten_tries_per_minute()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 10);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
