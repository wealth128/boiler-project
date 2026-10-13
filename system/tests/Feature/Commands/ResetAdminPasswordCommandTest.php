<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;

/*
 * php artisan census:reset-admin-password: IT resets the Admin's password
 * on the server (the System Admin can't, planning/rbac.md).
 */

const CONFIRM_RESET = 'Set a new password for this account?';

function resetAdminPassword(string $password, ?string $again = null, array $options = [])
{
    return test()->artisan('census:reset-admin-password', $options)
        ->expectsConfirmation(CONFIRM_RESET, 'yes')
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('Type the new password again', $again ?? $password);
}

it("resets the Admin's password, clears a lock and writes user.password_reset", function () {
    $admin = userWithRole('admin', ['username' => 'admin', 'failed_attempts' => 5, 'locked_at' => now()]);

    resetAdminPassword('New-Admin-Pass-2026')
        ->expectsOutputToContain('Password changed and account unlocked.')
        ->assertSuccessful();

    $admin->refresh();
    expect(Hash::check('New-Admin-Pass-2026', $admin->password))->toBeTrue()
        ->and($admin->failed_attempts)->toBe(0)
        ->and($admin->locked_at)->toBeNull();

    $entry = AuditLog::where('action', 'user.password_reset')->sole();
    expect($entry->subject_id)->toBe($admin->id)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->changes)->toEqual(['via' => 'census:reset-admin-password', 'unlocked' => true, 'temporary' => true])
        ->and($admin->must_change_password)->toBeTrue()
        ->and(AuditLog::where('action', 'user.updated')->exists())->toBeFalse()
        ->and(AuditLog::all()->map(fn ($e) => json_encode($e->changes))->implode(' '))->not->toContain('New-Admin-Pass-2026');
});

it('lets the Admin log in with the new password, then choose their own', function () {
    userWithRole('admin', ['username' => 'admin']);

    resetAdminPassword('New-Admin-Pass-2026')
        ->expectsOutputToContain('It is temporary')
        ->assertSuccessful();

    $this->post(route('login.store'), ['username' => 'admin', 'password' => 'New-Admin-Pass-2026'])
        ->assertRedirect('/choose-password');

    $this->get('/encode')->assertRedirect('/choose-password');
});

it('changes nothing when the two passwords differ', function () {
    $admin = userWithRole('admin');
    $before = $admin->password;

    resetAdminPassword('New-Admin-Pass-2026', 'Something-Else-2026')
        ->expectsOutputToContain('The two passwords do not match.')
        ->assertFailed();

    expect($admin->fresh()->password)->toBe($before)
        ->and(AuditLog::where('action', 'user.password_reset')->exists())->toBeFalse();
});

it('refuses a password that is too short', function () {
    userWithRole('admin');

    resetAdminPassword('short')->assertFailed();

    expect(AuditLog::where('action', 'user.password_reset')->exists())->toBeFalse();
});

it('changes nothing when IT answers no', function () {
    $admin = userWithRole('admin');
    $before = $admin->password;

    $this->artisan('census:reset-admin-password')
        ->expectsConfirmation(CONFIRM_RESET, 'no')
        ->expectsOutput('Nothing changed.')
        ->assertSuccessful();

    expect($admin->fresh()->password)->toBe($before);
});

it('picks the active Admin, or the one named with --username', function () {
    userWithRole('admin', ['username' => 'old.admin', 'is_active' => false]);
    $active = userWithRole('admin', ['username' => 'admin']);

    $this->artisan('census:reset-admin-password')
        ->expectsOutputToContain("({$active->username})")
        ->expectsConfirmation(CONFIRM_RESET, 'no')
        ->assertSuccessful();

    resetAdminPassword('New-Admin-Pass-2026', options: ['--username' => 'old.admin'])->assertSuccessful();

    expect(Hash::check('New-Admin-Pass-2026', $active->fresh()->password))->toBeFalse();
});

it('only resets Admin accounts', function () {
    userWithRole('admin');
    userWithRole('encoder', ['username' => 'er.cruz']);

    $this->artisan('census:reset-admin-password', ['--username' => 'er.cruz'])
        ->expectsOutputToContain('No Admin account with the username "er.cruz".')
        ->assertFailed();
});

it('says so when there is no Admin account', function () {
    userWithRole('system_admin');

    $this->artisan('census:reset-admin-password')
        ->expectsOutputToContain('There is no Admin account.')
        ->assertFailed();
});
