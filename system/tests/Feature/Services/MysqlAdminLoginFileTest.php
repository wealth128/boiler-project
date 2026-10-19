<?php

use App\Services\Setup\MysqlAdmin;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Loading a backup needs a temporary MySQL login file with the root
 * password. It must live in the system temp folder (never under
 * storage/app/private, which every backup zips) and be deleted at once,
 * also when loading fails. The mysql program is faked.
 */

beforeEach(function () {
    $this->dump = storage_path('framework/testing/login-'.uniqid().'.sql');
    File::ensureDirectoryExists(dirname($this->dump));
    file_put_contents($this->dump, "CREATE TABLE t (id int);\n");

    config([
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'database.connections.mysql_root' => ['host' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'password' => 'root-secret-Pw1'],
    ]);

    $this->seen = [];
});

afterEach(function () {
    @unlink($this->dump);
});

/** Fake mysql: remember the login file's path and what it held while running. */
function fakeMysqlClient(object $test, int $exitCode = 0, string $error = ''): void
{
    Process::fake(function (PendingProcess $process) use ($test, $exitCode, $error) {
        $option = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--defaults-extra-file='));
        $path = substr((string) $option, strlen('--defaults-extra-file='));

        $test->seen = ['path' => $path, 'contents' => is_file($path) ? file_get_contents($path) : null, 'command' => (array) $process->command];

        return Process::result(errorOutput: $error, exitCode: $exitCode);
    });
}

it('writes the login file in the system temp folder, not in the private storage folder', function () {
    fakeMysqlClient($this);

    app(MysqlAdmin::class)->importDump($this->dump, 'patient_census');

    $path = realpath(dirname($this->seen['path']));

    expect($path)->toBe(realpath(sys_get_temp_dir()))
        ->and(str_starts_with($path, (string) realpath(storage_path('app/private'))))->toBeFalse()
        ->and($this->seen['contents'])->toContain('password="root-secret-Pw1"')->toContain('user="root"')
        // The password is never on the command line.
        ->and(implode(' ', $this->seen['command']))->not->toContain('root-secret-Pw1')
        ->and($this->seen['command'][0])->toBe('C:/fake/mysql.exe');
});

it('deletes the login file after a successful load', function () {
    fakeMysqlClient($this);

    app(MysqlAdmin::class)->importDump($this->dump, 'patient_census');

    expect(file_exists($this->seen['path']))->toBeFalse();
});

it('deletes the login file when the load fails', function () {
    fakeMysqlClient($this, exitCode: 1, error: 'ERROR 1064: syntax error');

    expect(fn () => app(MysqlAdmin::class)->importDump($this->dump, 'patient_census'))
        ->toThrow(RuntimeException::class, 'ERROR 1064');

    expect(file_exists($this->seen['path']))->toBeFalse();
});

it('deletes the login file when the mysql program cannot even start', function () {
    $path = null;
    Process::fake(function (PendingProcess $process) use (&$path) {
        $option = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--defaults-extra-file='));
        $path = substr((string) $option, strlen('--defaults-extra-file='));

        throw new RuntimeException('The system cannot find the file specified.');
    });

    expect(fn () => app(MysqlAdmin::class)->importDump($this->dump, 'patient_census'))->toThrow(RuntimeException::class);

    expect($path)->not->toBeNull()->and(file_exists($path))->toBeFalse();
});

it('deletes the login file when the backup file cannot be opened', function () {
    fakeMysqlClient($this);

    expect(fn () => @app(MysqlAdmin::class)->importDump($this->dump.'.missing', 'patient_census'))->toThrow(RuntimeException::class);

    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'census-login-*'))->toBe([]);
});

it('refuses to write the login file inside the private storage folder', function () {
    $private = storage_path('app/private');
    File::ensureDirectoryExists($private);
    $before = glob($private.'/census-login-*') ?: [];
    fakeMysqlClient($this);

    $admin = new class extends MysqlAdmin
    {
        protected function tempFolder(): string
        {
            return storage_path('app/private');
        }
    };

    expect(fn () => $admin->importDump($this->dump, 'patient_census'))
        ->toThrow(RuntimeException::class, 'inside the private storage folder');

    expect(glob($private.'/census-login-*') ?: [])->toBe($before)
        ->and($this->seen)->toBe([]); // mysql never started
});

// --- Leftover login files (a crashed run) ---------------------------------------------------

it('deletes login files older than 1 hour at the start of a run, and only ours', function () {
    fakeMysqlClient($this);
    $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR;

    $old = $temp.'census-login-'.uniqid().'.cnf';
    $recent = $temp.'census-login-'.uniqid().'.cnf';
    $lookalike = $temp.'census-notes-'.uniqid().'.cnf';
    $other = $temp.'census-login-'.uniqid().'.txt';
    $threeLetters = $temp.'cen'.substr(uniqid(), -4).'.tmp'; // what tempnam() would make on Windows

    foreach ([$old, $recent, $lookalike, $other, $threeLetters] as $file) {
        file_put_contents($file, 'password="leaked"');
    }

    foreach ([$old, $lookalike, $other, $threeLetters] as $file) {
        touch($file, time() - 7200);
    }

    try {
        app(MysqlAdmin::class)->importDump($this->dump, 'patient_census');

        expect(file_exists($old))->toBeFalse()
            ->and(file_exists($recent))->toBeTrue()      // might still be in use
            ->and(file_exists($lookalike))->toBeTrue()   // not our name pattern
            ->and(file_exists($other))->toBeTrue()
            ->and(file_exists($threeLetters))->toBeTrue();
    } finally {
        array_map('unlink', array_filter([$old, $recent, $lookalike, $other, $threeLetters], 'file_exists'));
    }
});

it('names the login file census-login-<random>.cnf', function () {
    fakeMysqlClient($this);

    app(MysqlAdmin::class)->importDump($this->dump, 'patient_census');

    expect(basename($this->seen['path']))->toMatch('/^census-login-[0-9a-f]{16}\.cnf$/');
});
