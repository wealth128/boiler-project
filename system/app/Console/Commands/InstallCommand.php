<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesRootLogin;
use App\Enums\Office;
use App\Enums\Role;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\AuditLogger;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\EnvFile;
use App\Services\Setup\Installation;
use App\Services\Setup\InstallRequirements;
use App\Services\Setup\MysqlAdmin;
use Database\Seeders\AgeBracketSeeder;
use Database\Seeders\BranchAndRankSeeder;
use Database\Seeders\DiagnosisSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * php artisan census:install
 *
 * Sets up a new server in one go (planning/deployment-handover.md,
 * section 3): checks the PC, creates the database and the census_app
 * account, writes .env for production, runs the migrations, seeds ONLY the
 * dropdown lists and settings, creates the Admin and System Admin with
 * temporary passwords and builds the caches.
 *
 * Safe to run again: once the system is installed (APP_KEY set and users
 * exist) it refuses. "--repair" only re-checks the PC, fills in .env keys
 * that are missing and rebuilds the caches. It never deletes data and never
 * creates accounts.
 *
 * The MySQL root password is typed hidden, kept in memory only, and never
 * written to .env, the install log, any other log or the audit log. The
 * dev seeder is never run.
 */
class InstallCommand extends Command
{
    use UsesRootLogin;

    protected $signature = 'census:install
                            {--repair : Re-check the server and fix missing .env keys on an installed system}';

    protected $description = 'Set up a new server: database, .env, migrations, lists and the first accounts';

    /** The dropdown lists and settings. No accounts, no dev users. */
    private const SEEDERS = [
        BranchAndRankSeeder::class,
        DiagnosisSeeder::class,
        AgeBracketSeeder::class,
        SettingSeeder::class,
    ];

    public function handle(
        InstallRequirements $requirements,
        MysqlAdmin $mysql,
        AppMaintenance $maintenance,
        AuditLogger $audit,
    ): int {
        $repair = (bool) $this->option('repair');
        $installed = app(Installation::class)->isInstalled();

        if ($installed && ! $repair) {
            $this->error('The system is already installed. Nothing was changed.');
            $this->line('To re-check this server and fix missing settings, run: php artisan census:install --repair');

            return self::FAILURE;
        }

        if (! $installed && $repair) {
            $this->error('The system is not installed yet, so there is nothing to repair. Run: php artisan census:install');

            return self::FAILURE;
        }

        $this->note($repair ? 'Repair started.' : 'Install started.');

        try {
            if (! $this->checkServer($requirements, $mysql)) {
                $this->note('Stopped: the server does not meet the requirements.');

                return self::FAILURE;
            }

            $result = $repair
                ? $this->repair($maintenance)
                : $this->install($mysql, $maintenance, $audit);
        } catch (Throwable $e) {
            $message = $this->scrub($e->getMessage());

            $this->error('Stopped: '.$message);
            $this->note('Stopped with an error: '.$message);

            return self::FAILURE;
        }

        return $result;
    }

    // --- Checks --------------------------------------------------------

    private function checkServer(InstallRequirements $requirements, MysqlAdmin $mysql): bool
    {
        $this->info('Checking this server...');

        $results = $requirements->results();
        $results[] = [
            'label' => 'MySQL can be reached',
            'ok' => $mysql->reachable(),
            'fix' => 'Start MySQL (Laragon: Start All) and check DB_HOST and DB_PORT, then try again.',
        ];

        $failed = false;

        foreach ($results as $result) {
            if ($result['ok']) {
                $this->line('  OK   '.$result['label']);

                continue;
            }

            $failed = true;
            $this->line('  FIX  '.$result['label']);
            $this->line('       '.$result['fix']);
        }

        if ($failed) {
            $this->newLine();
            $this->error('Fix the lines marked FIX, then run this command again.');
        }

        $this->note($failed ? 'Server check: something needs fixing.' : 'Server check: all OK.');

        return ! $failed;
    }

    // --- Install ---------------------------------------------------------

    private function install(MysqlAdmin $mysql, AppMaintenance $maintenance, AuditLogger $audit): int
    {
        $envPath = (string) config('census.paths.env');
        $this->newLine();

        // 1. Everything we need to ask, before anything is changed.
        if (! $this->loginAsRoot($mysql)) {
            return self::FAILURE;
        }

        $mysqldump = $this->askMysqldump($mysql);
        $backupPath = $this->askBackupPath();
        [$adminName, $sysadminName] = $this->askUsernames();

        if (is_file($envPath) && ! $this->confirm('A .env file already exists. Replace it?', false)) {
            $this->line('Nothing changed.');
            $this->note('Stopped: the existing .env was kept.');

            return self::FAILURE;
        }

        // 2. The database and the census_app account.
        $appPassword = $this->randomPassword(32);
        $this->secrets[] = $appPassword;

        $mysql->provision(MysqlAdmin::DATABASE, MysqlAdmin::APP_USER, $appPassword);
        $this->info('Database '.MysqlAdmin::DATABASE.' and the user '.MysqlAdmin::APP_USER.' are ready.');
        $this->note('Database and the '.MysqlAdmin::APP_USER.' account created or updated (rights: '.implode(', ', MysqlAdmin::APP_GRANTS).').');

        // 3. .env for production.
        $env = EnvFile::fromExample((string) config('census.paths.env_example'), $envPath);
        $db = (array) config('database.connections.'.config('database.default'));

        foreach ([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'LOG_STACK' => 'daily',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($db['port'] ?? 3306),
            'DB_DATABASE' => MysqlAdmin::DATABASE,
            'DB_USERNAME' => MysqlAdmin::APP_USER,
            'DB_PASSWORD' => $appPassword,
            'MYSQLDUMP_PATH' => $mysqldump,
            'BACKUP_PATH' => $backupPath,
        ] as $key => $value) {
            $env->set($key, $value);
        }

        $env->save();
        $this->info('The .env file is written (production settings, new application key).');
        $this->note('.env written: production, debug off, new APP_KEY, LOG_STACK=daily, MYSQLDUMP_PATH and BACKUP_PATH set.');

        // 4. Tables, lists and the first two accounts, with the root login.
        $ran = $mysql->migrate();
        $this->info("Migrations done ({$ran} run).");
        $this->note("Migrations run: {$ran}.");

        $temporary = $mysql->withRoot(function () use ($adminName, $sysadminName, $audit): array {
            AuditObserver::muted(function (): void {
                foreach (self::SEEDERS as $seeder) {
                    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                }
            });

            return [
                'Admin' => $this->createAccount($audit, $adminName, 'Administrator', Role::Admin, Office::Admin),
                'System Admin' => $this->createAccount($audit, $sysadminName, 'System Administrator', Role::SystemAdmin, Office::IT),
            ];
        });

        $this->info('Dropdown lists and settings are filled in.');
        $this->note('Lists and settings seeded (no dev users). Admin and System Admin accounts created.');

        // 5. Caches.
        $cachesOk = $this->rebuildCaches($maintenance, $env);

        if (! $mysql->verifyAppLogin((string) ($db['host'] ?? '127.0.0.1'), (int) ($db['port'] ?? 3306), MysqlAdmin::DATABASE, MysqlAdmin::APP_USER, $appPassword)) {
            $this->warn('The census_app login could not be tested. If the system shows a database error, ask IT to check the '.MysqlAdmin::APP_USER.' account.');
            $this->note('Warning: the '.MysqlAdmin::APP_USER.' login test failed.');
        }

        // 6. The accounts sheet. Shown once; never saved.
        $this->newLine();
        $this->warn('Write these down and put them in the sealed envelope. They are not shown again.');

        $this->table(['Account', 'Username', 'Temporary password'], collect($temporary)->map(
            fn (array $account, string $label): array => [$label, $account['username'], $account['password']],
        )->values()->all());

        $this->line('Each person must choose a new password at their first login.');
        $this->newLine();
        $this->line('Next: set up the Windows Task Scheduler task that runs "php artisan schedule:run" every minute, then open System health.');

        $this->note('Install finished.'.($cachesOk ? '' : ' The caches still need rebuilding.'));

        return $cachesOk ? self::SUCCESS : self::FAILURE;
    }

    // --- Repair ----------------------------------------------------------

    private function repair(AppMaintenance $maintenance): int
    {
        $env = new EnvFile((string) config('census.paths.env'));

        if (! $env->exists()) {
            $this->error('There is no .env file to repair. Ask IT: it needs the database password from the sealed envelope.');

            return self::FAILURE;
        }

        $fixed = [];

        foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_STACK' => 'daily'] as $key => $value) {
            if (! $env->filled($key)) {
                $env->set($key, $value);
                $fixed[] = $key;
            }
        }

        if (! $env->filled('MYSQLDUMP_PATH')) {
            $env->set('MYSQLDUMP_PATH', $this->askMysqldump(app(MysqlAdmin::class)));
            $fixed[] = 'MYSQLDUMP_PATH';
        }

        if (! $env->filled('BACKUP_PATH')) {
            $env->set('BACKUP_PATH', $this->askBackupPath());
            $fixed[] = 'BACKUP_PATH';
        }

        if (! $env->filled('APP_KEY')) {
            $this->error('APP_KEY is empty. It is never replaced here: a new key would make saved data unreadable. Ask IT to restore .env from the backup.');

            return self::FAILURE;
        }

        if (! $env->filled('DB_PASSWORD')) {
            $this->error('DB_PASSWORD is empty. Ask IT to set it (the census_app password from the sealed envelope).');

            return self::FAILURE;
        }

        foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false'] as $key => $wanted) {
            if (strtolower((string) $env->get($key)) !== $wanted) {
                $this->warn("{$key} is \"{$env->get($key)}\", not \"{$wanted}\". It was left as it is. Change it in .env if this is the hospital server.");
            }
        }

        if ($fixed !== []) {
            $env->save();
            $this->info('Added the missing .env keys: '.implode(', ', $fixed).'.');
        } else {
            $this->line('No .env keys were missing.');
        }

        $this->note('Repair: '.($fixed === [] ? 'no .env keys missing' : 'added '.implode(', ', $fixed)).'. No data or accounts changed.');

        $cachesOk = $this->rebuildCaches($maintenance, $env);

        return $cachesOk ? self::SUCCESS : self::FAILURE;
    }

    // --- Pieces ------------------------------------------------------------

    private function rebuildCaches(AppMaintenance $maintenance, EnvFile $env): bool
    {
        try {
            $maintenance->clearCaches();
            $maintenance->rebuildCaches($env->keys());
        } catch (Throwable $e) {
            $this->error('The caches could not be rebuilt: '.$this->scrub($e->getMessage()));
            $this->line('Run "php artisan census:install --repair" again after fixing the problem.');
            $this->note('The caches could not be rebuilt.');

            return false;
        }

        $this->info('Caches rebuilt (config, routes, views).');
        $this->note('Caches rebuilt.');

        return true;
    }

    private function askMysqldump(MysqlAdmin $mysql): string
    {
        $found = $mysql->findMysqldump();

        if ($found !== null) {
            $this->line("Found mysqldump: {$found}");

            return $found;
        }

        $this->warn('mysqldump was not found in Laragon.');

        do {
            $path = str_replace('\\', '/', trim((string) $this->ask('Full path to mysqldump.exe')));

            if (is_file($path)) {
                return $path;
            }

            $this->error('That file does not exist. Try again.');
        } while (true);
    }

    private function askBackupPath(): string
    {
        $default = str_replace('\\', '/', storage_path('app/backups'));

        $this->line('Backups should go to the shared folder on the second PC, e.g. //BACKUP-PC/census-backups.');
        $this->line('A folder on this same PC is not safe if this PC fails.');

        do {
            $path = rtrim(str_replace('\\', '/', trim((string) $this->ask('Backup folder', $default))), '/');

            if ($path !== '' && $this->canWriteTo($path)) {
                $this->line("The backup folder works: {$path}");

                return $path;
            }

            $this->error('Could not create or write to that folder. Check the path and the permissions, then try again.');
        } while (true);
    }

    /** Make the folder if needed and prove a file can be written there. */
    private function canWriteTo(string $folder): bool
    {
        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            return false;
        }

        $probe = $folder.'/.census-write-test';

        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function askUsernames(): array
    {
        do {
            $admin = $this->askUsername('Username for the Admin account', 'admin');
            $sysadmin = $this->askUsername('Username for the System Admin account', 'sysadmin');

            if ($admin !== $sysadmin) {
                return [$admin, $sysadmin];
            }

            $this->error('The two accounts need different usernames.');
        } while (true);
    }

    private function askUsername(string $question, string $default): string
    {
        do {
            $username = mb_strtolower(trim((string) $this->ask($question, $default)));

            if (preg_match('/^[a-z0-9._-]{3,50}$/', $username) === 1) {
                return $username;
            }

            $this->error('Use 3 to 50 lowercase letters, numbers, dots, dashes or underscores.');
        } while (true);
    }

    /**
     * @return array{username: string, password: string}
     */
    private function createAccount(AuditLogger $audit, string $username, string $name, Role $role, Office $office): array
    {
        $password = $this->temporaryPassword();

        $user = AuditObserver::muted(fn (): User => User::create([
            'name' => $name,
            'username' => $username,
            'password' => $password, // hashed by the model cast
            'role' => $role,
            'office' => $office,
            'is_active' => true,
        ]));

        $user->forceFill(['must_change_password' => true])->save();

        // The password itself is never part of an audit entry.
        $audit->log('user.created', $user, ['via' => $this->getName(), 'label' => $username, 'temporary' => true]);

        return ['username' => $username, 'password' => $password];
    }

    /** 12 easy-to-read characters with letters and numbers (no 0/O or 1/l/I). */
    private function temporaryPassword(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $chars = [];

        for ($i = 0; $i < 9; $i++) {
            $chars[] = $letters[random_int(0, strlen($letters) - 1)];
        }

        for ($i = 0; $i < 3; $i++) {
            $chars[] = $digits[random_int(0, strlen($digits) - 1)];
        }

        shuffle($chars);

        return implode('', $chars);
    }

    /** Letters and numbers only, so it is safe in .env and in SQL. */
    private function randomPassword(int $length): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }

    /** Remove any password from text before it is shown or logged. */
    private function scrub(string $text): string
    {
        foreach (array_filter($this->secrets) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }

    /** One line in storage/logs/install.log. Never a password. */
    private function note(string $message): void
    {
        $path = (string) config('census.paths.install_log');

        File::ensureDirectoryExists(dirname($path));
        File::append($path, '['.now()->format('Y-m-d H:i:s').'] '.$this->scrub($message).PHP_EOL);
    }
}
