<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Step 3 login rules (CLAUDE.md, planning/rbac.md): username + password,
 * lock after 5 wrong passwords, deactivated / locked / deleted accounts
 * refused, login, wrong password, lock and logout written to audit_logs,
 * no public sign-up.
 * Factory users have the password "password".
 */

const LOCKED_MESSAGE = 'This account is locked after 5 wrong passwords. Ask the Admin or System Admin to unlock it.';

function login(string $username, string $password = 'password')
{
    return test()->from(route('login'))->post(route('login.store'), [
        'username' => $username,
        'password' => $password,
    ]);
}

// --- Login screen -------------------------------------------------------

it('shows the login screen with a username field', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

// --- Correct login ------------------------------------------------------

it('logs in with the correct username and password', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    // Encoders land on the Encode page (tests/Feature/Rbac/LandingPageTest.php).
    login('er.cruz')->assertRedirect(route('encode.index', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('ignores capital letters and spaces around the username', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    login('  ER.Cruz ');

    $this->assertAuthenticatedAs($user);
});

it('does not log in by email', function () {
    User::factory()->create(['username' => 'er.cruz']);

    $this->post(route('login.store'), ['email' => 'er.cruz@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('resets the wrong-password counter after a successful login', function () {
    $user = User::factory()->create(['username' => 'er.cruz', 'failed_attempts' => 3]);

    login('er.cruz');

    $this->assertAuthenticated();
    expect($user->fresh()->failed_attempts)->toBe(0);
});

it('writes user.login to the audit log on success', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    login('er.cruz');

    $entry = AuditLog::sole();
    expect($entry->action)->toBe('user.login')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->subject_type)->toBe('users')
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->ip_address)->toBe('127.0.0.1');
});

// --- Wrong password count -----------------------------------------------

it('counts wrong passwords and shows the attempts left', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    foreach ([4 => '4 attempts', 3 => '3 attempts', 2 => '2 attempts', 1 => '1 attempt'] as $left => $text) {
        login('er.cruz', 'wrong-password')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'username' => "Wrong username or password. {$text} left before the account locks.",
            ]);

        expect($user->fresh()->failed_attempts)->toBe(5 - $left);
    }

    $this->assertGuest();
    expect($user->fresh()->locked_at)->toBeNull()
        ->and(AuditLog::where('action', 'user.login_failed')->count())->toBe(4)
        ->and(AuditLog::where('action', 'user.locked')->exists())->toBeFalse();
});

it('writes user.login_failed to the audit log for a wrong password', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    login('er.cruz', 'wrong-password');
    login('er.cruz', 'wrong-password');

    $entries = AuditLog::orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('action')->unique()->all())->toBe(['user.login_failed'])
        ->and($entries->pluck('changes')->all())->toBe([['failed_attempts' => 1], ['failed_attempts' => 2]])
        ->and($entries[0]->user_id)->toBe($user->id)
        ->and($entries[0]->subject_type)->toBe('users')
        ->and($entries[0]->subject_id)->toBe($user->id)
        ->and($entries[0]->ip_address)->toBe('127.0.0.1');
});

it('writes nothing to the audit log for an unknown username', function () {
    login('nobody', 'wrong-password');

    expect(AuditLog::count())->toBe(0);
});

it('gives an unknown username the same message without a count', function () {
    login('nobody', 'password')
        ->assertSessionHasErrors(['username' => 'Wrong username or password.']);

    $this->assertGuest();
});

it('keeps the username but clears the password after a failed login', function () {
    User::factory()->create(['username' => 'er.cruz']);

    login('er.cruz', 'wrong-password');

    expect(session()->getOldInput('username'))->toBe('er.cruz')
        ->and(session()->getOldInput('password'))->toBeNull();
});

// --- Lock at 5 ----------------------------------------------------------

it('locks the account on the 5th wrong password', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    foreach (range(1, 4) as $try) {
        login('er.cruz', 'wrong-password');
    }

    login('er.cruz', 'wrong-password')
        ->assertSessionHasErrors(['username' => LOCKED_MESSAGE]);

    $user->refresh();
    expect($user->failed_attempts)->toBe(5)
        ->and($user->locked_at)->not->toBeNull();
    $this->assertGuest();
});

it('writes user.locked to the audit log once', function () {
    $user = User::factory()->create(['username' => 'er.cruz']);

    foreach (range(1, 7) as $try) {
        login('er.cruz', 'wrong-password');
    }

    // 5 counted wrong passwords, then one lock. Tries 6 and 7 hit the lock
    // before the password is checked, so they are not counted again.
    expect(AuditLog::where('action', 'user.login_failed')->count())->toBe(5);

    $entry = AuditLog::where('action', 'user.locked')->sole();
    expect($entry->action)->toBe('user.locked')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->subject_type)->toBe('users')
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->changes)->toBe(['failed_attempts' => 5])
        ->and($entry->ip_address)->toBe('127.0.0.1');
});

// --- Locked, deactivated and deleted accounts ---------------------------

it('refuses a locked user even with the correct password', function () {
    $user = User::factory()->locked()->create(['username' => 'er.cruz']);

    login('er.cruz')->assertSessionHasErrors(['username' => LOCKED_MESSAGE]);

    $this->assertGuest();
    expect($user->fresh()->failed_attempts)->toBe(5)
        ->and($user->fresh()->locked_at)->not->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

it('lets a user in again after the lock is cleared', function () {
    $user = User::factory()->locked()->create(['username' => 'er.cruz']);
    $user->forceFill(['failed_attempts' => 0, 'locked_at' => null])->save(); // what "Unlock" will do

    login('er.cruz');

    $this->assertAuthenticatedAs($user);
});

it('refuses a deactivated user even with the correct password', function () {
    User::factory()->inactive()->create(['username' => 'er.cruz']);

    login('er.cruz')->assertSessionHasErrors([
        'username' => 'This account is deactivated. Ask the Admin or System Admin.',
    ]);

    $this->assertGuest();
    expect(AuditLog::count())->toBe(0);
});

it('still counts wrong passwords on a deactivated account', function () {
    $user = User::factory()->inactive()->create(['username' => 'er.cruz']);

    login('er.cruz', 'wrong-password')->assertSessionHasErrors([
        'username' => 'Wrong username or password. 4 attempts left before the account locks.',
    ]);

    expect($user->fresh()->failed_attempts)->toBe(1);
});

it('refuses a deleted (in Trash) user even with the correct password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['username' => 'er.cruz']);
    $user->moveToTrash($admin, 'Left the hospital');

    login('er.cruz')->assertSessionHasErrors(['username' => 'Wrong username or password.']);

    $this->assertGuest();
    expect(User::withTrashed()->find($user->id)->failed_attempts)->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

// --- No public sign-up or reset by email --------------------------------

it('has no /register route', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Someone',
        'username' => 'someone',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::where('username', 'someone')->exists())->toBeFalse();
});

it('has no forgot-password pages', function () {
    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', ['username' => 'er.cruz'])->assertNotFound();
});

// --- Logout and throttle ------------------------------------------------

it('logs out and writes user.logout to the audit log', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();

    $entry = AuditLog::sole();
    expect($entry->action)->toBe('user.logout')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->subject_type)->toBe('users')
        ->and($entry->subject_id)->toBe($user->id);
});

it('writes nothing when a signed-out visitor posts to logout', function () {
    $this->post(route('logout'))->assertRedirect(route('login'));

    expect(AuditLog::count())->toBe(0);
});

it('lets the 6th try through the throttle so the lock message shows', function () {
    // 10 per minute per username + IP, above the 5-wrong-password lock.
    RateLimiter::increment(md5('login'.implode('|', ['er.cruz', '127.0.0.1'])), amount: 9);

    login('er.cruz', 'wrong-password')->assertStatus(302);
});

it('rate limits after 10 tries a minute', function () {
    RateLimiter::increment(md5('login'.implode('|', ['er.cruz', '127.0.0.1'])), amount: 10);

    login('er.cruz', 'wrong-password')->assertTooManyRequests();
});
