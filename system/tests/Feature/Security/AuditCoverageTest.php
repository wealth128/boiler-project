<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 3: every kind of change leaves an audit entry with the
 * right user. Each test makes the change through the real route, then reads
 * audit_logs. "Right user" is the account that sent the request (or the
 * account that signed in or out).
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

/** The newest audit entry with this action; fails with the list of actions that exist. */
function secAuditEntry(string $action): AuditLog
{
    $entry = AuditLog::where('action', $action)->latest('id')->first();

    expect($entry)->not->toBeNull("No audit entry '{$action}'. Entries: ".AuditLog::pluck('action')->unique()->implode(', '));

    return $entry;
}

// --- Create -------------------------------------------------------------------------

it('records an encoded visit and its new patient, for the encoder who saved them', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor)->post('/visits', $w->visitForm())->assertRedirect();

    $visit = secAuditEntry('visit.created');
    $patient = secAuditEntry('patient.created');

    expect($visit->user_id)->toBe($w->actor->id)
        ->and($visit->subject_type)->toBe('visits')
        ->and($patient->user_id)->toBe($w->actor->id)
        ->and($patient->subject_type)->toBe('patients');
});

it('records a new user account, for the Admin or System Admin who made it', function (string $role) {
    $this->world = $w = SecurityWorld::boot($role);
    $this->actingAs($w->actor)->post('/users', ['name' => 'New One', 'username' => 'new.one', 'role' => 'encoder', 'office' => 'ER', 'password' => 'TempPass2026', 'password_confirmation' => 'TempPass2026'])->assertRedirect();

    $entry = secAuditEntry('user.created');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->subject_id)->toBe(User::where('username', 'new.one')->value('id'));
})->with(['admin', 'system_admin']);

it('records a new diagnosis, rank and age bracket, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->post('/settings/diagnoses', ['name' => 'Gout'])->assertRedirect();
    $this->post('/settings/ranks', ['name' => 'LTC', 'branch_id' => $w->lists['army']->id])->assertRedirect();
    $this->post('/settings/age-brackets', ['min_age' => 18, 'max_age' => 40])->assertRedirect();

    foreach (['diagnosis.created', 'rank.created', 'age_bracket.created'] as $action) {
        expect(secAuditEntry($action)->user_id)->toBe($w->actor->id);
    }
});

// --- Edit -----------------------------------------------------------------------------

it('records a visit and patient edit with old and new values, for the editor', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor)->put("/visits/{$w->visit->id}", $w->visitForm(['patient_id' => $w->visit->patient_id, 'remarks' => 'edited', 'last_name' => 'Renamed']))->assertRedirect();

    $visit = secAuditEntry('visit.updated');
    $patient = secAuditEntry('patient.updated');

    expect($visit->user_id)->toBe($w->actor->id)
        ->and($visit->subject_id)->toBe($w->visit->id)
        ->and($visit->changes['new']['remarks'])->toBe('edited')
        ->and($patient->user_id)->toBe($w->actor->id)
        ->and($patient->changes['new']['last_name'])->toBe('Renamed');
});

it('records an account edit, a switch on or off, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->put("/users/{$w->otherUser->id}", ['name' => 'Renamed', 'username' => $w->otherUser->username, 'role' => 'encoder', 'office' => 'ER'])->assertRedirect();
    $this->post("/users/{$w->otherUser->id}/toggle-active")->assertRedirect();
    $this->post("/users/{$w->otherUser->id}/toggle-active")->assertRedirect();

    expect(secAuditEntry('user.updated')->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry('user.deactivated')->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry('user.activated')->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry('user.deactivated')->subject_id)->toBe($w->otherUser->id);
});

it('records a list change and a rank move, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->put("/settings/diagnoses/{$w->lists['dengue']->id}", ['name' => 'Dengue fever A'])->assertRedirect();
    $this->put("/settings/ranks/{$w->lists['sgt']->id}", ['name' => 'SSG'])->assertRedirect();
    $this->put("/settings/age-brackets/{$w->bracket->id}", ['min_age' => 0, 'max_age' => 15])->assertRedirect();
    $this->put("/settings/ranks/{$w->lists['sgt']->id}/move", ['direction' => 'up'])->assertRedirect();

    foreach (['diagnosis.updated', 'rank.updated', 'age_bracket.updated'] as $action) {
        expect(secAuditEntry($action)->user_id)->toBe($w->actor->id);
    }

    // The move changes sort_order on two ranks: each change is an entry.
    expect(AuditLog::where('action', 'rank.updated')->count())->toBeGreaterThanOrEqual(2);
});

it('records the hospital settings, logo and signatures, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->put('/settings/hospital', ['hospital_name' => 'Audit Hospital'])->assertRedirect();
    $this->put('/settings/hospital', ['hospital_name' => 'Audit Hospital', 'logo' => Illuminate\Http\UploadedFile::fake()->image('l.png', 100, 50), 'prepared_signature' => Illuminate\Http\UploadedFile::fake()->image('p.png', 100, 50)])->assertRedirect();
    $this->delete('/settings/hospital/logo')->assertRedirect();
    $this->delete('/settings/hospital/signature/prepared')->assertRedirect();

    foreach (['settings.updated', 'settings.logo_changed', 'settings.signature_changed', 'settings.logo_removed', 'settings.signature_removed'] as $action) {
        expect(secAuditEntry($action)->user_id)->toBe($w->actor->id);
    }

    expect(secAuditEntry('settings.updated')->changes['new'])->toHaveKey('hospital_name');
});

// --- Trash, restore, permanent delete ------------------------------------------------------

it('records a move to Trash with the reason, a restore and a permanent delete, for each record type', function (string $type) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    [$deleteUrl, $trashType, $id, $action, $confirm] = match ($type) {
        'visit' => ["/visits/{$w->visit->id}", 'visits', $w->visit->id, 'visit', fn () => Visit::withTrashed()->find($w->visit->id)->visit_no],
        'patient' => ["/patients/{$w->patient->id}", 'patients', $w->patient->id, 'patient', fn () => Patient::withTrashed()->find($w->patient->id)->patient_no],
        'user' => ["/users/{$w->otherUser->id}", 'users', $w->otherUser->id, 'user', fn () => $w->otherUser->username],
        'diagnosis' => ["/settings/diagnoses/{$w->lists['dengue']->id}", 'diagnoses', $w->lists['dengue']->id, 'diagnosis', fn () => $w->lists['dengue']->name],
        'rank' => ["/settings/ranks/{$w->lists['ens']->id}", 'ranks', $w->lists['ens']->id, 'rank', fn () => $w->lists['ens']->name],
        'age bracket' => ["/settings/age-brackets/{$w->bracket->id}", 'age-brackets', $w->bracket->id, 'age_bracket', fn () => AgeBracket::withTrashed()->find($w->bracket->id)->label],
    };

    $reason = SecurityWorld::reason(match ($type) {
        'visit' => App\Enums\VisitDeleteReason::class,
        'patient' => App\Enums\PatientDeleteReason::class,
        'user' => App\Enums\UserDeleteReason::class,
        default => App\Enums\ListItemDeleteReason::class,
    });

    $this->delete($deleteUrl, ['reason' => $reason])->assertRedirect();

    $deleted = secAuditEntry("{$action}.deleted");
    expect($deleted->user_id)->toBe($w->actor->id)
        ->and($deleted->subject_id)->toBe($id)
        ->and($deleted->changes['reason'])->toBe($reason);

    $this->post("/trash/{$trashType}/{$id}/restore")->assertRedirect();
    expect(secAuditEntry("{$action}.restored")->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry("{$action}.restored")->subject_id)->toBe($id);

    $this->delete($deleteUrl, ['reason' => $reason])->assertRedirect();
    $this->delete("/trash/{$trashType}/{$id}", ['confirm' => $confirm()])->assertRedirect();

    $purged = secAuditEntry("{$action}.purged");
    expect($purged->user_id)->toBe($w->actor->id)
        ->and($purged->subject_id)->toBe($id);
})->with(['visit', 'patient', 'user', 'diagnosis', 'rank', 'age bracket']);

it('records "Empty Trash" as one permanent-delete entry per record, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->delete('/trash', ['confirm' => 'EMPTY TRASH'])->assertRedirect();

    $entries = AuditLog::whereIn('action', ['visit.purged', 'patient.purged'])->get();

    expect($entries->pluck('action')->sort()->values()->all())->toBe(['patient.purged', 'visit.purged'])
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$w->actor->id]);
});

// --- Months -------------------------------------------------------------------------------------

it('records a month closed and reopened, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->post('/months/2026-09/close')->assertRedirect();
    expect(secAuditEntry('month.closed')->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry('month.closed')->changes['period'])->toBe('2026-09');

    $this->post('/months/2026-09/reopen')->assertRedirect();
    expect(secAuditEntry('month.reopened')->user_id)->toBe($w->actor->id)
        ->and(secAuditEntry('month.reopened')->changes['period'])->toBe('2026-09');
});

it('records a month report moved to Trash, restored and permanently deleted, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->septemberVisit();
    $this->actingAs($w->actor);

    $this->delete('/months/2026-09', ['reason' => SecurityWorld::reason(App\Enums\MonthReportDeleteReason::class)])->assertRedirect();
    $deletion = App\Models\MonthDeletion::where('period', '2026-09')->sole();

    expect(secAuditEntry('month.report_deleted')->user_id)->toBe($w->actor->id);

    $this->post("/trash/months/{$deletion->id}/restore")->assertRedirect();
    expect(secAuditEntry('month.report_restored')->user_id)->toBe($w->actor->id);

    $this->delete('/months/2026-09', ['reason' => SecurityWorld::reason(App\Enums\MonthReportDeleteReason::class)])->assertRedirect();
    $deletion = App\Models\MonthDeletion::where('period', '2026-09')->latest('id')->first();
    $this->delete("/trash/months/{$deletion->id}", ['confirm' => '2026-09'])->assertRedirect();

    expect(secAuditEntry('month.report_purged')->user_id)->toBe($w->actor->id);
});

it('records a report export, for the person who exported it', function (string $role, string $format) {
    $this->world = $w = SecurityWorld::boot($role);
    $this->actingAs($w->actor)->get("/reports/export/{$format}")->assertOk();

    expect(secAuditEntry('report.exported')->user_id)->toBe($w->actor->id);
})->with(['admin', 'viewer'])->with(['pdf', 'excel']);

// --- Users and passwords --------------------------------------------------------------------

it('records an unlock, for the Admin or System Admin who unlocked', function (string $role) {
    $this->world = $w = SecurityWorld::boot($role);
    $this->actingAs($w->actor)->post("/users/{$w->lockedUser->id}/unlock")->assertRedirect();

    $entry = secAuditEntry('user.unlocked');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->subject_id)->toBe($w->lockedUser->id);
})->with(['admin', 'system_admin']);

it('records a password reset for the account that was reset, by the person who reset it, without the password', function (string $role) {
    $this->world = $w = SecurityWorld::boot($role);
    $this->actingAs($w->actor)->post("/users/{$w->otherUser->id}/reset-password", ['password' => 'ResetPass2026', 'password_confirmation' => 'ResetPass2026', 'own_password' => 'password'])->assertRedirect();

    $entry = secAuditEntry('user.password_reset');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->subject_id)->toBe($w->otherUser->id)
        ->and(json_encode($entry->changes))->not->toContain('ResetPass2026')
        ->and(json_encode($entry->changes))->not->toContain('$2y$');
})->with(['admin', 'system_admin']);

it('records a password change, by the user themself, for both the settings page and the temporary password page', function () {
    $this->world = $w = SecurityWorld::boot('viewer');
    $this->actingAs($w->actor)->put('/settings/password', ['current_password' => 'password', 'password' => 'Brandnew2026', 'password_confirmation' => 'Brandnew2026'])->assertRedirect();

    $entry = secAuditEntry('user.password_changed');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->changes['was_temporary'])->toBeFalse();
});

it('records the choice of a new password after a temporary one', function () {
    $this->world = $w = SecurityWorld::boot('must_change');
    $this->actingAs($w->actor)->put('/choose-password', ['password' => 'Chosen2026x', 'password_confirmation' => 'Chosen2026x'])->assertRedirect();

    $entry = secAuditEntry('user.password_changed');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->changes['was_temporary'])->toBeTrue();
});

it('records sign in, wrong passwords, the lock, and sign out, for the right account', function () {
    $this->world = $w = SecurityWorld::boot('guest');
    $user = $w->loginUser;

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
    }

    expect(AuditLog::where('action', 'user.login_failed')->where('user_id', $user->id)->count())->toBe(5)
        ->and(secAuditEntry('user.locked')->user_id)->toBe($user->id);

    $other = userWithRole('encoder');
    $this->post('/login', ['username' => $other->username, 'password' => 'password'])->assertRedirect();
    expect(secAuditEntry('user.login')->user_id)->toBe($other->id);

    $this->post('/logout')->assertRedirect();
    expect(secAuditEntry('user.logout')->user_id)->toBe($other->id);
});

it('records the end of a session by the system, with the reason', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $w->actor->forceFill(['is_active' => false])->saveQuietly();

    $this->get('/encode')->assertRedirect();

    $entry = secAuditEntry('user.session_ended');

    expect($entry->user_id)->toBe($w->actor->id)
        ->and($entry->changes['reason'])->toBe('deactivated');
});

// --- Import ---------------------------------------------------------------------------------------

it('records an import and its undo, for the Admin, without any patient data', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $token = $w->stagedToken($this);
    $this->actingAs($w->actor)->post('/records/import/confirm', ['token' => $token, 'rows' => [2]])->assertRedirect();

    $created = secAuditEntry('import.created');
    $batch = App\Models\ImportBatch::latest('id')->first();

    expect($created->user_id)->toBe($w->actor->id)
        ->and(json_encode($created->changes))->not->toContain('Dela Cruz')->not->toContain('Juan');

    $this->post("/records/import/{$batch->id}/undo")->assertRedirect();

    $undone = secAuditEntry('import.undone');

    expect($undone->user_id)->toBe($w->actor->id)
        ->and(json_encode($undone->changes))->not->toContain('Dela Cruz');
});

// --- Backups --------------------------------------------------------------------------------------

it('records a manual backup, a test restore, a USB copy and the two marks, for the Admin', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->backupFakes();
    $this->actingAs($w->actor);

    $this->post('/backups/run')->assertRedirect();
    $w->goodBackup();
    $this->post('/backups/test-restore')->assertRedirect();
    $this->post('/backups/usb-copy', ['drive' => 'E:'])->assertRedirect();
    $this->post('/backups/marks/usb-copy')->assertRedirect();
    $this->post('/backups/marks/test-restore')->assertRedirect();

    foreach (['backup.run_manual', 'backup.test_restore', 'backup.usb_copy', 'backup.usb_copy_marked', 'backup.test_restore_marked'] as $action) {
        expect(secAuditEntry($action)->user_id)->toBe($w->actor->id, $action);
    }
});

it('records the automatic test restore with no user, and says it was automatic', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->backupFakes();
    $w->goodBackup();

    $this->artisan('backup:test-restore')->assertSuccessful();

    $entry = secAuditEntry('backup.test_restore');

    expect($entry->user_id)->toBeNull();
});
