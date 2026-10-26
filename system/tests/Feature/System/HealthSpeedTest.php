<?php

use App\Services\BackupMonitor;
use App\Services\HealthChecker;
use App\Services\SchedulerHeartbeat;

/*
 * Step 7g: the System health check "Speed settings" (docs/operations-guide.md,
 * "Speed settings"). OPcache, the config / route / event caches and the log
 * level are Warnings (only slower); a Vite development file in public is a
 * Problem (the pages would look for a dev server). The hooks are faked here, so
 * this never touches the real caches or the developer's public/hot.
 */

/**
 * The "Speed settings" row with the given server state.
 *
 * @param  list<string>  $missingCaches
 * @return array{key: string, label: string, status: string, detail: string, todo: string}
 */
function speedCheck(bool $opcache = true, array $missingCaches = [], bool $hot = false): array
{
    $checker = new class(app(BackupMonitor::class), app(SchedulerHeartbeat::class), $opcache, $missingCaches, $hot) extends HealthChecker
    {
        public function __construct(BackupMonitor $backups, SchedulerHeartbeat $heartbeat, private bool $opcache, private array $missing, private bool $hot)
        {
            parent::__construct($backups, $heartbeat);
        }

        protected function opcacheOn(): bool
        {
            return $this->opcache;
        }

        protected function missingCaches(): array
        {
            return $this->missing;
        }

        protected function viteHotFile(): bool
        {
            return $this->hot;
        }
    };

    return collect($checker->checks())->firstWhere('key', 'speed');
}

beforeEach(function () {
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['daily'], 'logging.channels.daily.level' => 'warning']);
});

it('is OK with OPcache on, the caches built and the log level above debug', function () {
    expect(speedCheck())->toMatchArray([
        'label' => 'Speed settings',
        'status' => 'ok',
        'detail' => 'OPcache on, caches built, log level warning.',
    ]);
});

it('warns when OPcache is off', function () {
    expect(speedCheck(opcache: false))
        ->toMatchArray(['status' => 'warning', 'detail' => 'OPcache is off.'])
        ->and(speedCheck(opcache: false)['todo'])->toContain('Speed settings');
});

it('warns when a cache is not built', function () {
    expect(speedCheck(missingCaches: ['config', 'event']))
        ->toMatchArray(['status' => 'warning', 'detail' => 'The config, event cache is not built.']);
});

it('warns when the log level is debug, also inside a stack', function () {
    config(['logging.channels.daily.level' => 'debug']);

    expect(speedCheck())->toMatchArray(['status' => 'warning', 'detail' => 'The log level is debug.']);
});

it('is a Problem when the Vite development file is in public, whatever else is fine', function () {
    expect(speedCheck(opcache: false, hot: true))
        ->toMatchArray(['status' => 'problem', 'detail' => 'The Vite development file public/hot is present.'])
        ->and(speedCheck(hot: true)['todo'])->toContain('public\\hot');
});

it('reads the real Vite file from the public folder', function () {
    $public = storage_path('framework/testing/public-'.uniqid());
    mkdir($public, 0777, true);
    $original = public_path();
    app()->usePublicPath($public);

    try {
        $checker = app(HealthChecker::class);
        $hot = (new ReflectionMethod($checker, 'viteHotFile'));

        expect($hot->invoke($checker))->toBeFalse();

        file_put_contents($public.'/hot', 'http://[::1]:5173');
        expect($hot->invoke($checker))->toBeTrue();
    } finally {
        app()->usePublicPath($original);
        @unlink($public.'/hot');
        @rmdir($public);
    }
});
