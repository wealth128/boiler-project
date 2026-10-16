<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * A new password ends other logins (Step 6x):
 * - Admin / System Admin resets a user's password, or IT runs
 *   census:reset-admin-password: every session of that user ends.
 * - A user changes their own password (Settings > Security, or "Choose your
 *   new password"): their OTHER sessions end, the current one stays.
 * The app uses the database session driver; the tests use the array driver,
 * so they switch the setting on and put rows in the sessions table. The
 * requests then write their own session row too, so the checks look at the
 * rows made here, not at the whole table.
 */

beforeEach(function () {
    config(['session.driver' => 'database']);
});

/** A row in the sessions table, as the database driver writes it. */
function sessionRow(string $id, ?User $user): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user?->id,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Test browser',
        'payload' => 'x',
        'last_activity' => time(),
    ]);
}

function sessionExists(string $id): bool
{
    return DB::table('sessions')->where('id', $id)->exists();
}

/** The ids of the sessions the user has now. @return list<string> */
function sessionsOf(User $user): array
{
    return DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
}

// --- Reset by Admin or System Admin ---------------------------------------------------

it('ends every session of the user when a password is reset', function (string $resetterRole) {
    $encoder = userWithRole('encoder');
    $other = userWithRole('viewer');

    sessionRow('enc-pc-1', $encoder);
    sessionRow('enc-pc-2', $encoder);
    sessionRow('other-pc', $other);
    sessionRow('guest', null);

    $resetter = userWithRole($resetterRole);
    sessionRow('resetter-pc', $resetter);

    $this->actingAs($resetter)
        ->post("/users/{$encoder->id}/reset-password", [
            'password' => 'Reset-Pass-2026',
            'password_confirmation' => 'Reset-Pass-2026',
            'own_password' => 'password',
        ])
        ->assertRedirect('/users')
        ->assertSessionHasNoErrors();

    // Only the encoder's sessions are gone.
    expect(sessionExists('enc-pc-1'))->toBeFalse()
        ->and(sessionExists('enc-pc-2'))->toBeFalse()
        ->and(sessionsOf($encoder))->toBe([])
        ->and(sessionExists('other-pc'))->toBeTrue()
        ->and(sessionExists('guest'))->toBeTrue()
        ->and(sessionExists('resetter-pc'))->toBeTrue();
})->with(['admin', 'system_admin']);

it('still audits the reset as before', function () {
    $encoder = userWithRole('encoder');
    sessionRow('enc-pc-1', $encoder);

    $this->actingAs(userWithRole('admin'))->post("/users/{$encoder->id}/reset-password", [
        'password' => 'Reset-Pass-2026',
        'password_confirmation' => 'Reset-Pass-2026',
        'own_password' => 'password',
    ]);

    $entry = AuditLog::where('action', 'user.password_reset')->sole();

    expect($entry->changes)->toEqual(['unlocked' => false, 'temporary' => true])
        ->and($entry->subject_id)->toBe($encoder->id);
});

it('keeps the sessions when the reset is refused (wrong own password)', function () {
    $encoder = userWithRole('encoder');
    sessionRow('enc-pc-1', $encoder);

    $this->actingAs(userWithRole('admin'))->from('/users')->post("/users/{$encoder->id}/reset-password", [
        'password' => 'Reset-Pass-2026',
        'password_confirmation' => 'Reset-Pass-2026',
        'own_password' => 'not-my-password',
    ])->assertSessionHasErrors('own_password');

    expect(sessionExists('enc-pc-1'))->toBeTrue();
});

it('ends the Admin sessions when IT resets the Admin password on the server', function () {
    $admin = userWithRole('admin', ['username' => 'admin']);
    $encoder = userWithRole('encoder');

    sessionRow('admin-pc-1', $admin);
    sessionRow('admin-pc-2', $admin);
    sessionRow('enc-pc', $encoder);

    $this->artisan('census:reset-admin-password')
        ->expectsConfirmation('Set a new password for this account?', 'yes')
        ->expectsQuestion('New password', 'New-Admin-Pass-2026')
        ->expectsQuestion('Type the new password again', 'New-Admin-Pass-2026')
        ->assertSuccessful();

    expect(sessionsOf($admin))->toBe([])
        ->and(sessionExists('enc-pc'))->toBeTrue();
});

it('keeps the Admin sessions when IT cancels the reset', function () {
    $admin = userWithRole('admin', ['username' => 'admin']);
    sessionRow('admin-pc-1', $admin);

    $this->artisan('census:reset-admin-password')
        ->expectsConfirmation('Set a new password for this account?', 'no')
        ->assertSuccessful();

    expect(sessionExists('admin-pc-1'))->toBeTrue();
});

// --- A user changes their own password ----------------------------------------------------

it('keeps the current session and ends the other ones when the user changes their own password', function () {
    $user = userWithRole('encoder');
    $someoneElse = userWithRole('viewer');

    // The current session: the browser sends this id in its cookie.
    $current = Str::random(40);

    sessionRow($current, $user);
    sessionRow('my-other-pc', $user);
    sessionRow('my-third-pc', $user);
    sessionRow('someone-else', $someoneElse);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from('/settings/security')
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'My-New-Pass-2026',
            'password_confirmation' => 'My-New-Pass-2026',
        ])
        ->assertSessionHasNoErrors();

    expect(sessionsOf($user))->toBe([$current])
        ->and(sessionExists('my-other-pc'))->toBeFalse()
        ->and(sessionExists('my-third-pc'))->toBeFalse()
        ->and(sessionExists('someone-else'))->toBeTrue();

    $entry = AuditLog::where('action', 'user.password_changed')->sole();

    expect($entry->changes)->toEqual(['was_temporary' => false]);
});

it('keeps the sessions when the new password is refused', function () {
    $user = userWithRole('encoder');
    $current = Str::random(40);

    sessionRow($current, $user);
    sessionRow('my-other-pc', $user);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from('/settings/security')
        ->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'My-New-Pass-2026',
            'password_confirmation' => 'My-New-Pass-2026',
        ])
        ->assertSessionHasErrors('current_password');

    expect(sessionExists($current))->toBeTrue()
        ->and(sessionExists('my-other-pc'))->toBeTrue();
});

it('keeps the current session and ends the other ones when a temporary password is replaced', function () {
    $user = userWithRole('encoder', ['must_change_password' => true]);
    $current = Str::random(40);

    sessionRow($current, $user);
    sessionRow('my-other-pc', $user);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from('/choose-password')
        ->put('/choose-password', [
            'password' => 'My-Own-Pass-2026',
            'password_confirmation' => 'My-Own-Pass-2026',
        ])
        ->assertSessionHasNoErrors();

    expect(sessionsOf($user))->toBe([$current])
        ->and($user->fresh()->must_change_password)->toBeFalse();
});

it('does nothing to sessions with another session driver, and does not fail', function () {
    config(['session.driver' => 'file']);

    $encoder = userWithRole('encoder');
    sessionRow('enc-pc-1', $encoder);

    $this->actingAs(userWithRole('admin'))->post("/users/{$encoder->id}/reset-password", [
        'password' => 'Reset-Pass-2026',
        'password_confirmation' => 'Reset-Pass-2026',
        'own_password' => 'password',
    ])->assertRedirect('/users');

    expect(sessionExists('enc-pc-1'))->toBeTrue();
});
