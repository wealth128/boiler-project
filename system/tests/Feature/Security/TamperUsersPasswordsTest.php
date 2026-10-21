<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SecurityWorld;
use Tests\Support\Tamper;

/*
 * Step 7a, check 2 (Users and the password pages): wrong types, missing and
 * too-long values, values not in the allowed lists, extra fields that try
 * to set a role, a flag or a password, someone else's account, an account in
 * Trash. Expected: 422 with a message, 403 or 404. Never a 500, and the
 * database stays as it was.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

/** A valid "new account" form. @param  array<string, mixed>  $over */
function secAccountForm(array $over = []): array
{
    return [
        'name' => 'New Encoder',
        'username' => 'new.encoder',
        'role' => 'encoder',
        'office' => 'ER',
        'password' => SecurityWorld::newPassword('Temp'),
        'password_confirmation' => SecurityWorld::newPassword('Temp'),
        ...$over,
    ];
}

dataset('bad accounts', [
    'no name' => [['name' => null], 'name'],
    'name only spaces' => [['name' => '    '], 'name'],
    'name 101 characters' => [['name' => str_repeat('n', 101)], 'name'],
    'name a list' => [['name' => ['A', 'B']], 'name'],
    'name an object' => [['name' => ['first' => 'A']], 'name'],
    'no username' => [['username' => null], 'username'],
    'username a list' => [['username' => ['a.b']], 'username'],
    'username 2 characters' => [['username' => 'ab'], 'username'],
    'username 51 characters' => [['username' => str_repeat('u', 51)], 'username'],
    'username with a space' => [['username' => 'has space'], 'username'],
    'username with symbols' => [['username' => 'semi;colon'], 'username'],
    'username with an accent' => [['username' => 'josé'], 'username'],
    'username with a slash' => [['username' => '../admin'], 'username'],
    'username with HTML' => [['username' => '<b>x</b>'], 'username'],
    'no role' => [['role' => null], 'role'],
    'role not in the list' => [['role' => 'superuser'], 'role'],
    'role in capitals' => [['role' => 'ADMIN'], 'role'],
    'role a list' => [['role' => ['admin']], 'role'],
    'a second Admin' => [['role' => 'admin', 'office' => 'Admin', 'username' => 'second.admin'], 'role'],
    'a second System Admin' => [['role' => 'system_admin', 'office' => 'IT', 'username' => 'second.sysadmin'], 'role'],
    'no office' => [['office' => null], 'office'],
    'office not in the list' => [['office' => 'Basement'], 'office'],
    'office a list' => [['office' => ['ER']], 'office'],
    'encoder office that is switched off' => [['office' => 'OPD'], 'office'],
    'password too short' => [['password' => 'Short1', 'password_confirmation' => 'Short1'], 'password'],
    'password only letters' => [['password' => 'OnlyLettersHere', 'password_confirmation' => 'OnlyLettersHere'], 'password'],
    'password only numbers' => [['password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
    'password a list' => [['password' => ['Valid1234'], 'password_confirmation' => ['Valid1234']], 'password'],
    'password not confirmed' => [['password' => 'Valid1234x', 'password_confirmation' => 'Different1234'], 'password'],
    'password without confirmation' => [['password' => 'Valid1234x', 'password_confirmation' => null], 'password'],
    'no password' => [['password' => null, 'password_confirmation' => null], 'password'],
]);

it('refuses a tampered new account, whoever adds it', function (array $tamper, string $field, string $who) {
    $this->world = $w = SecurityWorld::boot($who);
    userWithRole('system_admin', ['username' => 'the.sysadmin']);
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/users', $tamper + secAccountForm(), 422, $field);
})->with('bad accounts')->with(['admin', 'system_admin']);

it('refuses a new account with a username that exists, in any letter case, even in Trash', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $w->otherUser->forceFill(['deleted_at' => now(), 'deleted_by' => $w->actor->id, 'delete_reason' => 'x'])->saveQuietly();

    Tamper::refused($this, 'POST', '/users', secAccountForm(['username' => $w->encoder->username]), 422, 'username');
    Tamper::refused($this, 'POST', '/users', secAccountForm(['username' => strtoupper($w->encoder->username)]), 422, 'username');
    Tamper::refused($this, 'POST', '/users', secAccountForm(['username' => $w->otherUser->username]), 422, 'username');
});

it('ignores extra fields on a new account: it is active, unlocked, temporary, and has the role and office chosen', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('POST', '/users', secAccountForm([
        'is_active' => false,
        'must_change_password' => false,
        'locked_at' => '2020-01-01 00:00:00',
        'failed_attempts' => 4,
        'remember_token' => 'known-token',
        'id' => 4242,
        'deleted_at' => '2020-01-01 00:00:00',
        'email_verified_at' => '2020-01-01 00:00:00',
        'is_admin' => true,
    ]))->assertRedirect();

    $user = User::where('username', 'new.encoder')->sole();

    expect($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->locked_at)->toBeNull()
        ->and($user->failed_attempts)->toBe(0)
        ->and($user->remember_token)->not->toBe('known-token')
        ->and($user->id)->not->toBe(4242)
        ->and($user->deleted_at)->toBeNull()
        ->and($user->role->value)->toBe('encoder')
        ->and(Hash::check(SecurityWorld::newPassword('Temp'), $user->password))->toBeTrue()
        ->and($user->password)->not->toContain('Temp');
});

it('refuses a tampered account change', function (array $tamper, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    userWithRole('system_admin', ['username' => 'the.sysadmin']);
    $this->actingAs($w->actor);

    $form = ['name' => 'Renamed', 'username' => 'renamed.user', 'role' => 'encoder', 'office' => 'ER'];

    Tamper::refused($this, 'PUT', "/users/{$w->otherUser->id}", $tamper + $form, 422, $field);
})->with(secBadAccountRows());

/** The bad-account rows that make sense for a change (no password fields; a second Admin is tested below). */
function secBadAccountRows(): array
{
    return [
        'no name' => [['name' => null], 'name'],
        'name only spaces' => [['name' => '    '], 'name'],
        'name 101 characters' => [['name' => str_repeat('n', 101)], 'name'],
        'name a list' => [['name' => ['A', 'B']], 'name'],
        'no username' => [['username' => null], 'username'],
        'username a list' => [['username' => ['a.b']], 'username'],
        'username 2 characters' => [['username' => 'ab'], 'username'],
        'username 51 characters' => [['username' => str_repeat('u', 51)], 'username'],
        'username with a space' => [['username' => 'has space'], 'username'],
        'username with symbols' => [['username' => 'semi;colon'], 'username'],
        'no role' => [['role' => null], 'role'],
        'role not in the list' => [['role' => 'superuser'], 'role'],
        'role a list' => [['role' => ['admin']], 'role'],
        'no office' => [['office' => null], 'office'],
        'office not in the list' => [['office' => 'Basement'], 'office'],
        'office a list' => [['office' => ['ER']], 'office'],
        'encoder office that is switched off' => [['office' => 'OPD'], 'office'],
    ];
}

it('refuses to give a second Admin or System Admin role by changing an account', function (string $role, string $office) {
    $this->world = $w = SecurityWorld::boot('admin');
    userWithRole('system_admin', ['username' => 'the.sysadmin']);
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/users/{$w->otherUser->id}", ['name' => 'Renamed', 'username' => $w->otherUser->username, 'role' => $role, 'office' => $office], 422, 'role');
})->with([['admin', 'Admin'], ['system_admin', 'IT']]);

it('refuses to let anyone change their own role, or the Admin account', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $sysadmin = userWithRole('system_admin', ['username' => 'the.sysadmin']);

    // The Admin changes their own role.
    $this->actingAs($w->actor);
    Tamper::refused($this, 'PUT', "/users/{$w->actor->id}", ['name' => $w->actor->name, 'username' => $w->actor->username, 'role' => 'encoder', 'office' => 'ER'], [403, 422], 'role');

    // The System Admin changes the Admin account, its role, or deactivates / resets / deletes it.
    $this->actingAs($sysadmin);
    Tamper::refused($this, 'PUT', "/users/{$w->actor->id}", ['name' => 'Hacked', 'username' => $w->actor->username, 'role' => 'admin', 'office' => 'Admin'], 403);
    Tamper::refused($this, 'PUT', "/users/{$w->actor->id}", ['name' => 'Hacked', 'username' => 'hacked', 'role' => 'encoder', 'office' => 'ER'], 403);
    Tamper::refused($this, 'POST', "/users/{$w->actor->id}/toggle-active", [], 403);
    Tamper::refused($this, 'POST', "/users/{$w->actor->id}/reset-password", ['password' => 'Hacked12345', 'password_confirmation' => 'Hacked12345', 'own_password' => 'password'], 403);
    Tamper::refused($this, 'DELETE', "/users/{$w->actor->id}", ['reason' => 'Duplicate account'], 403);

    // Nobody deletes or deactivates their own account.
    $this->actingAs($w->actor);
    Tamper::refused($this, 'DELETE', "/users/{$w->actor->id}", ['reason' => 'Duplicate account'], 403);
    Tamper::refused($this, 'POST', "/users/{$w->actor->id}/toggle-active", [], 403);
});

it('ignores extra fields on an account change: password, flags and lock stay', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $hash = $w->otherUser->password;

    $this->json('PUT', "/users/{$w->otherUser->id}", [
        'name' => 'Renamed', 'username' => $w->otherUser->username, 'role' => 'encoder', 'office' => 'ER',
        'password' => 'Hacked12345', 'password_confirmation' => 'Hacked12345',
        'must_change_password' => true, 'is_active' => false, 'locked_at' => now()->toDateTimeString(), 'failed_attempts' => 5,
        'remember_token' => 'known', 'deleted_at' => now()->toDateTimeString(), 'id' => 4242, 'email_verified_at' => now()->toDateTimeString(),
    ])->assertRedirect();

    $user = $w->otherUser->fresh();

    expect($user->name)->toBe('Renamed')
        ->and($user->password)->toBe($hash)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->locked_at)->toBeNull()
        ->and($user->failed_attempts)->toBe(0)
        ->and($user->remember_token)->not->toBe('known')
        ->and($user->deleted_at)->toBeNull()
        ->and($user->id)->not->toBe(4242);
});

it('refuses to act on an account that is in Trash, missing or named oddly', function (string $method, string $suffix, array $data) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $w->otherUser->forceFill(['deleted_at' => now(), 'deleted_by' => $w->actor->id, 'delete_reason' => 'x'])->saveQuietly();

    Tamper::refused($this, $method, "/users/{$w->otherUser->id}{$suffix}", $data, 404);
    Tamper::refused($this, $method, "/users/999999{$suffix}", $data, 404);
    Tamper::refused($this, $method, "/users/abc{$suffix}", $data, 404);
    Tamper::refused($this, $method, "/users/-1{$suffix}", $data, 404);
    Tamper::refused($this, $method, "/users/99999999999999999999{$suffix}", $data, 404);
    Tamper::refused($this, $method, "/users/1%20OR%201=1{$suffix}", $data, 404);
})->with([
    'change' => ['PUT', '', ['name' => 'x', 'username' => 'xyz', 'role' => 'encoder', 'office' => 'ER']],
    'delete' => ['DELETE', '', ['reason' => 'Duplicate account']],
    'unlock' => ['POST', '/unlock', []],
    'reset password' => ['POST', '/reset-password', ['password' => 'Valid12345', 'password_confirmation' => 'Valid12345', 'own_password' => 'password']],
    'switch on or off' => ['POST', '/toggle-active', []],
]);

it('refuses to delete an account with a bad reason', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', "/users/{$w->otherUser->id}", $data, 422, $field);
})->with('bad delete reasons');

it('refuses a password reset with a wrong confirmation or a weak password', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', "/users/{$w->otherUser->id}/reset-password", $data, 422, $field);
})->with([
    'no own password' => [['password' => 'Valid12345', 'password_confirmation' => 'Valid12345'], 'own_password'],
    'wrong own password' => [['password' => 'Valid12345', 'password_confirmation' => 'Valid12345', 'own_password' => 'not-my-password'], 'own_password'],
    'own password a list' => [['password' => 'Valid12345', 'password_confirmation' => 'Valid12345', 'own_password' => ['password']], 'own_password'],
    'the user\'s password given as own password' => [['password' => 'Valid12345', 'password_confirmation' => 'Valid12345', 'own_password' => 'Valid12345'], 'own_password'],
    'no new password' => [['own_password' => 'password'], 'password'],
    'new password too short' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1', 'own_password' => 'password'], 'password'],
    'new password only letters' => [['password' => 'OnlyLetters', 'password_confirmation' => 'OnlyLetters', 'own_password' => 'password'], 'password'],
    'new password a list' => [['password' => ['Valid12345'], 'password_confirmation' => ['Valid12345'], 'own_password' => 'password'], 'password'],
    'new password not confirmed' => [['password' => 'Valid12345', 'password_confirmation' => 'Other12345', 'own_password' => 'password'], 'password'],
]);

it('does not unlock an account that is not locked, and writes nothing', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $before = AuditLog::count();
    $this->json('POST', "/users/{$w->otherUser->id}/unlock")->assertRedirect();

    expect(AuditLog::count())->toBe($before);
});

// --- Password pages -------------------------------------------------------------------

it('refuses a tampered password change from the settings page', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('viewer');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', '/settings/password', $data, 422, $field);
})->with([
    'nothing' => [[], 'current_password'],
    'no current password' => [['password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'], 'current_password'],
    'wrong current password' => [['current_password' => 'wrong', 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'], 'current_password'],
    'current password a list' => [['current_password' => ['password'], 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123'], 'current_password'],
    'new password too short' => [['current_password' => 'password', 'password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
    'new password only letters' => [['current_password' => 'password', 'password' => 'OnlyLetters', 'password_confirmation' => 'OnlyLetters'], 'password'],
    'new password only numbers' => [['current_password' => 'password', 'password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
    'new password a list' => [['current_password' => 'password', 'password' => ['Brandnew123'], 'password_confirmation' => ['Brandnew123']], 'password'],
    'new password not confirmed' => [['current_password' => 'password', 'password' => 'Brandnew123', 'password_confirmation' => 'Other12345'], 'password'],
    'no new password' => [['current_password' => 'password'], 'password'],
]);

it('ignores extra fields when changing your own password', function () {
    $this->world = $w = SecurityWorld::boot('viewer');
    $this->actingAs($w->actor);

    $this->json('PUT', '/settings/password', [
        'current_password' => 'password', 'password' => 'Brandnew123', 'password_confirmation' => 'Brandnew123',
        'role' => 'admin', 'is_active' => false, 'must_change_password' => true, 'username' => 'hacked',
    ])->assertRedirect();

    $user = $w->actor->fresh();

    expect($user->role->value)->toBe('viewer')
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->username)->not->toBe('hacked')
        ->and(Hash::check('Brandnew123', $user->password))->toBeTrue();
});

it('refuses a tampered "Choose your new password"', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('must_change');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', '/choose-password', $data, 422, $field);
})->with([
    'nothing' => [[], 'password'],
    'too short' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
    'only letters' => [['password' => 'OnlyLettersHere', 'password_confirmation' => 'OnlyLettersHere'], 'password'],
    'only numbers' => [['password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
    'a list' => [['password' => ['Brandnew123'], 'password_confirmation' => ['Brandnew123']], 'password'],
    'not confirmed' => [['password' => 'Brandnew123', 'password_confirmation' => 'Other12345'], 'password'],
    'the same as the temporary one' => [['password' => 'password', 'password_confirmation' => 'password'], 'password'],
]);

it('keeps "must change password" on until a valid new password is sent, and ignores extra fields', function () {
    $this->world = $w = SecurityWorld::boot('must_change');
    $this->actingAs($w->actor);

    $this->json('PUT', '/choose-password', [
        'password' => 'Chosen12345', 'password_confirmation' => 'Chosen12345',
        'role' => 'viewer', 'is_active' => false, 'must_change_password' => true, 'username' => 'hacked',
    ])->assertRedirect();

    $user = $w->actor->fresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->role->value)->toBe('admin')
        ->and($user->is_active)->toBeTrue()
        ->and($user->username)->not->toBe('hacked');
});

it('does not let someone who has no temporary password use "Choose your new password" to change theirs', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $hash = $w->actor->password;

    $this->json('PUT', '/choose-password', ['password' => 'Chosen12345', 'password_confirmation' => 'Chosen12345'])->assertRedirect();

    expect($w->actor->fresh()->password)->toBe($hash);
});

it('refuses a tampered profile change and ignores extra fields', function (array $data) {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PATCH', '/settings/profile', $data, 422, 'name');
})->with([
    'no name' => [[]],
    'empty name' => [['name' => '']],
    'name a list' => [['name' => ['A']]],
    'name 101 characters' => [['name' => str_repeat('n', 101)]],
]);

it('changes only the name on the profile page, whatever else is sent', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $username = $w->actor->username;

    $this->json('PATCH', '/settings/profile', [
        'name' => 'Only The Name', 'role' => 'admin', 'username' => 'hacked', 'office' => 'Admin', 'is_active' => false,
        'must_change_password' => true, 'password' => 'Hacked12345', 'locked_at' => now()->toDateTimeString(),
    ])->assertRedirect();

    $user = $w->actor->fresh();

    expect($user->name)->toBe('Only The Name')
        ->and($user->role->value)->toBe('encoder')
        ->and($user->username)->toBe($username)
        ->and($user->office->value)->toBe('ER')
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->locked_at)->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

// --- Login form ---------------------------------------------------------------------

it('answers a tampered login form with a message, never a server error, and signs nobody in', function (array $data) {
    $this->world = $w = SecurityWorld::boot('guest');

    $response = $this->json('POST', '/login', $data);

    expect($response->getStatusCode())->toBeIn([401, 422, 429], json_encode($data).' gave '.$response->getStatusCode());
    $this->assertGuest();
})->with([
    'nothing' => [[]],
    'username a list' => [['username' => ['a'], 'password' => 'password']],
    'password a list' => [['username' => 'someone', 'password' => ['password']]],
    'both lists' => [['username' => ['a'], 'password' => ['b']]],
    'username an object' => [['username' => ['x' => 'y'], 'password' => 'password']],
    'username a number' => [['username' => 12345, 'password' => 'password']],
    'very long username' => [['username' => str_repeat('u', 5000), 'password' => 'password']],
    'very long password' => [['username' => 'someone', 'password' => str_repeat('p', 100000)]],
    'sql in the username' => [['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]],
    'null byte' => [['username' => "admin\0", 'password' => "x\0"]],
    'no password' => [['username' => 'someone']],
    'no username' => [['password' => 'password']],
    'remember a list' => [['username' => 'someone', 'password' => 'x', 'remember' => ['1']]],
]);
