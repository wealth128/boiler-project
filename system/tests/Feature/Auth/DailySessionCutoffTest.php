<?php

use App\Http\Middleware\EndSessionAtDailyCutoff;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Sessions end only on Log out or at the daily cutoff (8:00 PM, Asia/Manila).
 * No idle timeout.
 */

function at(string $time): Carbon
{
    return Carbon::parse($time, 'Asia/Manila');
}

function signedInAt(User $user, string $time)
{
    return test()->actingAs($user)->withSession([
        EndSessionAtDailyCutoff::SESSION_KEY => at($time)->getTimestamp(),
    ]);
}

it('signs out at 8:00 PM someone who logged in earlier that day', function () {
    $user = userWithRole('encoder');
    $this->travelTo(at('2026-10-09 20:01'));

    signedInAt($user, '2026-10-09 07:30')
        ->get('/encode')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session ended at 8:00 PM. Please log in again.');

    $this->assertGuest();

    $entry = AuditLog::sole();
    expect($entry->action)->toBe('user.session_ended')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->changes)->toBe(['cutoff' => '20:00']);
});

it('keeps the session open before 8:00 PM, however long the user was idle', function () {
    $this->travelTo(at('2026-10-09 19:59'));

    signedInAt(userWithRole('encoder'), '2026-10-09 06:00')
        ->get('/encode')
        ->assertOk();

    $this->assertAuthenticated();
});

it('lets someone who logged in after 8:00 PM work until 8:00 PM the next day', function () {
    $user = userWithRole('encoder');

    $this->travelTo(at('2026-10-10 09:00'));
    signedInAt($user, '2026-10-09 20:30')->get('/encode')->assertOk();

    $this->travelTo(at('2026-10-10 20:00'));
    signedInAt($user, '2026-10-09 20:30')->get('/encode')->assertRedirect(route('login'));
});

it('signs out the next morning someone who left the PC signed in overnight', function () {
    $this->travelTo(at('2026-10-10 07:00'));

    signedInAt(userWithRole('admin'), '2026-10-09 15:00')
        ->get('/encode')
        ->assertRedirect(route('login'));
});

it('answers a JSON request after the cutoff with 401', function () {
    $this->travelTo(at('2026-10-09 21:00'));

    signedInAt(userWithRole('encoder'), '2026-10-09 08:00')
        ->getJson('/patients/search?q=cruz')
        ->assertUnauthorized();
});

it('saves the login time at login', function () {
    userWithRole('encoder', ['username' => 'er.cruz']);
    $this->travelTo(at('2026-10-09 08:00'));

    $this->post(route('login.store'), ['username' => 'er.cruz', 'password' => 'password']);

    expect(session(EndSessionAtDailyCutoff::SESSION_KEY))->toBe(at('2026-10-09 08:00')->getTimestamp());

    $this->travelTo(at('2026-10-09 20:00'));
    $this->get('/encode')->assertRedirect(route('login'));
});

it('does not write user.logout for a cutoff sign-out', function () {
    $this->travelTo(at('2026-10-09 20:30'));

    signedInAt(userWithRole('encoder'), '2026-10-09 08:00')->get('/encode');

    expect(AuditLog::where('action', 'user.logout')->exists())->toBeFalse();
});

it('can be turned off by leaving the cutoff empty', function () {
    config(['census.session_cutoff' => '']);
    $this->travelTo(at('2026-10-10 21:00'));

    signedInAt(userWithRole('encoder'), '2026-10-01 08:00')->get('/encode')->assertOk();
});

it('has no idle timeout shorter than a day', function () {
    expect(config('session.lifetime'))->toBeGreaterThan(24 * 60)
        ->and(config('session.expire_on_close'))->toBeFalse();
});
