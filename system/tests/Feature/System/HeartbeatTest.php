<?php

use App\Services\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The scheduler saves a "last ran" time every minute. When it is more than
 * 5 minutes old, Admin (and only Admin) sees a red bar on every page, with a
 * link to System health. Times are Asia/Manila.
 */

const BAR = 'Automatic tasks are not running (no backup, no 8 PM sign-out). Tell IT.';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'Asia/Manila'));
    Cache::forget(SchedulerHeartbeat::KEY);
});

/** The shared prop the red bar reads, as seen by $user on $uri. */
function barFor(object $test, $user, string $uri = '/encode'): mixed
{
    $bar = 'unset';

    $test->actingAs($user)->get($uri)->assertOk()
        ->assertInertia(function (Assert $page) use (&$bar) {
            $bar = $page->toArray()['props']['schedulerAlert'];
        });

    return $bar;
}

it('is scheduled to save the time every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');

    // What the scheduled task does.
    $event->run(app());

    expect(app(SchedulerHeartbeat::class)->lastBeat()?->getTimestamp())->toBe(now()->getTimestamp());
});

it('shows Admin the red bar when the scheduler never ran', function () {
    expect(barFor($this, userWithRole('admin')))->toBe(BAR);
});

it('shows Admin the red bar only after more than 5 minutes', function (int $minutesAgo, bool $shown) {
    Cache::forever(SchedulerHeartbeat::KEY, now()->subMinutes($minutesAgo)->getTimestamp());

    expect(barFor($this, userWithRole('admin')))->toBe($shown ? BAR : null);
})->with([
    'just now' => [0, false],
    '4 minutes ago' => [4, false],
    '5 minutes ago' => [5, false],
    '6 minutes ago' => [6, true],
    'an hour ago' => [60, true],
]);

it('clears the bar as soon as the scheduler runs again', function () {
    $admin = userWithRole('admin');
    Cache::forever(SchedulerHeartbeat::KEY, now()->subMinutes(30)->getTimestamp());
    expect(barFor($this, $admin))->toBe(BAR);

    app(SchedulerHeartbeat::class)->beat();

    expect(barFor($this, $admin))->toBeNull();
});

it('shows the bar to Admin only, even when the scheduler is stopped', function (string $role, string $uri) {
    expect(barFor($this, userWithRole($role), $uri))->toBeNull();
})->with([
    'encoder' => ['encoder', '/encode'],
    'viewer' => ['viewer', '/reports'],
    'system admin' => ['system_admin', '/users'],
]);

it('shows the bar on every Admin page', function (string $uri) {
    expect(barFor($this, userWithRole('admin'), $uri))->toBe(BAR);
})->with(['/encode', '/records/visits', '/reports', '/users', '/trash', '/backups', '/system/health']);

it('does not send the bar to a signed-out visitor', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('schedulerAlert', null));
});

it('builds the bar with a link to System health in the page layout', function () {
    $source = file_get_contents(resource_path('js/components/shared/scheduler-alert.tsx'));

    expect($source)->toContain("role !== 'admin'")
        ->toContain('system.health()')
        ->toContain('Open System health');
});
