<?php

use App\Models\BackupMark;
use App\Models\TestRestoreRun;
use App\Services\BackupMonitor;
use App\Services\HealthChecker;
use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Step 7b: System health is sorted Problems, Warnings, OK; it is paged
 * (10, 20 or 50) and has a small filter (?status=) kept in the address. The
 * counts at the top always cover every check. Disk space and the database are
 * replaced by a stand-in checker, as in HealthTest.
 */

const PAGING_GB = 1024 ** 3;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'Asia/Manila'));
    $this->base = storage_path('framework/testing/health-paging-'.uniqid());
    $this->folder = $this->base.'/backups';
    File::ensureDirectoryExists($this->folder);

    config([
        'census.backup.path' => $this->folder,
        'census.version' => '1.2.3',
    ]);

    app()['env'] = 'production';
    config(['app.debug' => false]);
    app(SchedulerHeartbeat::class)->beat();
    BackupMark::query()->delete();
    TestRestoreRun::query()->delete();
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

/**
 * A server with a few Problems (no database, no backup) and Warnings (low
 * disk space, no USB copy) among the 17 checks.
 */
function unhealthyChecker(object $test): void
{
    $folder = $test->folder;

    app()->bind(HealthChecker::class, fn ($app) => new class($app->make(BackupMonitor::class), $app->make(SchedulerHeartbeat::class), $folder) extends HealthChecker
    {
        public function __construct(BackupMonitor $backups, SchedulerHeartbeat $heartbeat, private string $folder)
        {
            parent::__construct($backups, $heartbeat);
        }

        protected function freeSpace(string $path): ?int
        {
            return 5 * PAGING_GB;
        }

        protected function pingDatabase(): void
        {
            throw new RuntimeException('down');
        }

        protected function mysqlClientWorks(): bool
        {
            return true;
        }

        protected function rootHasNoPassword(): bool
        {
            return false;
        }

        protected function phpVersion(): string
        {
            return '8.4.5';
        }
    });
}

/** @return array{problem: int, warning: int, ok: int, total: int} */
function healthCounts(): array
{
    return HealthChecker::counts(app(HealthChecker::class)->checks());
}

it('sorts Problems first, then Warnings, then OK, and keeps each group in the order the checks run', function () {
    unhealthyChecker($this);
    $counts = healthCounts();

    expect($counts['problem'])->toBeGreaterThan(0)
        ->and($counts['warning'])->toBeGreaterThan(0)
        ->and($counts['ok'])->toBeGreaterThan(0);

    $this->actingAs(userWithRole('admin'))->get('/system/health?per_page=50')
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($counts) {
            $rows = collect($page->toArray()['props']['checks']['data']);
            $rank = ['problem' => 0, 'warning' => 1, 'ok' => 2];
            $ranks = $rows->map(fn ($row) => $rank[$row['status']])->all();
            $sorted = $ranks;
            sort($sorted);

            expect($ranks)->toBe($sorted)
                ->and($rows->first()['status'])->toBe('problem')
                ->and($rows->last()['status'])->toBe('ok')
                ->and($rows)->toHaveCount($counts['total']);

            $page->where('counts', $counts)->where('overall', 'problem')->etc();
        });

    // Inside a group the order of the checks is kept (database runs before the backup checks).
    $problems = collect(app(HealthChecker::class)->checks())->where('status', 'problem')->pluck('key')->values()->all();

    $this->get('/system/health?status=problem&per_page=50')
        ->assertInertia(function (Assert $page) use ($problems) {
            expect(collect($page->toArray()['props']['checks']['data'])->pluck('key')->all())->toBe($problems);
        });
});

it('keeps every problem and warning on page 1 of the default 10 rows', function () {
    unhealthyChecker($this);
    $counts = healthCounts();

    $this->actingAs(userWithRole('admin'))->get('/system/health')
        ->assertInertia(function (Assert $page) use ($counts) {
            $statuses = collect($page->toArray()['props']['checks']['data'])->pluck('status');

            expect($statuses)->toHaveCount(10)
                ->and($statuses->reject(fn ($s) => $s === 'ok')->count())->toBe($counts['problem'] + $counts['warning']);
        });
});

it('pages the checks 10 at a time and keeps the counts on every page', function () {
    unhealthyChecker($this);
    $counts = healthCounts();

    $this->actingAs(userWithRole('admin'))->get('/system/health')
        ->assertInertia(fn (Assert $page) => $page
            ->has('checks.data', 10)
            ->where('checks.meta', [
                'current_page' => 1, 'last_page' => 2, 'per_page' => 10,
                'total' => 17, 'from' => 1, 'to' => 10, 'pages' => [1, 2],
            ])
            ->where('counts', $counts)
            ->etc());

    $this->get('/system/health?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('checks.data', 7)
            ->where('checks.meta.current_page', 2)
            ->where('counts', $counts)
            ->etc());

    $this->get('/system/health?page=9')->assertRedirect('/system/health?page=2');
});

it('filters by Problems, Warnings or OK from ?status= and keeps the filter in the paging', function () {
    unhealthyChecker($this);
    $counts = healthCounts();
    $admin = userWithRole('admin');

    foreach (['problem', 'warning', 'ok'] as $status) {
        $this->actingAs($admin)->get('/system/health?per_page=50&status='.$status)
            ->assertInertia(function (Assert $page) use ($status, $counts) {
                $page->where('filter', $status)->where('checks.meta.total', $counts[$status])->where('counts', $counts)->etc();

                expect(collect($page->toArray()['props']['checks']['data'])->pluck('status')->unique()->all())->toBe([$status]);
            });
    }

    // Past the end of a filtered list goes to its last page, keeping the filter and the size.
    $this->get('/system/health?status=ok&per_page=10&page=9')
        ->assertRedirect('/system/health?status=ok&per_page=10&page=1');
});

it('shows every check for an unknown filter', function (mixed $odd) {
    unhealthyChecker($this);

    $this->actingAs(userWithRole('admin'))->get('/system/health?'.http_build_query(['status' => $odd]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filter', 'all')->where('checks.meta.total', 17)->etc());
})->with([
    'unknown word' => ['bogus'],
    'empty' => [''],
    'capitals' => ['PROBLEM'],
    'a list' => [['problem']],
]);

it('says how many problems and warnings there are, even with a filter and a short list', function () {
    unhealthyChecker($this);
    $counts = healthCounts();

    $this->actingAs(userWithRole('admin'))->get('/system/health?status=ok')
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.problem', $counts['problem'])
            ->where('counts.warning', $counts['warning'])
            ->etc());
});

it('shows the page text for the counts and the filter', function () {
    $page = file_get_contents(resource_path('js/pages/system/health.tsx'));

    expect($page)->toContain("plural(counts.problem, 'problem', 'problems')")
        ->toContain("plural(counts.warning, 'warning', 'warnings')")
        ->toContain('Pagination')
        ->toContain("problem: 'Problems'")
        ->toContain("warning: 'Warnings'");
});
