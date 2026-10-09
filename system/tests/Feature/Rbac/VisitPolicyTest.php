<?php

use App\Models\MonthClosure;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * VisitPolicy (planning/rbac.md): an encoder edits only their own visit on
 * the day it was encoded; Admin edits any; nobody edits or deletes a visit
 * while its month is closed. Checked through the real routes
 * (PUT and DELETE /visits/{visit}). Times are Asia/Manila.
 */

function closeMonth(string $period, bool $closed = true): void
{
    MonthClosure::create([
        'period' => $period,
        'is_closed' => $closed,
        'closed_by' => userWithRole('admin')->id,
        'closed_at' => now(),
    ]);
}

function assertDeniedWith($response, string $message): void
{
    $response->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 403)
            ->where('message', $message));
}

const NOT_YOURS = 'You can only edit visits you encoded. Ask the Admin to correct this one.';
const NOT_TODAY = 'You can only edit your own visits on the day you encoded them. Ask the Admin to correct this one.';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
});

// --- Same-day rule (encoders) --------------------------------------------

it('lets an encoder edit their own visit on the same day', function () {
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->setTime(8, 0)]);

    $this->actingAs($encoder)->from('/encode')
        ->put("/visits/{$visit->id}")
        ->assertRedirect('/encode');
});

it('blocks an encoder from editing their own visit from yesterday', function () {
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->subDay()]);

    assertDeniedWith($this->actingAs($encoder)->put("/visits/{$visit->id}"), NOT_TODAY);
});

it('uses the Manila calendar day, not 24 hours', function () {
    $encoder = userWithRole('encoder');
    $lateLastNight = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => Carbon::parse('2026-10-08 23:59', 'Asia/Manila')]);
    $earlyToday = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => Carbon::parse('2026-10-09 00:01', 'Asia/Manila')]);

    $this->travelTo(Carbon::parse('2026-10-09 00:05', 'Asia/Manila'));
    expect(Gate::forUser($encoder)->allows('update', $lateLastNight))->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-09 23:58', 'Asia/Manila'));
    expect(Gate::forUser($encoder)->allows('update', $earlyToday))->toBeTrue();
});

it("blocks an encoder from editing another encoder's visit, even today", function () {
    $visit = Visit::factory()->create(['visited_at' => now()]);

    assertDeniedWith($this->actingAs(userWithRole('encoder'))->put("/visits/{$visit->id}"), NOT_YOURS);
});

it('lets Admin edit any visit, from any day', function () {
    $visit = Visit::factory()->create(['visited_at' => now()->subMonths(2)]);

    $this->actingAs(userWithRole('admin'))->from('/records/visits')
        ->put("/visits/{$visit->id}")
        ->assertRedirect('/records/visits');
});

it('never lets System Admin or the Viewer edit a visit', function (string $role) {
    $user = userWithRole($role);
    $visit = Visit::factory()->create(['encoded_by' => $user->id, 'visited_at' => now()]);

    expect(Gate::forUser($user)->allows('update', $visit))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $visit))->toBeFalse();
})->with(['system_admin', 'viewer']);

it('never lets an encoder delete a visit, even their own', function () {
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()]);

    expect(Gate::forUser($encoder)->allows('delete', $visit))->toBeFalse();
});

// --- Closed month ----------------------------------------------------------

it('blocks Admin from editing a visit in a closed month', function () {
    closeMonth('2026-09');
    $visit = Visit::factory()->create(['visited_at' => '2026-09-15 10:00']);

    assertDeniedWith(
        $this->actingAs(userWithRole('admin'))->put("/visits/{$visit->id}"),
        'September 2026 is closed. The Admin must reopen it first.',
    );
});

it('blocks Admin from deleting a visit in a closed month', function () {
    closeMonth('2026-09');
    $visit = Visit::factory()->create(['visited_at' => '2026-09-15 10:00']);

    assertDeniedWith(
        $this->actingAs(userWithRole('admin'))->delete("/visits/{$visit->id}"),
        'September 2026 is closed. The Admin must reopen it first.',
    );
});

it('blocks an encoder from editing their own same-day visit once the month is closed', function () {
    closeMonth('2026-10');
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()]);

    assertDeniedWith(
        $this->actingAs($encoder)->put("/visits/{$visit->id}"),
        'October 2026 is closed. The Admin must reopen it first.',
    );
});

it('allows edits again after the month is reopened', function () {
    closeMonth('2026-09', closed: false);
    $visit = Visit::factory()->create(['visited_at' => '2026-09-15 10:00']);

    $this->actingAs(userWithRole('admin'))->from('/records/visits')
        ->put("/visits/{$visit->id}")
        ->assertRedirect('/records/visits');
});

it('only locks the closed month, not the months around it', function () {
    closeMonth('2026-09');
    $august = Visit::factory()->create(['visited_at' => '2026-08-31 23:00']);
    $october = Visit::factory()->create(['visited_at' => '2026-10-01 00:30']);
    $admin = userWithRole('admin');

    expect(Gate::forUser($admin)->allows('update', $august))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $october))->toBeTrue();
});
