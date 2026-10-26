<?php

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\AuditPruneRun;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Models\TestRestoreRun;
use App\Services\AdminAlerts;
use App\Services\HospitalSettings;
use App\Services\SchedulerHeartbeat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Step 7g: the props every page gets (HandleInertiaRequests) are cheap. The
 * Admin warning bars and the top bar's hospital name and logo are worked out
 * at most once a minute; any change that affects them forgets them at once,
 * and a problem that comes only with time shows within 60 seconds.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    $this->admin = userWithRole('admin');
    app(SchedulerHeartbeat::class)->beat();

    // A recent good backup, USB copy and test restore: no bar at all.
    BackupRun::create(['started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'status' => BackupStatus::Success, 'file_name' => 'patient_census_x.sql']);

    foreach (BackupMarkType::cases() as $type) {
        BackupMark::create(['type' => $type, 'marked_by' => null, 'marked_at' => now()->subDay()]);
    }
});

/**
 * The tables a request read from.
 *
 * @return list<string>
 */
function propTables(callable $call): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $call();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())
        ->map(fn (array $q): string => preg_match('/from [`"]?(\w+)/i', $q['query'], $m) ? $m[1] : '')
        ->filter()
        ->values()
        ->all();
}

/** @return list<string> the bar texts on an Admin page */
function bars(object $test): array
{
    $props = $test->actingAs($test->admin)->get('/settings/lists')->viewData('page')['props'];

    return [...array_column($props['backupAlerts'], 'message'), ...array_filter([$props['schedulerAlert']])];
}

it('works the warning bars out once, then reads them from the cache on the next pages', function () {
    $this->actingAs($this->admin);

    $first = propTables(fn () => $this->get('/settings/lists')->assertOk());
    $second = propTables(fn () => $this->get('/settings/lists')->assertOk());

    $alertTables = ['backup_runs', 'backup_marks', 'test_restore_runs', 'audit_prune_runs'];

    expect(array_intersect($first, $alertTables))->not->toBeEmpty()
        ->and(array_intersect($second, $alertTables))->toBeEmpty();
});

it('never works the bars out for an Encoder', function () {
    $tables = propTables(fn () => $this->actingAs(userWithRole('encoder'))->get('/encode')->assertOk());

    expect(array_intersect($tables, ['backup_runs', 'backup_marks', 'test_restore_runs', 'audit_prune_runs']))->toBeEmpty();
});

it('shows a failed backup on the very next page', function () {
    expect(bars($this))->toBe([]);

    BackupRun::create(['started_at' => now(), 'finished_at' => now(), 'status' => BackupStatus::Failed, 'message' => 'disk full']);

    expect(bars($this))->toBe(['The last backup failed. Tell IT.']);
});

it('shows a failed test restore on the very next page', function () {
    expect(bars($this))->toBe([]);

    TestRestoreRun::create(['trigger' => 'manual', 'status' => BackupStatus::Failed, 'started_at' => now(), 'finished_at' => now(), 'message' => 'counts differ']);

    expect(bars($this))->toBe(['The test restore failed. Tell IT.']);
});

it('removes the USB reminder on the very next page after a USB copy', function () {
    BackupMark::query()->where('type', BackupMarkType::UsbCopy)->delete();
    expect(bars($this)[0])->toContain('The weekly USB copy is overdue');

    BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $this->admin->id, 'marked_at' => now()]);

    expect(bars($this))->toBe([]);
});

it('shows a failed audit log clean-up on the very next page', function () {
    expect(bars($this))->toBe([]);

    AuditPruneRun::create(['started_at' => now(), 'finished_at' => now(), 'status' => BackupStatus::Failed, 'rows_count' => 0, 'message' => 'archive folder missing']);

    expect(bars($this))->toBe(['Audit log clean-up failed: archive folder missing']);
});

it('shows a stopped scheduler within 60 seconds and removes it as soon as it beats again', function () {
    expect(bars($this))->toBe([]);

    // 4 min 50 s: still fine, remembered until 5 min 50 s.
    $this->travel(4 * 60 + 50)->seconds();
    expect(bars($this))->toBe([]);

    // 5 min 20 s: the scheduler is late, but the remembered "all fine" is younger than 60 seconds.
    $this->travel(30)->seconds();
    expect(bars($this))->toBe([]);

    // 5 min 51 s: remembered for more than 60 seconds, worked out again.
    $this->travel(31)->seconds();
    expect(bars($this))->toBe(['Automatic tasks are not running (no backup, no 8 PM sign-out). Tell IT.']);

    app(SchedulerHeartbeat::class)->beat();
    expect(bars($this))->toBe([]);
});

it('shows a backup that is now too old within 60 seconds, with nothing written', function () {
    // The last good backup is 30 seconds short of the 26-hour limit.
    BackupRun::query()->delete();
    BackupRun::create(['started_at' => now()->subHours(26)->addSeconds(30), 'finished_at' => now()->subHours(26)->addSeconds(30), 'status' => BackupStatus::Success, 'file_name' => 'patient_census_y.sql']);
    expect(bars($this))->toBe([]);

    $this->travel(61)->seconds();

    expect(bars($this)[0])->toContain('No successful backup since');
});

it('reads the hospital name and logo once a minute and shows a new name at once', function () {
    Setting::create(['key' => 'hospital_name', 'value' => 'AFP General Hospital']);
    $this->actingAs($this->admin);

    $first = propTables(fn () => $this->get('/settings/lists')->assertOk());
    $second = propTables(fn () => $this->get('/settings/lists')->assertOk());

    expect($first)->toContain('settings')
        ->and($second)->not->toContain('settings');

    Setting::query()->updateOrCreate(['key' => 'hospital_name'], ['value' => 'V. Luna Medical Center']);

    $this->get('/settings/lists')->assertInertia(fn ($page) => $page->where('hospital.name', 'V. Luna Medical Center'));
});

it('shows a new logo at once, with a new address', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);

    $this->get('/settings/lists')->assertInertia(fn ($page) => $page->where('hospital.logo_url', null));

    Storage::disk('local')->put('logo.png', 'x');
    Setting::query()->updateOrCreate(['key' => 'logo_path'], ['value' => 'logo.png']);

    $this->get('/settings/lists')->assertInertia(fn ($page) => $page
        ->where('hospital.logo_url', fn (string $url) => str_starts_with($url, '/settings/hospital/logo?v='))
        ->where('hospital.login_logo_url', fn (string $url) => str_starts_with($url, '/hospital-logo?v=')));
});

it('keeps the bars of one minute in a single cache entry', function () {
    bars($this);

    expect(cache()->get(AdminAlerts::KEY))->toBe(['alerts' => [], 'scheduler' => null])
        ->and(cache()->has(HospitalSettings::SHARED_KEY))->toBeTrue();
});
