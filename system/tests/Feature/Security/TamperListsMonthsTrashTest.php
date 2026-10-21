<?php

use App\Models\AgeBracket;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Tests\Support\SecurityWorld;
use Tests\Support\Tamper;

/*
 * Step 7a, check 2 (Lists, Reports / Months, Trash): wrong values, values
 * not in the allowed lists, extra fields, other people's records, records in
 * Trash and records in a closed month. Expected: 422 with a message, 403 or
 * 404. Never a 500, and the database stays as it was.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

// --- Lists: add ---------------------------------------------------------------

dataset('bad list names', [
    'no name' => [[], 'name'],
    'empty name' => [['name' => ''], 'name'],
    'only spaces' => [['name' => '     '], 'name'],
    'one character' => [['name' => 'a'], 'name'],
    'name a list' => [['name' => ['Asthma']], 'name'],
    'name an object' => [['name' => ['x' => 'y']], 'name'],
    'name 121 characters' => [['name' => str_repeat('n', 121)], 'name'],
]);

it('refuses a bad diagnosis name', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/settings/diagnoses', $data, 422, $field);
})->with('bad list names');

it('refuses a bad rank name or branch', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/settings/ranks', $data + ['branch_id' => $w->lists['army']->id], 422, $field);
})->with('bad list names');

it('refuses a rank name longer than 60 characters, a missing or wrong branch, and a duplicate', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => str_repeat('r', 61), 'branch_id' => $w->lists['army']->id], 422, 'name');
    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => 'NEWRANK'], 422, 'branch_id');
    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => 'NEWRANK', 'branch_id' => 999999], 422, 'branch_id');
    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => 'NEWRANK', 'branch_id' => 'army'], 422, 'branch_id');
    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => 'NEWRANK', 'branch_id' => [1]], 422, 'branch_id');
    Tamper::refused($this, 'POST', '/settings/ranks', ['name' => 'cpl', 'branch_id' => $w->lists['army']->id], 422, 'name');
    Tamper::refused($this, 'POST', '/settings/diagnoses', ['name' => 'HYPERTENSION'], 422, 'name');
    Tamper::refused($this, 'POST', '/settings/diagnoses', ['name' => '  hypertension  '], 422, 'name');
});

it('ignores extra fields when adding a list item: it can never be a fixed "Other" row, in Trash, or numbered by the browser', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('POST', '/settings/diagnoses', ['name' => 'Extra fields test', 'is_other' => true, 'sort_order' => -5, 'id' => 4242, 'deleted_at' => now()->toDateTimeString(), 'deleted_by' => 1])->assertRedirect();
    $this->json('POST', '/settings/ranks', ['name' => 'XTRA', 'branch_id' => $w->lists['army']->id, 'is_other' => true, 'sort_order' => -5, 'id' => 4243, 'deleted_at' => now()->toDateTimeString()])->assertRedirect();

    $diagnosis = Diagnosis::where('name', 'Extra fields test')->sole();
    $rank = Rank::where('name', 'XTRA')->sole();

    expect($diagnosis->is_other)->toBeFalse()
        ->and($diagnosis->id)->not->toBe(4242)
        ->and($diagnosis->deleted_at)->toBeNull()
        ->and($rank->is_other)->toBeFalse()
        ->and($rank->id)->not->toBe(4243)
        ->and($rank->deleted_at)->toBeNull();
});

it('stores a name with HTML as plain text (the screen escapes it)', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('POST', '/settings/diagnoses', ['name' => '<script>alert(1)</script>'])->assertRedirect();

    expect(Diagnosis::where('name', '<script>alert(1)</script>')->exists())->toBeTrue();
});

// --- Lists: change ---------------------------------------------------------------

it('refuses a bad rename of a diagnosis', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/settings/diagnoses/{$w->lists['dengue']->id}", $data, 422, $field);
})->with('bad list names');

it('refuses to change a fixed "Other" row, an item in Trash, a missing item and a made-up list', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    // The fixed "Other (specify)" rows can't be renamed, moved or deleted.
    Tamper::refused($this, 'PUT', "/settings/diagnoses/{$w->lists['otherDiagnosis']->id}", ['name' => 'Renamed'], 403);
    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['otherType']->id}", ['name' => 'Renamed'], 403);
    Tamper::refused($this, 'DELETE', "/settings/diagnoses/{$w->lists['otherDiagnosis']->id}", ['reason' => 'Duplicate entry'], [403, 422]);
    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['otherType']->id}/move", ['direction' => 'up'], 403);

    $w->lists['dengue']->delete();
    $w->lists['sgt']->delete();
    Tamper::refused($this, 'PUT', "/settings/diagnoses/{$w->lists['dengue']->id}", ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['sgt']->id}", ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'DELETE', "/settings/diagnoses/{$w->lists['dengue']->id}", ['reason' => 'Duplicate entry'], 404);
    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['sgt']->id}/move", ['direction' => 'up'], 404);

    Tamper::refused($this, 'PUT', '/settings/diagnoses/999999', ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'PUT', '/settings/diagnoses/abc', ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'PUT', '/settings/diagnoses/-1', ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'PUT', '/settings/diagnoses/99999999999999999999', ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'DELETE', '/settings/ranks/99999999999999999999', ['reason' => 'Duplicate entry'], 404);
    Tamper::refused($this, 'PUT', '/settings/ranks/99999999999999999999/move', ['direction' => 'up'], 404);
    Tamper::refused($this, 'PUT', '/settings/users/1', ['name' => 'Renamed'], 404);
    Tamper::refused($this, 'POST', '/settings/branches', ['name' => 'Navy 2'], 404);
    Tamper::refused($this, 'POST', '/settings/hospital%2F..', ['name' => 'x'], 404);
});

it('refuses to rename a list item to a name that already exists', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/settings/diagnoses/{$w->lists['dengue']->id}", ['name' => 'Hypertension'], 422, 'name');
    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['sgt']->id}", ['name' => 'cpl'], 422, 'name');
});

it('ignores extra fields when renaming: Other flag, order, branch and Trash state stay', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $rank = $w->lists['sgt'];

    $this->json('PUT', "/settings/ranks/{$rank->id}", ['name' => 'SSG', 'is_other' => true, 'sort_order' => 99, 'branch_id' => $w->lists['navy']->id, 'deleted_at' => now()->toDateTimeString()])->assertRedirect();

    $rank->refresh();

    expect($rank->name)->toBe('SSG')
        ->and($rank->is_other)->toBeFalse()
        ->and($rank->sort_order)->toBe(2)
        ->and($rank->branch_id)->toBe($w->lists['army']->id)
        ->and($rank->deleted_at)->toBeNull();
});

dataset('bad age brackets', [
    'nothing' => [[], 'min_age'],
    'start text' => [['min_age' => 'abc'], 'min_age'],
    'start negative' => [['min_age' => -1], 'min_age'],
    'start 121' => [['min_age' => 121], 'min_age'],
    'start a list' => [['min_age' => [5]], 'min_age'],
    'start with decimals' => [['min_age' => 5.5], 'min_age'],
    'end text' => [['min_age' => 30, 'max_age' => 'x'], 'max_age'],
    'end 121' => [['min_age' => 30, 'max_age' => 121], 'max_age'],
    'end below start' => [['min_age' => 40, 'max_age' => 30], 'max_age'],
    'overlaps the existing bracket' => [['min_age' => 10, 'max_age' => 30], 'min_age'],
]);

it('refuses a bad age bracket', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/settings/age-brackets', $data, 422, $field);
})->with('bad age brackets');

it('refuses a bad age bracket change, and a change that would overlap another bracket', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $other = AgeBracket::create(['min_age' => 18, 'max_age' => 40]);
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/settings/age-brackets/{$w->bracket->id}", ['min_age' => 'abc'], 422, 'min_age');
    Tamper::refused($this, 'PUT', "/settings/age-brackets/{$w->bracket->id}", ['min_age' => 5, 'max_age' => 2], 422, 'max_age');
    Tamper::refused($this, 'PUT', "/settings/age-brackets/{$w->bracket->id}", ['min_age' => 0, 'max_age' => 25], 422, 'min_age');
    Tamper::refused($this, 'PUT', "/settings/age-brackets/{$other->id}", ['min_age' => 10], 422, 'min_age');
});

it('refuses to delete list items with a bad reason', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', "/settings/diagnoses/{$w->lists['dengue']->id}", $data, 422, $field);
    Tamper::refused($this, 'DELETE', "/settings/ranks/{$w->lists['sgt']->id}", $data, 422, $field);
    Tamper::refused($this, 'DELETE', "/settings/age-brackets/{$w->bracket->id}", $data, 422, $field);
})->with('bad delete reasons');

it('refuses a bad rank move', function (mixed $direction) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/settings/ranks/{$w->lists['sgt']->id}/move", ['direction' => $direction], 422, 'direction');
})->with(['sideways', '', null, [['up']], 1, 'UP', 'up; DROP TABLE ranks']);

// --- Months ------------------------------------------------------------------------

it('refuses to close, reopen or delete a month that cannot be, with a message', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $reason = ['reason' => 'Encoded in the wrong month'];

    // A month that has not ended, a month in the future, a month that is already closed.
    Tamper::refused($this, 'POST', '/months/2026-10/close', [], 422, 'period');
    Tamper::refused($this, 'POST', '/months/2030-01/close', [], 422, 'period');
    $w->closedAugust();
    Tamper::refused($this, 'POST', '/months/2026-08/close', [], 422, 'period');

    // Reopen a month that was never closed.
    Tamper::refused($this, 'POST', '/months/2026-07/reopen', [], 422, 'period');

    // Delete the report of a closed month, of a month with no visits, and with a bad reason.
    Tamper::refused($this, 'DELETE', '/months/2026-08', $reason, 422, 'period');
    Tamper::refused($this, 'DELETE', '/months/2026-07', $reason, 422, 'period');
    $w->septemberVisit();
    Tamper::refused($this, 'DELETE', '/months/2026-09', ['reason' => 'because'], 422, 'reason');
    Tamper::refused($this, 'DELETE', '/months/2026-09', ['reason' => ['Wrong month']], 422, 'reason');
    Tamper::refused($this, 'DELETE', '/months/2026-09', ['reason' => 'Other (specify)', 'reason_other' => 'short'], 422, 'reason_other');
    Tamper::refused($this, 'DELETE', '/months/2026-09', [], 422, 'reason');
});

it('turns away odd month names at the route, before any code runs', function (string $period) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    foreach (['POST' => "/months/{$period}/close", 'POST ' => "/months/{$period}/reopen", 'DELETE' => "/months/{$period}"] as $method => $uri) {
        Tamper::refused($this, trim($method), $uri, ['reason' => 'Duplicate entry'], 404);
    }
})->with(['2026-13', '2026-00', '2026-9', '26-09', 'sept', '2026-09-01', '2026-09%20', '..%2F..%2Fetc', '2026-09;DROP', '2026-1a', '20260', '0000-01x']);

it('refuses a month before the system began or far in the past without a server error', function (string $period) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $before = SecurityWorld::state();

    foreach (['POST' => "/months/{$period}/reopen", 'DELETE' => "/months/{$period}"] as $method => $uri) {
        $response = $this->json($method, $uri, ['reason' => 'Duplicate entry']);
        expect($response->getStatusCode())->toBeIn([403, 404, 422], "{$method} {$uri}");
    }

    expect(SecurityWorld::state())->toBe($before);
})->with(['0001-01', '1999-12', '2025-12']);

it('answers odd report filters on the screen, print, PDF and Excel without a server error', function (string $page, string $query) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $response = $this->get("{$page}?{$query}");

    expect($response->getStatusCode())->toBeLessThan(500, "{$page}?{$query}");
})->with(function () {
    $queries = [
        'type=zzz', 'type[]=monthly', 'month=2026-99', 'month[]=2026-09', 'month=../../x', 'year=-1', 'year=99999', 'year[]=2026', 'quarter=9', 'quarter=-1', 'quarter[]=1',
        'category=zzz', 'category[]=er', 'category=SUMMARY', 'scope=all', 'scope[]=all', 'scope=zzz', 'from=zzz&to=zzz', 'type=custom&from=2026-13-45&to=2026-14-45',
        'type=custom&from=2026-12-31&to=2026-01-01', 'type=custom&from=0001-01-01&to=9999-12-31', 'type=custom&from[]=x&to[]=y', 'type=custom&from=2026-09-01', 'type=annual&year=1900',
        'type=quarterly&year=2026&quarter=7', 'tab=zzz', 'tab[]=er', 'q=%27%20OR%201%3D1--',
    ];

    foreach (['/reports', '/reports/print', '/reports/export/pdf', '/reports/export/excel'] as $page) {
        foreach ($queries as $query) {
            yield "{$page}?{$query}" => [$page, $query];
        }
    }
});

it('never serves a frozen signature or a picture for an odd span or name', function (string $uri) {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->closedAugust();
    $this->actingAs($w->actor);

    expect($this->get($uri)->getStatusCode())->toBeIn([403, 404], $uri);
})->with([
    '/reports/signatures/2026-08/other',
    '/reports/signatures/2026-08/../../.env',
    '/reports/signatures/..%2F..%2F.env/noted',
    '/reports/signatures/2026-99/noted',
    '/reports/signatures/2026-Q9/noted',
    '/reports/signatures/2026-09/noted',
    '/reports/signatures/2026-08/NOTED',
    '/reports/signatures/2026-08/noted%00.png',
    '/reports/signatures/2026-08/prepared/extra',
    '/settings/hospital/signature/other',
    '/settings/hospital/signature/..%2F..%2F.env',
    '/settings/hospital/signature/noted_signature',
    '/settings/hospital/logo/extra',
]);

// --- Trash ---------------------------------------------------------------------------

it('says "no longer in Trash" and changes nothing when restoring or deleting a record that is not in Trash', function (string $type) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $ids = ['visits' => $w->visit->id, 'patients' => $w->patient->id, 'users' => $w->otherUser->id, 'diagnoses' => $w->lists['dengue']->id, 'ranks' => $w->lists['sgt']->id, 'age-brackets' => $w->bracket->id];
    $id = $ids[$type];

    $before = SecurityWorld::state();

    $restore = $this->json('POST', "/trash/{$type}/{$id}/restore");
    $destroy = $this->json('DELETE', "/trash/{$type}/{$id}", ['confirm' => 'anything']);

    expect($restore->getStatusCode())->toBeIn([302, 404])
        ->and($destroy->getStatusCode())->toBeIn([302, 404, 422])
        ->and(SecurityWorld::state())->toBe($before);
})->with(['visits', 'patients', 'users', 'diagnoses', 'ranks', 'age-brackets']);

it('turns away odd types and ids in the Trash address', function (string $uri) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', $uri.'/restore', [], 404);
    Tamper::refused($this, 'DELETE', $uri, ['confirm' => 'x'], 404);
})->with([
    '/trash/settings/1', '/trash/audit_logs/1', '/trash/visits/abc', '/trash/visits/-1', '/trash/visits/1.5', '/trash/VISITS/1',
    '/trash/visits/1%20OR%201=1', '/trash/visits/99999999999999999999', '/trash/..%2F..%2Fusers/1', '/trash/visit/1', '/trash/visits',
]);

it('refuses a permanent delete without the exact confirm text', function (mixed $confirm) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', "/trash/visits/{$w->trashedVisit->id}", $confirm === 'MISSING' ? [] : ['confirm' => $confirm], 422, 'confirm');
    Tamper::refused($this, 'DELETE', "/trash/patients/{$w->trashedPatient->id}", $confirm === 'MISSING' ? [] : ['confirm' => $confirm], 422, 'confirm');
})->with(['MISSING', '', 'wrong', [['x']], [['confirm']], 123, 'V-000001 ', 'P-000999', 'EMPTY TRASH', '%']);

it('refuses to empty Trash without the exact words', function (mixed $confirm) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', '/trash', $confirm === 'MISSING' ? [] : ['confirm' => $confirm], 422, 'confirm');
})->with(['MISSING', '', 'empty', 'EMPTY', 'EMPTY TRASHH', [['EMPTY TRASH']], 0, 'DELETE ALL']);

it('blocks a permanent delete that would break the records, and says why', function (string $case) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $trash = fn ($model) => $model->forceFill(['deleted_at' => now(), 'deleted_by' => $w->admin()->id, 'delete_reason' => 'Duplicate entry'])->saveQuietly();

    [$type, $item, $confirm] = match ($case) {
        'a visit in a closed month' => (function () use ($w, $trash) {
            $visit = $w->closedMonthVisit();
            $trash($visit);

            return ['visits', $visit, $visit->visit_no];
        })(),
        'a patient who still has visits' => (function () use ($w, $trash) {
            $trash($w->visit->patient);

            return ['patients', $w->visit->patient()->withTrashed()->first(), $w->visit->patient()->withTrashed()->first()->patient_no];
        })(),
        'a diagnosis used by a visit' => (function () use ($w, $trash) {
            $trash($w->lists['hypertension']);

            return ['diagnoses', $w->lists['hypertension'], $w->lists['hypertension']->name];
        })(),
        'a rank used by a visit' => (function () use ($w, $trash) {
            $trash($w->rank);

            return ['ranks', $w->rank, $w->rank->name];
        })(),
        'a user who has visits' => (function () use ($w, $trash) {
            $trash($w->encoder);

            return ['users', $w->encoder, $w->encoder->username];
        })(),
        'a user who has audit entries' => (function () use ($w, $trash) {
            App\Models\AuditLog::create(['user_id' => $w->otherUser->id, 'action' => 'test.entry', 'subject_type' => 'users', 'subject_id' => $w->otherUser->id, 'changes' => [], 'ip_address' => '127.0.0.1']);
            $trash($w->otherUser);

            return ['users', $w->otherUser, $w->otherUser->username];
        })(),
    };

    Tamper::refused($this, 'DELETE', "/trash/{$type}/{$item->id}", ['confirm' => $confirm], 422, 'item');
})->with([
    'a visit in a closed month',
    'a patient who still has visits',
    'a diagnosis used by a visit',
    'a rank used by a visit',
    'a user who has visits',
    'a user who has audit entries',
]);

it('keeps the Trash list intact when "Empty Trash" meets records it must keep', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $closed = $w->closedMonthVisit();
    $closed->forceFill(['deleted_at' => now(), 'deleted_by' => $w->admin()->id, 'delete_reason' => 'Duplicate entry'])->saveQuietly();

    $this->json('DELETE', '/trash', ['confirm' => 'EMPTY TRASH'])->assertRedirect();

    expect(Visit::withTrashed()->whereKey($closed->id)->exists())->toBeTrue()
        ->and(Visit::withTrashed()->whereKey($w->trashedVisit->id)->exists())->toBeFalse()
        ->and(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeTruthy();
});

it('does not restore a visit whose patient is still in Trash without saying so, or a visit into a closed month', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    // The trashed visit's patient goes to Trash too.
    $w->trashedVisit->patient->forceFill(['deleted_at' => now(), 'deleted_by' => $w->admin()->id, 'delete_reason' => 'Duplicate patient'])->saveQuietly();

    $response = $this->json('POST', "/trash/visits/{$w->trashedVisit->id}/restore");
    expect($response->getStatusCode())->toBeLessThan(500);

    $visit = $w->closedMonthVisit();
    $visit->forceFill(['deleted_at' => now(), 'deleted_by' => $w->admin()->id, 'delete_reason' => 'Duplicate entry'])->saveQuietly();
    $before = SecurityWorld::state();
    $closedResponse = $this->json('POST', "/trash/visits/{$visit->id}/restore");

    expect($closedResponse->getStatusCode())->toBeLessThan(500);

    // Whatever the rule says, a visit in a closed month never comes back into the report.
    if (Visit::whereKey($visit->id)->exists()) {
        $this->fail('A visit in a closed month was restored.');
    }

    expect(SecurityWorld::state())->toBe($before);
});
