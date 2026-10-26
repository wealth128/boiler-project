<?php

use App\Enums\BackupStatus;
use App\Models\AuditLog;
use App\Models\AuditPruneRun;
use App\Services\AuditArchive;
use App\Services\AuditPruner;
use App\Services\BackupService;
use App\Services\HealthChecker;
use App\Services\UsbCopyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Step 7e: audit log retention of 1 year. census:prune-audit archives entries older
 * than 365 days to audit-archive_*.csv.gz in the backup folder, then deletes exactly
 * those rows. Nobody can delete entries by hand.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-26 04:30', 'Asia/Manila'));
    $this->folder = storage_path('framework/testing/audit-archive-'.uniqid());
    config(['census.backup.path' => $this->folder]);
    $this->admin = userWithRole('admin');

    DB::table('audit_logs')->delete();
    DB::table('audit_prune_runs')->delete();

    $this->entry = function (string $createdAt, string $action = 'visit.created', ?array $changes = null): int {
        return DB::table('audit_logs')->insertGetId([
            'user_id' => $this->admin->id,
            'action' => $action,
            'subject_type' => 'visits',
            'subject_id' => 1,
            'changes' => $changes === null ? null : json_encode($changes),
            'ip_address' => '10.0.0.5',
            'created_at' => $createdAt,
        ]);
    };

    $this->readArchive = function (string $name): array {
        $text = (string) gzdecode((string) file_get_contents($this->folder.DIRECTORY_SEPARATOR.$name));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    };
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

it('archives entries older than 365 days, deletes them and keeps newer ones', function () {
    $old = ($this->entry)('2025-10-20 09:00:00', 'visit.created', ['label' => 'V-1']);
    $oldest = ($this->entry)('2025-01-02 10:00:00');
    $keptEdge = ($this->entry)('2025-10-27 09:00:00'); // 364 days old
    $recent = ($this->entry)('2026-10-01 09:00:00');

    $run = app(AuditPruner::class)->runIfIdle();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->rows_count)->toBe(2)
        ->and($run->archive_name)->toBe('audit-archive_2026-10-26.csv.gz')
        ->and(DB::table('audit_logs')->whereIn('id', [$old, $oldest])->count())->toBe(0)
        ->and(DB::table('audit_logs')->whereIn('id', [$keptEdge, $recent])->count())->toBe(2);

    $rows = ($this->readArchive)($run->archive_name);

    expect($rows[0])->toBe(AuditArchive::COLUMNS)
        ->and($rows)->toHaveCount(3)
        ->and(array_column($rows, 0))->toContain((string) $old, (string) $oldest);
    // The details come back as JSON.
    $withDetails = collect($rows)->first(fn ($row) => $row[0] === (string) $old);
    expect(json_decode($withDetails[7], true))->toBe(['label' => 'V-1'])
        ->and($withDetails[6])->toBe('10.0.0.5');
});

it('writes the archive before it deletes anything', function () {
    ($this->entry)('2025-01-02 10:00:00');
    $existedAtDelete = [];

    DB::listen(function ($query) use (&$existedAtDelete) {
        if (str_starts_with(strtolower($query->sql), 'delete from `audit_logs`')) {
            $existedAtDelete[] = is_file($this->folder.DIRECTORY_SEPARATOR.'audit-archive_2026-10-26.csv.gz');
        }
    });

    app(AuditPruner::class)->runIfIdle();

    expect($existedAtDelete)->not->toBeEmpty()->and($existedAtDelete)->each->toBeTrue();
});

it('deletes in chunks of 1000 and archives every row', function () {
    $rows = [];

    for ($i = 0; $i < 2500; $i++) {
        $rows[] = ['user_id' => null, 'action' => 'user.login', 'subject_type' => 'users', 'subject_id' => 1, 'changes' => null, 'ip_address' => null, 'created_at' => '2025-02-01 08:00:00'];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('audit_logs')->insert($chunk);
    }

    $deletes = 0;
    DB::listen(function ($query) use (&$deletes) {
        if (str_starts_with(strtolower($query->sql), 'delete from `audit_logs`')) {
            $deletes++;
        }
    });

    $run = app(AuditPruner::class)->runIfIdle();

    expect($run->rows_count)->toBe(2500)
        ->and($deletes)->toBe(3)
        ->and(AuditArchive::countRows($this->folder.DIRECTORY_SEPARATOR.$run->archive_name))->toBe(2500)
        ->and(DB::table('audit_logs')->where('action', 'user.login')->count())->toBe(0);
});

it('writes one audit.pruned entry with the count, the dates and the file name', function () {
    ($this->entry)('2025-01-02 10:00:00');
    ($this->entry)('2025-10-20 09:00:00');

    app(AuditPruner::class)->runIfIdle();

    $entry = AuditLog::query()->where('action', 'audit.pruned')->sole();

    expect($entry->changes)->toMatchArray([
        'count' => 2,
        'oldest' => '2025-01-02',
        'newest' => '2025-10-20',
        'archive_file' => 'audit-archive_2026-10-26.csv.gz',
    ])->and($entry->user_id)->toBeNull();

    // The entry is itself removed by the same rule a year later.
    $this->travelTo(Carbon::parse('2027-10-27 04:30', 'Asia/Manila'));
    $run = app(AuditPruner::class)->runIfIdle();

    expect($run->rows_count)->toBe(1)
        ->and(AuditLog::query()->where('action', 'audit.pruned')->count())->toBe(1) // the new one
        ->and(AuditLog::query()->where('action', 'audit.pruned')->first()->id)->not->toBe($entry->id);
});

it('records a quiet run without an audit entry or archive when nothing is old enough', function () {
    ($this->entry)('2026-10-01 09:00:00');

    $run = app(AuditPruner::class)->runIfIdle();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->rows_count)->toBe(0)
        ->and($run->archive_name)->toBeNull()
        ->and(AuditLog::query()->where('action', 'audit.pruned')->count())->toBe(0)
        ->and(is_dir($this->folder) ? glob($this->folder.'/audit-archive_*') : [])->toBe([]);
});

it('keeps text that starts with = + - @ from being read as a formula', function () {
    ($this->entry)('2025-01-02 10:00:00', '=cmd', ['label' => '=1+1']);

    $run = app(AuditPruner::class)->runIfIdle();
    $row = ($this->readArchive)($run->archive_name)[1];

    expect($row[3])->toBe("'=cmd")
        ->and(AuditArchive::safe('+1'))->toBe("'+1")
        ->and(AuditArchive::safe('-x'))->toBe("'-x")
        ->and(AuditArchive::safe('@a'))->toBe("'@a")
        ->and(AuditArchive::safe('visit.created'))->toBe('visit.created');
});

it('deletes nothing and shows the red bar when the archive cannot be written', function () {
    $id = ($this->entry)('2025-01-02 10:00:00');

    // A FILE where the backup folder should be: it cannot be created.
    File::ensureDirectoryExists(dirname($this->folder));
    file_put_contents($this->folder, 'not a folder');
    config(['census.backup.path' => $this->folder.DIRECTORY_SEPARATOR.'sub']);

    $run = app(AuditPruner::class)->runIfIdle();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and(DB::table('audit_logs')->where('id', $id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'audit.pruned')->count())->toBe(0)
        ->and(app(AuditPruner::class)->alert())->toStartWith('Audit log clean-up failed: ');

    $this->actingAs($this->admin)->get('/audit-log')->assertInertia(fn (Assert $page) => $page
        ->where('backupAlerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['level'] === 'error' && str_starts_with($a['message'], 'Audit log clean-up failed: '))));

    // The bar goes away once a later run succeeds.
    @unlink($this->folder);
    config(['census.backup.path' => $this->folder]);
    $later = app(AuditPruner::class)->runIfIdle();

    expect($later->status)->toBe(BackupStatus::Success)
        ->and(app(AuditPruner::class)->alert())->toBeNull()
        ->and(DB::table('audit_logs')->where('id', $id)->exists())->toBeFalse();
});

it('shows no bar to anyone but Admin', function () {
    AuditPruneRun::create(['started_at' => now(), 'finished_at' => now(), 'status' => BackupStatus::Failed, 'rows_count' => 0, 'message' => 'disk full']);

    $this->actingAs(userWithRole('encoder'))->get('/encode')->assertInertia(fn (Assert $page) => $page->where('backupAlerts', []));
});

it('does not run while a backup or test restore holds the lock', function () {
    $id = ($this->entry)('2025-01-02 10:00:00');
    $lock = Cache::lock(BackupService::LOCK, 60);
    expect($lock->get())->toBeTrue();

    expect(app(AuditPruner::class)->runIfIdle())->toBeNull()
        ->and(DB::table('audit_logs')->where('id', $id)->exists())->toBeTrue()
        ->and(AuditPruneRun::query()->count())->toBe(0);

    $this->artisan('census:prune-audit')->expectsOutputToContain('try again tomorrow')->assertSuccessful();

    $lock->release();

    $this->artisan('census:prune-audit')->assertSuccessful();
    expect(DB::table('audit_logs')->where('id', $id)->exists())->toBeFalse();
});

it('is scheduled daily at 04:30 without overlapping', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'census:prune-audit'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('census.audit.retention_days'))->toBe(365)
        ->and(array_key_exists('census:prune-audit', Artisan::all()))->toBeTrue();
});

it('has no route that deletes or changes audit entries, and the model refuses', function () {
    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'audit')) {
            expect($route->methods())->toBe(['GET', 'HEAD']);
        }
    }

    $entry = AuditLog::query()->create(['user_id' => null, 'action' => 'visit.created', 'subject_type' => 'visits', 'subject_id' => 1]);

    expect(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => $entry->update(['action' => 'x']))->toThrow(LogicException::class);
});

it('keeps audit archives when the 30-day backup cleanup runs', function () {
    File::ensureDirectoryExists($this->folder);
    $archive = $this->folder.'/audit-archive_2020-01-01.csv.gz';
    $dump = $this->folder.'/patient_census_2020-01-01_020000.sql';
    $zip = $this->folder.'/patient_census_files_2020-01-01_020000.zip';

    foreach ([$archive, $dump, $zip] as $file) {
        file_put_contents($file, 'x');
    }

    $method = new ReflectionMethod(BackupService::class, 'deleteOldBackups');
    $removed = $method->invoke(app(BackupService::class), $this->folder);

    expect($removed)->toBe(2)
        ->and(is_file($archive))->toBeTrue()
        ->and(is_file($dump))->toBeFalse()
        ->and(is_file($zip))->toBeFalse();
});

it('picks the newest archive file for Copy to USB', function () {
    File::ensureDirectoryExists($this->folder);
    file_put_contents($this->folder.'/audit-archive_2026-01-05.csv.gz', 'a');
    file_put_contents($this->folder.'/audit-archive_2026-10-26.csv.gz', 'b');
    file_put_contents($this->folder.'/audit-archive_2026-10-26_2.csv.gz', 'c');
    touch($this->folder.'/audit-archive_2026-01-05.csv.gz', time() - 5000);
    touch($this->folder.'/audit-archive_2026-10-26.csv.gz', time() - 100);
    touch($this->folder.'/audit-archive_2026-10-26_2.csv.gz', time());
    file_put_contents($this->folder.'/notes.txt', 'ignored');

    expect(AuditArchive::newest($this->folder))->toBe('audit-archive_2026-10-26_2.csv.gz')
        ->and(AuditArchive::newest($this->folder.'/missing'))->toBeNull()
        ->and(AuditArchive::nextName($this->folder, now()->toImmutable()))->toBe('audit-archive_2026-10-26_3.csv.gz')
        ->and(UsbCopyService::class)->toBeString();
});

it('shows the retention note and does not let From go back past the retention date', function () {
    $this->actingAs($this->admin)->get('/audit-log?from=2020-01-01&to=2026-10-26')
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention_from', '2025-10-26')
            ->where('filters.from', '2025-10-26'));

    $this->actingAs($this->admin)->get('/audit-log?from=2026-01-01')
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-01-01'));
});

it('adds an Audit log clean-up check to System health', function () {
    $check = fn () => collect(app(HealthChecker::class)->checks())->firstWhere('key', 'audit_cleanup');

    expect($check()['status'])->toBe(HealthChecker::WARNING); // never ran

    $run = AuditPruneRun::create(['started_at' => now()->subHours(20), 'finished_at' => now()->subHours(20), 'status' => BackupStatus::Success, 'rows_count' => 0]);
    expect($check()['status'])->toBe(HealthChecker::OK);

    $run->update(['started_at' => now()->subDays(3)]);
    expect($check()['status'])->toBe(HealthChecker::WARNING);

    AuditPruneRun::create(['started_at' => now(), 'finished_at' => now(), 'status' => BackupStatus::Failed, 'rows_count' => 0, 'message' => 'disk full']);
    expect($check()['status'])->toBe(HealthChecker::PROBLEM)
        ->and($check()['detail'])->toContain('disk full');
});
