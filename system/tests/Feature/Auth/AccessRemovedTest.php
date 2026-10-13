<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Http\Middleware\EndSessionWhenAccessRemoved;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
 * Removing access works right away (planning/rbac.md): a signed-in user who
 * is deactivated, locked or moved to Trash is signed out on their next
 * click, with the login form's message, and "user.session_ended" is logged.
 *
 * The account is changed in the database only, then the request loads the
 * user from the session the way a real browser request does.
 */

function signedInBySession(User $user)
{
    return test()->withSession([Auth::guard('web')->getName() => $user->id]);
}

it('keeps an active account signed in', function () {
    $user = userWithRole('encoder');

    signedInBySession($user)->get('/encode')->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('ends the session of a user who was deactivated', function () {
    $user = userWithRole('encoder');
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    signedInBySession($user)->get('/encode')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['username' => AuthenticateUser::DEACTIVATED_MESSAGE]);

    $this->assertGuest();

    $entry = AuditLog::where('action', 'user.session_ended')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->changes)->toEqual(['reason' => 'deactivated']);
});

it('ends the session of a user whose account was locked', function () {
    $user = userWithRole('admin');
    DB::table('users')->where('id', $user->id)->update(['failed_attempts' => 5, 'locked_at' => now()]);

    signedInBySession($user)->get('/reports')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['username' => AuthenticateUser::lockedMessage()]);

    $this->assertGuest();
    expect(AuditLog::where('action', 'user.session_ended')->sole()->changes)->toEqual(['reason' => 'locked']);
});

it('ends the session of a user who was moved to Trash', function () {
    $user = userWithRole('viewer');
    $user->moveToTrash(userWithRole('admin'), 'Left the hospital');

    signedInBySession($user)->get('/reports')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['username' => EndSessionWhenAccessRemoved::REMOVED_MESSAGE]);

    $this->assertGuest();

    $entry = AuditLog::where('action', 'user.session_ended')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->changes)->toEqual(['reason' => 'trashed']);
});

it('ends the session on a form submit too', function () {
    $user = userWithRole('encoder');
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    signedInBySession($user)->post('/visits', [])->assertRedirect(route('login'));

    $this->assertGuest();
});

it('answers a JSON request with 401 and the message', function () {
    $user = userWithRole('encoder');
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    signedInBySession($user)->getJson('/patients/search?q=cruz')
        ->assertUnauthorized()
        ->assertJson(['message' => AuthenticateUser::DEACTIVATED_MESSAGE]);
});

it('does not write user.logout, which means the user clicked Log out', function () {
    $user = userWithRole('encoder');
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    signedInBySession($user)->get('/encode');

    expect(AuditLog::where('action', 'user.logout')->exists())->toBeFalse();
});

it('leaves signed-out visitors alone', function () {
    $this->get('/login')->assertOk();

    expect(AuditLog::count())->toBe(0);
});
