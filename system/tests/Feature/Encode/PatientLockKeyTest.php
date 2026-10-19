<?php

use App\Http\Controllers\Encode\VisitController;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * The new-patient lock is keyed the way MySQL compares names in the
 * duplicate check (utf8mb4_unicode_ci: case and accents ignored), so
 * "Peña" and "Pena" typed by two encoders at the same moment share ONE
 * lock and the second one is stopped.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    config(['census.lock_wait_seconds' => 0]);

    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
});

function lockKey(string $last, string $first = 'Juan', string $birthdate = '1990-05-01'): string
{
    return VisitController::newPatientLockKey($last, $first, $birthdate);
}

function penaForm(object $test, string $last, string $first = 'Juan'): array
{
    return [
        'patient_id' => null,
        'last_name' => $last,
        'first_name' => $first,
        'middle_initial' => 'M',
        'sex' => 'Male',
        'birthdate' => '1990-05-01',
        'age' => 36,
        'branch_id' => $test->army->id,
        'rank_id' => $test->cpl->id,
        'rank_other' => null,
        'diagnosis_id' => $test->hypertension->id,
        'diagnosis_other' => null,
        'category' => 'ER',
        'remarks' => null,
    ];
}

it('gives names that MySQL treats as equal the same lock key', function (string $a, string $b) {
    expect(lockKey($a))->toBe(lockKey($b));
})->with([
    'accent' => ['Peña', 'Pena'],
    'accent and capitals' => ['PEÑA', 'pena'],
    'accent in the middle' => ['Muñoz', 'Munoz'],
    'umlaut' => ['Müller', 'Muller'],
    'acute' => ['José', 'Jose'],
    'letter n with a separate tilde mark' => ["Pen\u{0303}a", 'Pena'],
    'extra spaces around' => ['  Peña ', 'Pena'],
    'extra spaces inside' => ['Dela   Cruz', 'dela cruz'],
    'capitals' => ['DELA CRUZ', 'Dela Cruz'],
]);

it('gives the first name the same treatment', function () {
    expect(lockKey('Cruz', 'José'))->toBe(lockKey('Cruz', 'JOSE'))
        ->and(lockKey('Cruz', ' María  Luisa'))->toBe(lockKey('Cruz', 'maria luisa'));
});

it('gives different people different keys', function () {
    expect(lockKey('Peña'))->not->toBe(lockKey('Penal'))
        ->and(lockKey('Peña', 'Juan'))->not->toBe(lockKey('Peña', 'Jose'))
        ->and(lockKey('Peña', 'Juan', '1990-05-01'))->not->toBe(lockKey('Peña', 'Juan', '1990-05-02'))
        // the separators cannot be shifted: "ab|c" is not "a|bc"
        ->and(lockKey('ab', 'c'))->not->toBe(lockKey('a', 'bc'));
});

it('keeps the key for plain names as it was, so a lock held by an older version still matches', function () {
    expect(lockKey('Dela Cruz'))->toBe('new-patient:'.sha1(mb_strtolower('Dela Cruz|Juan|1990-05-01')));
});

it('stops a second encoder who types "Peña" while "Pena" is being saved', function () {
    // The first encoder's save of "Pena" is still running: it holds the lock.
    $lock = Cache::lock(lockKey('Pena'), 30);
    expect($lock->get())->toBeTrue();

    $this->actingAs(userWithRole('encoder'))->from('/encode')
        ->post('/visits', penaForm($this, 'Peña'))
        ->assertSessionHasErrors('duplicate_patient');

    expect(Patient::count())->toBe(0);

    $lock->release();
});

it('stops "PEÑA" the same way, and lets a different surname through', function () {
    $lock = Cache::lock(lockKey('Peña'), 30);
    $lock->get();

    $encoder = userWithRole('encoder');

    $this->actingAs($encoder)->from('/encode')->post('/visits', penaForm($this, 'PEÑA'))->assertSessionHasErrors('duplicate_patient');
    $this->actingAs($encoder)->post('/visits', penaForm($this, 'Santos'))->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1);
    $lock->release();
});

it('releases the lock after a save of an accented name', function () {
    $this->actingAs(userWithRole('encoder'))->post('/visits', penaForm($this, 'Peña'))->assertSessionHasNoErrors();

    $lock = Cache::lock(lockKey('Pena'), 5);

    expect($lock->get())->toBeTrue();
    $lock->release();
});
