<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Visit;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\ReportDataVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Step 7d-4, fix 7: php artisan census:rebuild-snapshots counts every saved copy
 * of a closed month, quarter and year again with the current report version. The
 * signature block of each period, who saved it and when stay as they were.
 */

const REBUILD_PROMPT = 'Type REBUILD to continue';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $branch = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $rank = Rank::factory()->for($branch)->create(['name' => 'CPL', 'sort_order' => 1]);
    $diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    foreach (['2026-07-10 09:00', '2026-08-10 09:00', '2026-09-10 09:00'] as $when) {
        Visit::factory()->create([
            'patient_id' => Patient::factory()->create()->id,
            'visited_at' => Carbon::parse($when, 'Asia/Manila'),
            'category' => 'ER',
            'age' => 30,
            'rank_id' => $rank->id,
            'branch_id' => $branch->id,
            'diagnosis_id' => $diagnosis->id,
            'encoded_by' => $this->admin->id,
        ]);
    }
});

/** Close July, August and September (this also saves the copy of Q3), then make every copy look old. */
function closedQuarterWithOldCopies(object $test): void
{
    $months = app(MonthService::class);

    foreach (['2026-07', '2026-08', '2026-09'] as $period) {
        $months->close($period, $test->admin);
    }

    foreach (ReportSnapshot::all() as $snapshot) {
        $data = $snapshot->data;
        $data['version'] = 3;
        $data['old_shape'] = true;
        $snapshot->forceFill([
            'data' => $data,
            'signature_block' => ['prepared_name' => 'Frozen '.$snapshot->period, 'prepared_title' => 'Nurse'],
        ])->save();
    }
}

it('rebuilds every saved copy at the current version and keeps the frozen signatures', function () {
    closedQuarterWithOldCopies($this);

    $before = ReportSnapshot::query()->orderBy('period')->get()->keyBy('period');
    expect($before->keys()->all())->toBe(['2026-07', '2026-08', '2026-09', '2026-Q3']);

    $this->travel(2)->days();

    $this->artisan('census:rebuild-snapshots')
        ->expectsOutputToContain('Saved copies: 4')
        ->expectsQuestion(REBUILD_PROMPT, 'REBUILD')
        ->expectsOutputToContain('Rebuilt 4 saved copies')
        ->assertSuccessful();

    $after = ReportSnapshot::query()->orderBy('period')->get()->keyBy('period');

    expect($after->keys()->all())->toBe(['2026-07', '2026-08', '2026-09', '2026-Q3']);

    foreach ($after as $period => $snapshot) {
        expect($snapshot->data['version'])->toBe(ReportBuilder::VERSION)
            ->and($snapshot->data)->not->toHaveKey('old_shape')
            ->and($snapshot->signature_block)->toBe($before[$period]->signature_block)
            ->and($snapshot->created_by)->toBe($before[$period]->created_by)
            ->and($snapshot->created_at->equalTo($before[$period]->created_at))->toBeTrue()
            ->and($snapshot->id)->toBe($before[$period]->id);
    }

    // The numbers are the ones counted now: a month with one visit, a quarter with three.
    expect($after['2026-09']->data['totals']['visits'])->toBe(1)
        ->and($after['2026-Q3']->data['totals']['visits'])->toBe(3)
        ->and($after['2026-Q3']->data)->toEqual(app(ReportBuilder::class)->build(
            Carbon::parse('2026-07-01', 'Asia/Manila'),
            Carbon::parse('2026-10-01', 'Asia/Manila'),
        ));
});

it('rebuilds a year copy as well', function () {
    $months = app(MonthService::class);

    // A year with all twelve months closed would need visits dated in the future; save the key directly.
    closedQuarterWithOldCopies($this);
    ReportSnapshot::create(['period' => '2026', 'data' => ['version' => 3], 'signature_block' => ['prepared_name' => 'Year'], 'created_by' => $this->admin->id]);

    $this->artisan('census:rebuild-snapshots')
        ->expectsQuestion(REBUILD_PROMPT, 'REBUILD')
        ->assertSuccessful();

    $year = ReportSnapshot::query()->where('period', '2026')->sole();

    expect($year->data['version'])->toBe(ReportBuilder::VERSION)
        ->and($year->data['totals']['visits'])->toBe(3)
        ->and($year->signature_block)->toBe(['prepared_name' => 'Year']);
});

it('writes the audit entry snapshots.rebuilt with the count', function () {
    closedQuarterWithOldCopies($this);

    $this->artisan('census:rebuild-snapshots')
        ->expectsQuestion(REBUILD_PROMPT, 'REBUILD')
        ->assertSuccessful();

    $entry = AuditLog::where('action', 'snapshots.rebuilt')->sole();

    expect($entry->changes['count'])->toBe(4)
        ->and($entry->changes['version'])->toBe(ReportBuilder::VERSION)
        ->and($entry->changes['via'])->toBe('census:rebuild-snapshots');
});

it('raises the report data version, so a remembered report is not used afterwards', function () {
    closedQuarterWithOldCopies($this);
    $before = ReportDataVersion::current();

    $this->artisan('census:rebuild-snapshots')->expectsQuestion(REBUILD_PROMPT, 'REBUILD')->assertSuccessful();

    expect(ReportDataVersion::current())->not->toBe($before);
});

it('changes nothing unless REBUILD is typed', function (string $answer) {
    closedQuarterWithOldCopies($this);
    $before = DB::table('report_snapshots')->orderBy('id')->get()->toJson();

    $this->artisan('census:rebuild-snapshots')
        ->expectsQuestion(REBUILD_PROMPT, $answer)
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    expect(DB::table('report_snapshots')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(AuditLog::where('action', 'snapshots.rebuilt')->exists())->toBeFalse();
})->with(['rebuild', 'yes', '', 'REBUILD now']);

it('says so when no month is closed, and asks nothing', function () {
    $this->artisan('census:rebuild-snapshots')
        ->expectsOutputToContain('There are no saved copies yet')
        ->assertSuccessful();

    expect(AuditLog::where('action', 'snapshots.rebuilt')->exists())->toBeFalse();
});

it('refuses, and changes nothing, while a month of that year is being closed', function () {
    closedQuarterWithOldCopies($this);
    $before = DB::table('report_snapshots')->orderBy('id')->get()->toJson();

    $lock = Cache::lock('month-close:2026', 60);
    $lock->get();
    config(['census.lock_wait_seconds' => 0]);

    try {
        $this->artisan('census:rebuild-snapshots')
            ->expectsQuestion(REBUILD_PROMPT, 'REBUILD')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();
    } finally {
        $lock->release();
    }

    expect(DB::table('report_snapshots')->orderBy('id')->get()->toJson())->toBe($before);
});

it('is documented in the operations guide', function () {
    $guide = (string) file_get_contents(base_path('../docs/operations-guide.md'));

    expect($guide)->toContain('census:rebuild-snapshots')->toContain('REBUILD');
});
