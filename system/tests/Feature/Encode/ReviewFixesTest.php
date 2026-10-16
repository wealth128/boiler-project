<?php

use App\Http\Controllers\Encode\EncodeController;
use App\Http\Controllers\Encode\PatientSearchController;
use App\Http\Controllers\Encode\VisitController;
use App\Http\Requests\Encode\StoreVisitRequest;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use App\Services\ReportBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Step 6x, items 4 to 7:
 * 4. The Gate "encode-visits" inside the Encode page and the patient search.
 * 5. Throttles on Print, PDF, Excel and the patient search (429, friendly).
 * 6. A typed "Other" rank is cleaned and merged with a listed rank of the
 *    same branch, like diagnoses.
 * 7. The same new patient saved at the same moment by two encoders.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 5]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS']);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent', 'sort_order' => 1]);
    $this->civilian = Rank::factory()->for($this->others)->create(['name' => 'Civilian', 'sort_order' => 2]);
    $this->otherType = Rank::factory()->for($this->others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 99]);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
});

// --- 4. The Gate inside the controllers -----------------------------------------------------

it('gives a viewer and a system admin a 403 on the Encode page and the patient search', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/encode')->assertForbidden();
    $this->actingAs($user)->get('/patients/search?q=cruz')->assertForbidden();
})->with(['viewer', 'system_admin']);

it('has the Gate inside EncodeController and PatientSearchController, not only the route rule', function (string $role) {
    $user = userWithRole($role);
    $request = Request::create('/encode');
    $request->setUserResolver(fn () => $user);
    $this->actingAs($user);

    expect(fn () => app(EncodeController::class)->index($request))->toThrow(AuthorizationException::class);

    $search = Request::create('/patients/search', 'GET', ['q' => 'cruz']);
    $search->setUserResolver(fn () => $user);

    expect(fn () => app(PatientSearchController::class)($search))->toThrow(AuthorizationException::class);
})->with(['viewer', 'system_admin']);

it('still lets an encoder and the Admin use the Encode page and the search', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/encode')->assertOk();
    $this->actingAs($user)->get('/patients/search?q=cruz')->assertOk();
})->with(['encoder', 'admin']);

// --- 5. Throttles --------------------------------------------------------------------------------

it('puts a throttle on Print, PDF, Excel and the patient search', function (string $route, string $limiter) {
    expect(Route::getRoutes()->getByName($route)->gatherMiddleware())->toContain("throttle:{$limiter}");
})->with([
    'print' => ['reports.print', 'report-files'],
    'pdf' => ['reports.export.pdf', 'report-files'],
    'excel' => ['reports.export.excel', 'report-files'],
    'search' => ['patients.search', 'patient-search'],
]);

it('answers 429 with a friendly page after 20 report requests in a minute, for Print, PDF and Excel alike', function () {
    $admin = userWithRole('admin');

    foreach (range(1, 20) as $n) {
        $this->actingAs($admin)->get('/reports/print')->assertOk();
    }

    $this->actingAs($admin)->get('/reports/print')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 429)
            ->where('message', 'Too many requests, wait a minute and try again.'));

    // One shared limit: the files are refused too.
    $this->actingAs($admin)->get('/reports/export/pdf')->assertStatus(429);
    $this->actingAs($admin)->get('/reports/export/excel')->assertStatus(429);
});

it('lets the 20th report request through and keeps the limit per user', function () {
    $admin = userWithRole('admin');

    foreach (range(1, 20) as $n) {
        $this->actingAs($admin)->get('/reports/print')->assertOk();
    }

    $this->actingAs($admin)->get('/reports/print')->assertStatus(429);

    // Someone else is not limited by the Admin's requests.
    $this->actingAs(userWithRole('viewer'))->get('/reports/print')->assertOk();
});

it('answers 429 with a short JSON message after 60 searches in a minute', function () {
    $encoder = userWithRole('encoder');

    foreach (range(1, 60) as $n) {
        $this->actingAs($encoder)->getJson('/patients/search?q=cruz')->assertOk();
    }

    $this->actingAs($encoder)->getJson('/patients/search?q=cruz')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too many requests, wait a minute.']);
});

it('does not limit normal use', function () {
    $admin = userWithRole('admin');

    foreach (range(1, 5) as $n) {
        $this->actingAs($admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk();
    }
});

// --- 6. Typed "Other" rank ----------------------------------------------------------------------

/** One ER visit in September 2026 of a new patient. */
function rankVisit(object $test, Branch $branch, Rank $rank, ?string $typed = null): Visit
{
    return Visit::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'visited_at' => Carbon::parse('2026-09-10 09:00', 'Asia/Manila'),
        'category' => 'ER',
        'age' => 30,
        'branch_id' => $branch->id,
        'rank_id' => $rank->id,
        'rank_other' => $typed,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => userWithRole('encoder')->id,
    ]);
}

/**
 * The rank lines of a branch in the September ER report, as label => count.
 *
 * @return array<string, int>
 */
function rankLines(string $branch): array
{
    $report = app(ReportBuilder::class)->build('2026-09');
    $found = collect($report['categories']['ER']['branches'])->firstWhere('name', $branch);

    return collect($found['ranks'])->mapWithKeys(fn ($line) => [$line['label'] => $line['count']])->all();
}

it('merges typed text that matches a listed rank of the same branch, ignoring case and extra spaces', function () {
    rankVisit($this, $this->others, $this->dependent);
    rankVisit($this, $this->others, $this->otherType, 'dependent');
    rankVisit($this, $this->others, $this->otherType, '  DEPENDENT ');
    rankVisit($this, $this->others, $this->otherType, "Dependent\t");

    expect(rankLines('Others'))->toBe(['Dependent' => 4]);
});

it('keeps the listed rank order and its name when typed text is merged', function () {
    rankVisit($this, $this->others, $this->civilian);
    rankVisit($this, $this->others, $this->otherType, 'dependent');

    // Seniority order: Dependent (1) then Civilian (2), and the listed spelling.
    expect(array_keys(rankLines('Others')))->toBe(['Dependent', 'Civilian']);
});

it('merges typed text with extra spaces and different case into one line', function () {
    rankVisit($this, $this->others, $this->otherType, 'Sea  Cadet');
    rankVisit($this, $this->others, $this->otherType, 'sea cadet');
    rankVisit($this, $this->others, $this->otherType, ' SEA CADET ');
    rankVisit($this, $this->others, $this->otherType, 'Guest');

    $lines = rankLines('Others');

    expect($lines)->toHaveCount(2)
        ->and($lines['Guest'])->toBe(1)
        ->and(array_sum($lines))->toBe(4)
        ->and(collect($lines)->except('Guest')->values()->all())->toBe([3]);
});

it('cleans the spaces in a typed rank that has no listed match', function () {
    rankVisit($this, $this->others, $this->otherType, '  Press   Officer ');

    expect(rankLines('Others'))->toBe(['Press Officer' => 1]);
});

it('does not merge typed text with a listed rank of another branch', function () {
    // "ENS" is a listed rank of Navy, not of Others.
    rankVisit($this, $this->others, $this->otherType, 'ENS');
    rankVisit($this, $this->navy, $this->ens);

    expect(rankLines('Others'))->toBe(['ENS' => 1])
        ->and(rankLines('Navy'))->toBe(['ENS' => 1]);
});

it('merges typed text with a listed rank that is in Trash, like diagnoses', function () {
    $this->civilian->delete();

    rankVisit($this, $this->others, $this->civilian);
    rankVisit($this, $this->others, $this->otherType, 'civilian');

    expect(rankLines('Others'))->toBe(['Civilian' => 2]);
});

it('keeps an Other visit with nothing typed as its own line', function () {
    rankVisit($this, $this->others, $this->otherType, null);
    rankVisit($this, $this->others, $this->otherType, '   ');

    expect(rankLines('Others'))->toBe(['Other (specify)' => 2]);
});

it('keeps the branch total the same when typed ranks are merged', function () {
    rankVisit($this, $this->others, $this->dependent);
    rankVisit($this, $this->others, $this->otherType, 'dependent');
    rankVisit($this, $this->others, $this->otherType, 'Visitor');

    $report = app(ReportBuilder::class)->build('2026-09');
    $branch = collect($report['categories']['ER']['branches'])->firstWhere('name', 'Others');

    expect($branch['total'])->toBe(3)
        ->and($report['categories']['ER']['patients'])->toBe(3)
        ->and(array_sum(array_column($branch['ranks'], 'count')))->toBe(3);
});

// --- 7. The same new patient saved at the same moment -----------------------------------------------

/** @return array<string, mixed> */
function newPatientForm(object $test, array $overrides = []): array
{
    return [
        'patient_id' => null,
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
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
        ...$overrides,
    ];
}

/** A validated store request, as the controller receives it. */
function validatedStoreRequest(array $data, $user): StoreVisitRequest
{
    $request = StoreVisitRequest::create('/visits', 'POST', $data);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));
    $request->validateResolved();

    return $request;
}

it('refuses the second save of the same new patient with the duplicate message (one after the other)', function () {
    $this->actingAs(userWithRole('encoder'))->post('/visits', newPatientForm($this))->assertSessionHasNoErrors();

    $this->actingAs(userWithRole('encoder'))->from('/encode')->post('/visits', newPatientForm($this))
        ->assertSessionHasErrors(['duplicate_patient' => StoreVisitRequest::DUPLICATE_MESSAGE]);

    expect(Patient::count())->toBe(1)->and(Visit::count())->toBe(1);
});

it('checks again inside the save: a patient saved by another encoder after the first check is not duplicated', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    // Encoder A's request passes the first check: the patient is not on file yet...
    $request = validatedStoreRequest(newPatientForm($this), $encoder);

    // ...then encoder B saves the same person before A's save starts.
    Patient::create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'M', 'sex' => 'Male', 'birthdate' => '1990-05-01']);

    expect(fn () => app(VisitController::class)->store($request))
        ->toThrow(ValidationException::class);

    try {
        app(VisitController::class)->store($request);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['duplicate_patient' => [StoreVisitRequest::DUPLICATE_MESSAGE]]);
    }

    // Still one patient, and no visit was saved for A.
    expect(Patient::count())->toBe(1)->and(Visit::count())->toBe(0);
});

it('does not treat a patient in Trash, a different birthdate or a different first name as a duplicate in the save', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    $trashed = Patient::create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'M', 'sex' => 'Male', 'birthdate' => '1990-05-01']);
    $trashed->delete();

    $request = validatedStoreRequest(newPatientForm($this), $encoder);
    app(VisitController::class)->store($request);

    // The trashed one and the new one.
    expect(Patient::withTrashed()->count())->toBe(2)->and(Patient::count())->toBe(1)->and(Visit::count())->toBe(1);
});

it('still saves a different person with the same name when the encoder confirms "save as new"', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    Patient::create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'M', 'sex' => 'Male', 'birthdate' => '1990-05-01']);

    $this->post('/visits', newPatientForm($this, ['confirm_new_patient' => true]))->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(2)->and(Visit::count())->toBe(1);
});

it('does not run the new-patient check for a returning patient', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    $patient = Patient::create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'M', 'sex' => 'Male', 'birthdate' => '1990-05-01']);

    $this->post('/visits', newPatientForm($this, ['patient_id' => $patient->id]))->assertSessionHasNoErrors();
    $this->post('/visits', newPatientForm($this, ['patient_id' => $patient->id]))->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)->and(Visit::count())->toBe(2);
});

it('releases the lock after saving, so the next save of that patient is not held up', function () {
    $this->actingAs(userWithRole('encoder'))->post('/visits', newPatientForm($this))->assertSessionHasNoErrors();

    $key = 'new-patient:'.sha1(mb_strtolower('Dela Cruz|Juan|1990-05-01'));
    $lock = Cache::lock($key, 5);

    expect($lock->get())->toBeTrue();

    $lock->release();
});

it('releases the lock when the save is refused as a duplicate', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);
    $request = validatedStoreRequest(newPatientForm($this), $encoder);

    Patient::create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'M', 'sex' => 'Male', 'birthdate' => '1990-05-01']);

    expect(fn () => app(VisitController::class)->store($request))->toThrow(ValidationException::class);

    $lock = Cache::lock('new-patient:'.sha1(mb_strtolower('Dela Cruz|Juan|1990-05-01')), 5);

    expect($lock->get())->toBeTrue();

    $lock->release();
});

it('treats the same name in other letter case and with extra spaces as the same lock', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    $this->post('/visits', newPatientForm($this))->assertSessionHasNoErrors();

    // Same person typed differently: the guard (case-insensitive) still stops it.
    $this->from('/encode')->post('/visits', newPatientForm($this, ['last_name' => 'DELA CRUZ', 'first_name' => 'juan']))
        ->assertSessionHasErrors('duplicate_patient');

    expect(Patient::count())->toBe(1);
});
