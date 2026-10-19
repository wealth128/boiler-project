<?php

use App\Models\AgeBracket;
use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Services\MonthService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Close and reopen share ONE lock per year, "month-close:YYYY". A quarter's
 * or a year's snapshot depends on the other months of the same year, so two
 * months of one quarter closed at the same moment, or a reopen in the middle
 * of a close, must take turns. The wait is set to 0 here, so a busy lock
 * answers at once. "Today" is 15-Oct-2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');
    config(['census.lock_wait_seconds' => 0]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    $this->admin = userWithRole('admin');
    $this->months = app(MonthService::class);
});

/** Someone else is closing a month of $year right now. */
function yearBusy(string $year = '2026'): Lock
{
    $lock = Cache::lock("month-close:{$year}", 60);
    expect($lock->get())->toBeTrue();

    return $lock;
}

it('makes closing August wait while July of the same year is being closed', function () {
    $busy = yearBusy();

    expect(fn () => $this->months->close('2026-08', $this->admin))
        ->toThrow(ValidationException::class, 'August 2026 is being closed right now.');

    expect(MonthClosure::where('period', '2026-08')->exists())->toBeFalse();

    $busy->release();
    $this->months->close('2026-08', $this->admin);

    expect(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeTrue();
});

it('does not hold up a month of another year', function () {
    $busy = yearBusy('2025');

    $this->months->close('2026-08', $this->admin);

    expect(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeTrue();
    $busy->release();
});

it('refuses a reopen while a month of that year is being closed, and leaves everything as it was', function () {
    $this->months->close('2026-07', $this->admin);
    $this->months->close('2026-08', $this->admin);
    $this->months->close('2026-09', $this->admin);
    expect(ReportSnapshot::where('period', '2026-Q3')->exists())->toBeTrue();

    $busy = yearBusy();

    expect(fn () => $this->months->reopen('2026-08', $this->admin))
        ->toThrow(ValidationException::class, "can't be reopened while a month of that year is being closed");

    // Still closed, quarter snapshot intact.
    expect(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeTrue()
        ->and(ReportSnapshot::where('period', '2026-Q3')->exists())->toBeTrue();

    $busy->release();
    $this->months->reopen('2026-08', $this->admin);

    expect(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeFalse()
        ->and(ReportSnapshot::where('period', '2026-Q3')->exists())->toBeFalse();
});

it('makes the quarter snapshot when the last of its months is closed, however they were closed', function () {
    $this->months->close('2026-07', $this->admin);
    $this->months->close('2026-08', $this->admin);

    expect(ReportSnapshot::where('period', '2026-Q3')->exists())->toBeFalse();

    $this->months->close('2026-09', $this->admin);

    expect(ReportSnapshot::where('period', '2026-Q3')->exists())->toBeTrue();
});

it('releases the year lock after close, after reopen and after a refused close or reopen', function () {
    $this->months->close('2026-07', $this->admin);
    expect(fn () => $this->months->close('2026-07', $this->admin))->toThrow(ValidationException::class, 'already closed');

    $check = Cache::lock('month-close:2026', 5);
    expect($check->get())->toBeTrue();
    $check->release();

    $this->months->reopen('2026-07', $this->admin);
    expect(fn () => $this->months->reopen('2026-07', $this->admin))->toThrow(ValidationException::class, 'is not closed');

    $check = Cache::lock('month-close:2026', 5);
    expect($check->get())->toBeTrue();
    $check->release();
});

it('no longer uses a lock per month', function () {
    $source = file_get_contents(app_path('Services/MonthService.php'));

    expect($source)->toContain("'month-close:'.substr(\$period, 0, 4)")
        ->not->toContain('"month-close:{$period}"');
});
