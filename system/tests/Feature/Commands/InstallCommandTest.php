<?php

use App\Console\Commands\InstallCommand;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\EnvFile;
use App\Services\Setup\InstallRequirements;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeAppMaintenance;
use Tests\Support\FakeMysqlAdmin;
use Tests\Support\FakeRequirements;
use Tests\Support\SetupLog;

require_once __DIR__.'/../../Support/SetupFakes.php';

/*
 * php artisan census:install and --repair. The MySQL root account, the
 * caches and the PC's PHP settings are faked: nothing here logs in as root,
 * writes the real .env or rebuilds the real caches. The seeders and the
 * accounts run on patient_census_test.
 */

beforeEach(function () {
    SetupLog::$calls = [];
    $this->dir = storage_path('framework/testing/install-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    $this->envPath = $this->dir.'/.env';
    $this->logPath = $this->dir.'/install.log';
    $this->backupDir = $this->dir.'/backups';

    config([
        'census.paths.env' => $this->envPath,
        'census.paths.install_log' => $this->logPath,
    ]);

    $this->mysql = new FakeMysqlAdmin;
    app()->instance(MysqlAdmin::class, $this->mysql);
    app()->instance(AppMaintenance::class, new FakeAppMaintenance);
    app()->instance(InstallRequirements::class, new FakeRequirements);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** Answers every question of a normal install. */
function install(object $test, array $options = [], string $rootPassword = 'root-secret-Pw1')
{
    return $test->artisan('census:install', $options)
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, $rootPassword)
        ->expectsQuestion('Backup folder', $test->backupDir)
        ->expectsQuestion('Username for the Admin account', 'head.admin')
        ->expectsQuestion('Username for the System Admin account', 'it.admin');
}

/** @return list<string> Every file under the test folder and today's Laravel log. */
function filesToScan(object $test): array
{
    $files = collect(File::allFiles($test->dir, true))->map(fn ($f) => $f->getPathname())->all();

    return array_merge($files, glob(storage_path('logs/laravel*.log')) ?: []);
}

// --- A fresh install ---------------------------------------------------

it('installs: database, .env, tables, lists and the two first accounts', function () {
    install($this)
        ->expectsOutputToContain('Write these down and put them in the sealed envelope')
        ->assertSuccessful();

    // Database and account for the app, with only the rights in security.md.
    expect($this->mysql->provisioned['database'])->toBe('patient_census')
        ->and($this->mysql->provisioned['user'])->toBe('census_app')
        ->and(strlen($this->mysql->provisioned['password']))->toBeGreaterThanOrEqual(32)
        ->and(MysqlAdmin::APP_GRANTS)->toBe(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'LOCK TABLES', 'SHOW VIEW', 'TRIGGER']);

    // Steps in order: log in, create, migrate, seed with root, caches.
    expect(SetupLog::names())->toBe(['connect', 'provision', 'migrate', 'with-root', 'clear-caches', 'rebuild-caches']);

    // Only the two accounts, with temporary passwords. No dev users.
    $users = User::query()->orderBy('id')->get();
    expect($users)->toHaveCount(2)
        ->and($users[0]->username)->toBe('head.admin')
        ->and($users[0]->role)->toBe(Role::Admin)
        ->and($users[1]->username)->toBe('it.admin')
        ->and($users[1]->role)->toBe(Role::SystemAdmin)
        ->and($users->every(fn (User $u) => $u->must_change_password && $u->is_active))->toBeTrue()
        ->and(User::whereIn('username', ['encoder', 'viewer', 'admin', 'sysadmin'])->exists())->toBeFalse();

    // Lists and settings are seeded.
    expect(Branch::count())->toBeGreaterThan(0)
        ->and(Setting::where('key', 'hospital_name')->exists())->toBeTrue();

    // Each account has an audit entry without a password.
    $entries = AuditLog::where('action', 'user.created')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->every(fn ($e) => $e->user_id === null && ! str_contains(json_encode($e->changes), 'password":"')))->toBeTrue();
});

it('writes a production .env with the new database login and the backup settings', function () {
    install($this)->assertSuccessful();

    $env = new EnvFile($this->envPath);
    $app = $this->mysql->provisioned;

    expect($env->get('APP_ENV'))->toBe('production')
        ->and($env->get('APP_DEBUG'))->toBe('false')
        ->and($env->get('APP_KEY'))->toStartWith('base64:')
        ->and(strlen(base64_decode(substr($env->get('APP_KEY'), 7))))->toBe(32)
        ->and($env->get('LOG_STACK'))->toBe('daily')
        ->and($env->get('DB_DATABASE'))->toBe('patient_census')
        ->and($env->get('DB_USERNAME'))->toBe('census_app')
        ->and($env->get('DB_PASSWORD'))->toBe($app['password'])
        ->and($env->get('MYSQLDUMP_PATH'))->toBe('C:/fake/mysqldump.exe')
        ->and($env->get('MYSQL_PATH'))->toBe('C:/fake/mysql.exe')
        ->and($env->get('BACKUP_PATH'))->toBe(str_replace('\\', '/', $this->backupDir))
        ->and($env->get('APP_NAME'))->toBe('Patient Encoding & Reports') // the rest comes from .env.example
        ->and(is_dir($this->backupDir))->toBeTrue()
        ->and(file_exists($this->backupDir.'/.census-write-test'))->toBeFalse();
});

it('rebuilds the caches in a new process that does not inherit the old .env values', function () {
    install($this)->assertSuccessful();

    $keys = SetupLog::detail('rebuild-caches')['keys'];

    expect($keys)->toContain('APP_KEY', 'DB_PASSWORD', 'APP_ENV');
});

it('never keeps the MySQL root password or the app password in a log or file, or in the audit log', function () {
    install($this)->assertSuccessful();

    $secrets = ['root-secret-Pw1'];
    $appPassword = $this->mysql->provisioned['password'];

    foreach (filesToScan($this) as $path) {
        $text = (string) file_get_contents($path);

        foreach ($secrets as $secret) {
            expect($text)->not->toContain($secret, "root password found in {$path}");
        }

        if (basename($path) !== '.env') {
            expect($text)->not->toContain($appPassword, "app password found in {$path}");
        }
    }

    $audit = AuditLog::all()->map(fn ($e) => json_encode($e->changes))->implode(' ');
    expect($audit)->not->toContain('root-secret-Pw1')->not->toContain($appPassword);

    $log = file_get_contents($this->logPath);
    expect($log)->toContain('Install started.')->toContain('Install finished.')
        ->and($log)->toContain('SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER');
});

it('hides the root password even from an error message', function () {
    $this->mysql = new class extends FakeMysqlAdmin
    {
        public function migrate(): int
        {
            throw new RuntimeException('The migrations failed near root-secret-Pw1');
        }
    };
    app()->instance(MysqlAdmin::class, $this->mysql);

    install($this)->expectsOutputToContain('The migrations failed near ***')->assertFailed();

    expect(file_get_contents($this->logPath))->not->toContain('root-secret-Pw1')->toContain('***');
});

// --- Stops before changing anything -------------------------------------

it('stops on any FIX line and changes nothing', function () {
    app()->instance(InstallRequirements::class, new FakeRequirements(missingExtension: 'gd'));

    $this->artisan('census:install')
        ->expectsOutputToContain('FIX  PHP extension gd')
        ->expectsOutputToContain('Turn on the gd extension')
        ->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(file_exists($this->envPath))->toBeFalse()
        ->and(User::count())->toBe(0);
});

it('stops when MySQL cannot be reached', function () {
    $this->mysql->isReachable = false;

    $this->artisan('census:install')
        ->expectsOutputToContain('FIX  MySQL can be reached')
        ->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

it('stops when the root password is wrong, before creating anything', function () {
    $this->artisan('census:install')
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'wrong-password')
        ->expectsOutputToContain('Could not log in to MySQL as root')
        ->assertFailed();

    expect(SetupLog::names())->toBe(['connect'])
        ->and(file_exists($this->envPath))->toBeFalse()
        ->and(User::count())->toBe(0);
});

it('asks before replacing an existing .env, and keeps it on No', function () {
    file_put_contents($this->envPath, "APP_KEY=\nDB_PASSWORD=keep-me\n");

    $this->artisan('census:install')
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsQuestion('Backup folder', $this->backupDir)
        ->expectsQuestion('Username for the Admin account', 'head.admin')
        ->expectsQuestion('Username for the System Admin account', 'it.admin')
        ->expectsConfirmation('A .env file already exists. Replace it?', 'no')
        ->assertFailed();

    expect(file_get_contents($this->envPath))->toContain('keep-me')
        ->and(SetupLog::names())->toBe(['connect']);
});

// --- Already installed ---------------------------------------------------

it('refuses to run again on an installed system and changes nothing', function () {
    userWithRole('admin', ['username' => 'admin']);

    $this->artisan('census:install')
        ->expectsOutputToContain('This server already has the system installed. Use census:install --repair, or census:restore.')
        ->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(file_exists($this->envPath))->toBeFalse()
        ->and(User::count())->toBe(1);
});

// --- --repair -------------------------------------------------------------

it('--repair adds missing .env keys, rebuilds the caches and changes no data', function () {
    $admin = userWithRole('admin', ['username' => 'admin']);
    $password = $admin->password;
    file_put_contents($this->envPath, "APP_NAME=Census\nAPP_KEY=base64:keepthiskey\nDB_PASSWORD=keep-me\nAPP_ENV=production\n");
    $before = ['users' => User::count(), 'branches' => Branch::count(), 'settings' => Setting::count(), 'audit' => AuditLog::count()];

    $this->artisan('census:install', ['--repair' => true])
        ->expectsQuestion('Backup folder', $this->backupDir)
        ->expectsOutputToContain('Added the missing .env keys: APP_DEBUG, LOG_STACK, MYSQLDUMP_PATH, MYSQL_PATH, BACKUP_PATH.')
        ->assertSuccessful();

    $env = new EnvFile($this->envPath);
    expect($env->get('APP_DEBUG'))->toBe('false')
        ->and($env->get('LOG_STACK'))->toBe('daily')
        ->and($env->get('MYSQLDUMP_PATH'))->toBe('C:/fake/mysqldump.exe')
        ->and($env->get('MYSQL_PATH'))->toBe('C:/fake/mysql.exe')
        ->and($env->get('BACKUP_PATH'))->toBe(str_replace('\\', '/', $this->backupDir))
        // What was there stays exactly as it was.
        ->and($env->get('APP_KEY'))->toBe('base64:keepthiskey')
        ->and($env->get('DB_PASSWORD'))->toBe('keep-me')
        ->and($env->get('APP_NAME'))->toBe('Census');

    // No database work, no new accounts, no data changed.
    expect(SetupLog::names())->toBe(['clear-caches', 'rebuild-caches'])
        ->and(['users' => User::count(), 'branches' => Branch::count(), 'settings' => Setting::count(), 'audit' => AuditLog::count()])->toBe($before)
        ->and(User::first()->password)->toBe($password);
});

it('--repair leaves a .env that is complete alone', function () {
    userWithRole('admin', ['username' => 'admin']);
    $text = "APP_KEY=base64:k\nDB_PASSWORD=x\nAPP_ENV=production\nAPP_DEBUG=false\nLOG_STACK=daily\nMYSQLDUMP_PATH=C:/fake/mysqldump.exe\nMYSQL_PATH=C:/m2\nBACKUP_PATH=C:/b\n";
    file_put_contents($this->envPath, $text);

    $this->artisan('census:install', ['--repair' => true])
        ->expectsOutputToContain('No .env keys were missing.')
        ->assertSuccessful();

    expect(trim(file_get_contents($this->envPath)))->toBe(trim($text));
});

it('--repair warns about a debug or non-production .env but does not change it', function () {
    userWithRole('admin', ['username' => 'admin']);
    file_put_contents($this->envPath, "APP_KEY=base64:k\nDB_PASSWORD=x\nAPP_ENV=local\nAPP_DEBUG=true\nLOG_STACK=daily\nMYSQLDUMP_PATH=C:/m\nMYSQL_PATH=C:/m2\nBACKUP_PATH=C:/b\n");

    $this->artisan('census:install', ['--repair' => true])
        ->expectsOutputToContain('APP_DEBUG is "true", not "false"')
        ->assertSuccessful();

    expect((new EnvFile($this->envPath))->get('APP_DEBUG'))->toBe('true');
});

it('--repair never makes a new key and never creates accounts', function () {
    userWithRole('admin', ['username' => 'admin']);
    file_put_contents($this->envPath, "APP_KEY=\nDB_PASSWORD=x\nAPP_ENV=production\nAPP_DEBUG=false\nLOG_STACK=daily\nMYSQLDUMP_PATH=C:/m\nMYSQL_PATH=C:/m2\nBACKUP_PATH=C:/b\n");

    $this->artisan('census:install', ['--repair' => true])
        ->expectsOutputToContain('APP_KEY is empty')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('--repair on a system that is not installed says so', function () {
    $this->artisan('census:install', ['--repair' => true])
        ->expectsOutputToContain('nothing to repair')
        ->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

it('--repair still stops on a FIX line', function () {
    userWithRole('admin', ['username' => 'admin']);
    app()->instance(InstallRequirements::class, new FakeRequirements(missingExtension: 'zip'));

    $this->artisan('census:install', ['--repair' => true])->expectsOutputToContain('FIX  PHP extension zip')->assertFailed();
});

// --- The requirements themselves --------------------------------------

it('checks PHP, the extensions, the folders and the upload limits', function () {
    $results = collect((new InstallRequirements)->results())->pluck('label');

    expect($results->implode('|'))
        ->toContain('PHP 8.3 or newer')
        ->toContain('PHP extension gd')->toContain('PHP extension intl')->toContain('PHP extension zip')
        ->toContain('PHP extension mbstring')->toContain('PHP extension pdo_mysql')
        ->toContain('PHP extension fileinfo')->toContain('PHP extension openssl')
        ->toContain('storage')->toContain('bootstrap/cache')
        ->toContain('upload_max_filesize')->toContain('post_max_size');
});

it('flags a PHP that is too old and upload limits under 2M', function () {
    $old = new class extends InstallRequirements
    {
        protected function phpVersion(): string
        {
            return '8.2.30';
        }

        protected function ini(string $key): string
        {
            return '1M';
        }
    };

    $bad = collect($old->results())->where('ok', false)->pluck('label')->implode('|');

    expect($bad)->toContain('PHP 8.3 or newer')->toContain('upload_max_filesize')->toContain('post_max_size');
});

it('reads and writes .env lines without touching the rest', function () {
    file_put_contents($this->envPath, "\xEF\xBB\xBF# comment\nA=1\nB=\"two words\"\n");
    $env = new EnvFile($this->envPath);

    $env->set('A', '2');
    $env->set('C', 'has space & "quote" $x');
    $env->save();

    $again = new EnvFile($this->envPath);
    expect($again->get('A'))->toBe('2')
        ->and($again->get('B'))->toBe('two words')
        ->and($again->get('C'))->toBe('has space & "quote" $x')
        ->and($again->filled('MISSING'))->toBeFalse()
        ->and(str_replace("\r", '', file_get_contents($this->envPath)))->toStartWith("# comment\n");
});

// --- Never over a live system by mistake -------------------------------------

const REFUSED = 'This server already has the system installed. Use census:install --repair, or census:restore.';

it('refuses when .env already has an APP_KEY, even with no users and a database that cannot be reached', function () {
    file_put_contents($this->envPath, 'APP_KEY=base64:alivekey
DB_PASSWORD=live-pass
');
    $this->mysql->rootPassword = 'something-else'; // the login would fail

    $this->artisan('census:install')
        ->expectsOutputToContain(REFUSED)
        ->assertFailed();

    // Not even a login was tried, and .env is as it was.
    expect(SetupLog::names())->toBe([])
        ->and(User::count())->toBe(0)
        ->and(file_get_contents($this->envPath))->toContain('base64:alivekey')->toContain('live-pass');
});

it('refuses when the patient_census database already exists, before creating anything', function () {
    $this->mysql->databaseAlreadyThere = true;

    $this->artisan('census:install')
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsOutputToContain(REFUSED)
        ->assertFailed();

    // Only the login happened: no account, no .env, no migration.
    expect(SetupLog::names())->toBe(['connect'])
        ->and(file_exists($this->envPath))->toBeFalse()
        ->and(User::count())->toBe(0);
});

it('still installs when .env has an empty APP_KEY and there is no database', function () {
    file_put_contents($this->envPath, 'APP_KEY=
');

    install($this)
        ->expectsConfirmation('A .env file already exists. Replace it?', 'yes')
        ->assertSuccessful();

    expect(User::count())->toBe(2);
});

it('--force still refuses unless INSTALL OVER is typed exactly', function (string $typed) {
    file_put_contents($this->envPath, 'APP_KEY=base64:alivekey
');

    $this->artisan('census:install', ['--force' => true])
        ->expectsQuestion('Type INSTALL OVER to continue', $typed)
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(file_get_contents($this->envPath))->toContain('base64:alivekey');
})->with(['lowercase' => 'install over', 'yes' => 'yes', 'empty' => '', 'one word' => 'INSTALL']);

it('--force with INSTALL OVER installs over a live .env', function () {
    file_put_contents($this->envPath, 'APP_KEY=base64:alivekey
');

    // The questions come in this order: INSTALL OVER first, then the usual ones.
    $this->artisan('census:install', ['--force' => true])
        ->expectsQuestion('Type INSTALL OVER to continue', 'INSTALL OVER')
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsQuestion('Backup folder', $this->backupDir)
        ->expectsQuestion('Username for the Admin account', 'head.admin')
        ->expectsQuestion('Username for the System Admin account', 'it.admin')
        ->expectsConfirmation('A .env file already exists. Replace it?', 'yes')
        ->expectsOutputToContain('NEW application key')
        ->assertSuccessful();

    expect((new EnvFile($this->envPath))->get('APP_KEY'))->not->toBe('base64:alivekey')->toStartWith('base64:')
        ->and(User::count())->toBe(2);
});

it('--force with INSTALL OVER installs over an existing database, and asks only once', function () {
    $this->mysql->databaseAlreadyThere = true;

    // Found after the root login, so the question comes after the password.
    $this->artisan('census:install', ['--force' => true])
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsQuestion('Type INSTALL OVER to continue', 'INSTALL OVER')
        ->expectsQuestion('Backup folder', $this->backupDir)
        ->expectsQuestion('Username for the Admin account', 'head.admin')
        ->expectsQuestion('Username for the System Admin account', 'it.admin')
        ->assertSuccessful();

    expect(SetupLog::names())->toContain('provision', 'migrate');
});

it('--force does not ask anything on a server that is really new', function () {
    // No INSTALL OVER question expected: asking it would fail the test.
    install($this, ['--force' => true])->assertSuccessful();

    expect(User::count())->toBe(2);
});

it('--repair still works when .env has a key but the users cannot be counted', function () {
    file_put_contents($this->envPath, 'APP_KEY=base64:k
DB_PASSWORD=x
APP_ENV=production
APP_DEBUG=false
LOG_STACK=daily
MYSQLDUMP_PATH=C:/m
MYSQL_PATH=C:/m2
BACKUP_PATH=C:/b
');

    $this->artisan('census:install', ['--repair' => true])
        ->expectsOutputToContain('No .env keys were missing.')
        ->assertSuccessful();
});

// --- MYSQL_PATH ------------------------------------------------------------------

it('finds the mysql program next to mysqldump', function () {
    $dump = $this->dir.'/mysqldump.exe';
    $client = $this->dir.'/mysql.exe';
    file_put_contents($dump, 'x');

    $admin = new MysqlAdmin;

    expect($admin->findMysqlClient($dump))->toBeNull(); // no mysql.exe there yet

    file_put_contents($client, 'x');

    expect($admin->findMysqlClient($dump))->toBe(str_replace(chr(92), '/', $client))
        ->and($admin->findMysqlClient($this->dir.'/something-else.exe'))->toBeNull();
});

it('asks for the path of mysql.exe when it is not next to mysqldump, and saves it as MYSQL_PATH', function () {
    $this->mysql->mysqlClient = null;
    $client = $this->dir.'/my-mysql.exe';
    file_put_contents($client, 'x');

    $this->artisan('census:install')
        ->expectsQuestion(InstallCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsOutputToContain('The mysql program was not found next to mysqldump.')
        ->expectsQuestion('Full path to mysql.exe', $client)
        ->expectsQuestion('Backup folder', $this->backupDir)
        ->expectsQuestion('Username for the Admin account', 'head.admin')
        ->expectsQuestion('Username for the System Admin account', 'it.admin')
        ->assertSuccessful();

    expect((new EnvFile($this->envPath))->get('MYSQL_PATH'))->toBe(str_replace(chr(92), '/', $client));
});

it('lists MYSQL_PATH, empty, in .env.example', function () {
    expect((new EnvFile(base_path('.env.example')))->get('MYSQL_PATH'))->toBe('');
});
