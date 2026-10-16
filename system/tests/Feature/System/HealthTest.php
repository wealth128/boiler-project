<?php

use App\Enums\BackupStatus;
use App\Models\BackupRun;
use App\Services\BackupMonitor;
use App\Services\HealthChecker;
use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * System health (GET /system/health, Admin only) and php artisan
 * census:health. Disk space, php.ini values and the PHP version are replaced
 * by a stand-in checker, so each state can be tried on any PC.
 */

const GB = 1024 ** 3;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'Asia/Manila'));
    $this->base = storage_path('framework/testing/health-'.uniqid());
    $this->folder = $this->base.'/backups';
    File::ensureDirectoryExists($this->folder);

    config([
        'census.backup.path' => $this->folder,
        'census.version' => '1.2.3',
        'database.connections.mysql.password' => 'db-secret-pass',
    ]);

    healthyServer($this);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

/**
 * A server where every check passes. Tests then break one thing.
 *
 * @param  array<string, mixed>  $fake  free_server, free_backup, upload, post, php, database_down, storage_writable
 */
function healthyServer(object $test, array $fake = []): void
{
    app()['env'] = 'production';
    config(['app.debug' => false]);
    app(SchedulerHeartbeat::class)->beat();
    backupWith($test, 2, zip: true);

    $fake += ['free_server' => 100 * GB, 'free_backup' => 100 * GB, 'upload' => '2M', 'post' => '8M', 'php' => '8.3.5', 'database_down' => false, 'storage_writable' => true];
    $folder = $test->folder;

    app()->bind(HealthChecker::class, fn ($app) => new class($app->make(BackupMonitor::class), $app->make(SchedulerHeartbeat::class), $fake, $folder) extends HealthChecker
    {
        public function __construct(BackupMonitor $backups, SchedulerHeartbeat $heartbeat, private array $fake, private string $folder)
        {
            parent::__construct($backups, $heartbeat);
        }

        protected function freeSpace(string $path): ?int
        {
            return $this->fake[$path === $this->folder ? 'free_backup' : 'free_server'];
        }

        protected function ini(string $key): string
        {
            return $this->fake[$key === 'upload_max_filesize' ? 'upload' : 'post'];
        }

        protected function phpVersion(): string
        {
            return $this->fake['php'];
        }

        protected function pingDatabase(): void
        {
            if ($this->fake['database_down']) {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused (host=10.1.2.3, user=census_app, password=db-secret-pass)');
            }
        }

        protected function isWritable(string $path): bool
        {
            return $this->fake['storage_writable'];
        }
    });
}

/** Replace the backup history with one success $hoursAgo hours ago (and its zip file, if asked). */
function backupWith(object $test, float $hoursAgo, bool $zip, bool $zipOnDisk = true): void
{
    BackupRun::query()->delete();
    array_map('unlink', glob($test->folder.'/*.zip') ?: []);
    $at = now()->subMinutes((int) ($hoursAgo * 60));
    $stamp = $at->format('Y-m-d_His');

    BackupRun::create([
        'started_at' => $at,
        'finished_at' => $at->copy()->addMinute(),
        'status' => BackupStatus::Success,
        'file_name' => "patient_census_{$stamp}.sql",
        'size_bytes' => 1000,
        'zip_name' => $zip ? "patient_census_files_{$stamp}.zip" : null,
        'zip_size_bytes' => $zip ? 500 : null,
    ]);

    if ($zip && $zipOnDisk) {
        file_put_contents($test->folder."/patient_census_files_{$stamp}.zip", 'zip');
    }
}

/** @return array{key: string, label: string, status: string, detail: string, todo: string} */
function check(string $key): array
{
    return collect(app(HealthChecker::class)->checks())->firstWhere('key', $key);
}

// --- Who may open the page --------------------------------------------------

it('shows the page to Admin only', function (string $role, bool $allowed) {
    $response = $this->actingAs(userWithRole($role))->get('/system/health');

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'admin' => ['admin', true],
    'encoder' => ['encoder', false],
    'viewer' => ['viewer', false],
    'system admin' => ['system_admin', false],
]);

it('sends a signed-out visitor to the login page', function () {
    $this->get('/system/health')->assertRedirect('/login');
});

it('lists every check with a result and one "what to do" line', function () {
    $this->actingAs(userWithRole('admin'))->get('/system/health')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/health')
            ->where('overall', 'ok')
            ->has('checks', 11)
            ->where('checks.0.key', 'database')
            ->where('checks.0.status', 'ok')
            ->where('checks.10.key', 'version')
            ->where('checks.10.detail', '1.2.3.')
            ->has('checks.0.todo'));
});

it('does not show passwords, .env contents or connection details', function () {
    healthyServer($this, ['database_down' => true]);

    $json = $this->actingAs(userWithRole('admin'))->get('/system/health')->assertOk()->content();

    expect($json)->not->toContain('db-secret-pass')
        ->not->toContain('census_app')
        ->not->toContain('10.1.2.3')
        ->not->toContain('SQLSTATE')
        ->not->toContain((string) config('app.key'))
        ->not->toContain('DB_PASSWORD');
});

it('is in the sidebar under Administration for Admin only', function () {
    $nav = file_get_contents(resource_path('js/layouts/app/nav-items.ts'));

    expect($nav)->toContain("title: 'System health'")
        ->toContain("permission: 'view-health'")
        ->toContain("group: 'admin'");
});

// --- Each check -----------------------------------------------------------------

it('database: OK when connected, Problem when it cannot be reached', function () {
    expect(check('database')['status'])->toBe('ok');

    healthyServer($this, ['database_down' => true]);

    expect(check('database')['status'])->toBe('problem')
        ->and(check('database')['todo'])->toContain('MySQL');
});

it('last backup: OK within 26 hours, Problem after that or when there is none', function () {
    expect(check('last_backup')['status'])->toBe('ok');

    backupWith($this, 25.5, zip: true);
    expect(check('last_backup')['status'])->toBe('ok');

    backupWith($this, 26.5, zip: true);
    expect(check('last_backup')['status'])->toBe('problem')
        ->and(check('last_backup')['todo'])->toContain('Run backup now');

    BackupRun::query()->delete();
    expect(check('last_backup')['status'])->toBe('problem');
});

it('picture zip: OK when present, Warning for an old backup without one, Problem when the file is gone', function () {
    expect(check('picture_backup')['status'])->toBe('ok');

    backupWith($this, 2, zip: false);
    expect(check('picture_backup')['status'])->toBe('warning');

    backupWith($this, 2, zip: true, zipOnDisk: false);
    expect(check('picture_backup')['status'])->toBe('problem');

    BackupRun::query()->delete();
    expect(check('picture_backup')['status'])->toBe('warning');
});

it('backup folder: Problem when it cannot be reached, and its free space is judged like the disk', function () {
    expect(check('backup_folder')['status'])->toBe('ok');

    File::deleteDirectory($this->folder);
    expect(check('backup_folder')['status'])->toBe('problem')
        ->and(check('backup_folder')['todo'])->toContain('backup PC');

    File::ensureDirectoryExists($this->folder);
    healthyServer($this, ['free_backup' => 5 * GB]);
    File::ensureDirectoryExists($this->folder);
    expect(check('backup_folder')['status'])->toBe('warning');

    healthyServer($this, ['free_backup' => 1 * GB]);
    File::ensureDirectoryExists($this->folder);
    expect(check('backup_folder')['status'])->toBe('problem');
});

it('server disk: OK from 10 GB, Warning under 10 GB, Problem under 2 GB', function (int|float $freeGb, string $status) {
    healthyServer($this, ['free_server' => (int) ($freeGb * GB)]);

    expect(check('server_disk')['status'])->toBe($status);
})->with([
    'plenty' => [200, 'ok'],
    'exactly 10 GB' => [10, 'ok'],
    'just under 10 GB' => [9.9, 'warning'],
    'exactly 2 GB' => [2, 'warning'],
    'just under 2 GB' => [1.9, 'problem'],
    'almost nothing' => [0.1, 'problem'],
]);

it('scheduler: OK within 5 minutes, Problem after that or when it never ran', function () {
    expect(check('scheduler')['status'])->toBe('ok');

    Cache::forever(SchedulerHeartbeat::KEY, now()->subMinutes(6)->getTimestamp());
    expect(check('scheduler')['status'])->toBe('problem')
        ->and(check('scheduler')['todo'])->toContain('Task Scheduler');

    Cache::forget(SchedulerHeartbeat::KEY);
    expect(check('scheduler')['status'])->toBe('problem')
        ->and(check('scheduler')['detail'])->toContain('never run');
});

it('safe settings: OK only in production with debug off', function () {
    expect(check('environment')['status'])->toBe('ok');

    config(['app.debug' => true]);
    expect(check('environment')['status'])->toBe('problem')
        ->and(check('environment')['detail'])->toContain('APP_DEBUG is on');

    config(['app.debug' => false]);
    app()['env'] = 'local';
    expect(check('environment')['status'])->toBe('problem')
        ->and(check('environment')['detail'])->toContain('APP_ENV is not "production"');
});

it('storage folders: Problem when they cannot be written', function () {
    expect(check('storage')['status'])->toBe('ok');

    healthyServer($this, ['storage_writable' => false]);

    expect(check('storage')['status'])->toBe('problem');
});

it('upload limits: OK at 2M or more (or unlimited), Problem under 2M', function (string $upload, string $post, string $status) {
    healthyServer($this, ['upload' => $upload, 'post' => $post]);

    expect(check('upload_limits')['status'])->toBe($status);
})->with([
    'both 2M' => ['2M', '2M', 'ok'],
    'in kilobytes' => ['2048K', '8M', 'ok'],
    'in bytes' => ['2097152', '8M', 'ok'],
    'gigabytes' => ['1G', '1G', 'ok'],
    'no limit' => ['0', '-1', 'problem'], // upload 0 bytes
    'upload too small' => ['1M', '8M', 'problem'],
    'post too small' => ['2M', '1M', 'problem'],
    'unlimited post' => ['2M', '-1', 'ok'],
]);

it('PHP version: OK from 8.3, Problem before', function (string $version, string $status) {
    healthyServer($this, ['php' => $version]);

    expect(check('php')['status'])->toBe($status);
})->with([
    '8.5' => ['8.5.5', 'ok'],
    '8.3.0' => ['8.3.0', 'ok'],
    '8.2.30' => ['8.2.30', 'problem'],
]);

it('system version: shown from the config', function () {
    expect(check('version'))->toMatchArray(['status' => 'ok', 'detail' => '1.2.3.']);
});

it('reads php.ini sizes', function () {
    expect(HealthChecker::iniBytes('2M'))->toBe(2097152)
        ->and(HealthChecker::iniBytes('512K'))->toBe(524288)
        ->and(HealthChecker::iniBytes('1G'))->toBe(1073741824)
        ->and(HealthChecker::iniBytes('-1'))->toBe(PHP_INT_MAX)
        ->and(HealthChecker::iniBytes('1048576'))->toBe(1048576);
});

it('summarises as the worst result', function () {
    expect(HealthChecker::overall([['status' => 'ok'], ['status' => 'ok']]))->toBe('ok')
        ->and(HealthChecker::overall([['status' => 'ok'], ['status' => 'warning']]))->toBe('warning')
        ->and(HealthChecker::overall([['status' => 'warning'], ['status' => 'problem']]))->toBe('problem');
});

// --- php artisan census:health ------------------------------------------------------

it('census:health exits 0 when nothing is a Problem', function () {
    $this->artisan('census:health')
        ->expectsOutputToContain('OK       Database: Connected.')
        ->assertExitCode(0);
});

it('census:health exits 0 with only a warning, and shows what to do', function () {
    healthyServer($this, ['free_server' => 5 * GB]);

    $this->artisan('census:health')
        ->expectsOutputToContain('WARNING  Server disk space')
        ->expectsOutputToContain('What to do: Tell IT: free some space on the server disk.')
        ->assertExitCode(0);
});

it('census:health exits 1 when any check is a Problem', function () {
    healthyServer($this, ['database_down' => true]);

    $this->artisan('census:health')
        ->expectsOutputToContain('PROBLEM  Database')
        ->expectsOutputToContain('There is at least one problem')
        ->assertExitCode(1);
});

it('census:health exits 1 when the scheduler stopped', function () {
    Cache::forget(SchedulerHeartbeat::KEY);

    $this->artisan('census:health')->expectsOutputToContain('PROBLEM  Automatic tasks')->assertExitCode(1);
});

it('census:health prints no password or connection detail', function () {
    healthyServer($this, ['database_down' => true]);

    $this->artisan('census:health')
        ->doesntExpectOutputToContain('db-secret-pass')
        ->doesntExpectOutputToContain('10.1.2.3')
        ->doesntExpectOutputToContain('census_app')
        ->assertExitCode(1);
});
