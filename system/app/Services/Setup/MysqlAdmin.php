<?php

namespace App\Services\Setup;

use App\Services\BackupCounts;
use App\Services\BackupService;
use App\Services\LoginFile;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Everything the setup commands do with the MySQL root account:
 * create the database and the census_app user, migrate, load a backup.
 *
 * The root password is only ever kept in memory, in the runtime
 * "mysql_root" connection of this one command. It is never written to
 * .env, a log, the audit log or the config cache. The one exception is
 * loading a backup: the mysql program needs a login file, so a temporary
 * one is written with owner-only rights and deleted straight after.
 *
 * Tests replace this class with a fake: no test needs a real root login.
 */
class MysqlAdmin
{
    /** Name of the runtime connection that uses the root login. */
    public const CONNECTION = 'mysql_root';

    public const DATABASE = 'patient_census';

    public const APP_USER = 'census_app';

    /**
     * What census_app may do on patient_census (planning/security.md).
     * The last three are the least that mysqldump needs for the daily
     * backup. Nothing else: no CREATE, DROP, ALTER or GRANT.
     *
     * @var list<string>
     */
    public const APP_GRANTS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'LOCK TABLES', 'SHOW VIEW', 'TRIGGER'];

    /**
     * The test restore (planning/architecture.md section 4): a scratch
     * database with a fixed name and an account that has rights on that
     * database only. Nothing else may ever be created or dropped by it.
     */
    public const SCRATCH_DATABASE = 'patient_census_restore_test';

    public const SCRATCH_USER = 'census_restore_test';

    public const SCRATCH_CONNECTION = 'restore_test';

    /** Can this PC reach MySQL at the host and port in the settings? */
    public function reachable(): bool
    {
        $db = $this->baseConfig();
        $socket = @fsockopen((string) $db['host'], (int) $db['port'], $errorCode, $error, 3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Log in as root. Fails with a plain message if the password is wrong.
     */
    public function connect(string $rootPassword, ?string $database = null): void
    {
        config(['database.connections.'.self::CONNECTION => array_merge($this->baseConfig(), [
            'username' => 'root',
            'password' => $rootPassword,
            'database' => $database,
        ])]);

        DB::purge(self::CONNECTION);

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (Throwable) {
            DB::purge(self::CONNECTION);

            throw new RuntimeException('Could not log in to MySQL as root. Check the root password and that MySQL is running.');
        }
    }

    /**
     * Create the database and the census_app account (safe to run again:
     * an existing account gets the new password and the same rights).
     */
    public function provision(string $database, string $user, string $password): void
    {
        foreach ([$database, $user] as $name) {
            if (! preg_match('/^[A-Za-z0-9_]{1,32}$/', $name)) {
                throw new RuntimeException('The database and user names may only use letters, numbers and underscores.');
            }
        }

        $root = DB::connection(self::CONNECTION);
        $secret = $root->getPdo()->quote($password);
        $account = "'{$user}'@'localhost'";
        $grants = implode(', ', self::APP_GRANTS);

        $root->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $root->statement("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$secret}");
        $root->statement("ALTER USER {$account} IDENTIFIED BY {$secret}");
        $root->statement("GRANT {$grants} ON `{$database}`.* TO {$account}");
        $root->statement('FLUSH PRIVILEGES');

        $this->useDatabase($database);
    }

    /**
     * Give every root account that has no password the new one (needs the
     * root login). Laragon's MySQL starts with root and no password. The
     * password is only used in this statement: it is never stored.
     */
    public function setRootPassword(string $password): void
    {
        $root = DB::connection(self::CONNECTION);
        $pdo = $root->getPdo();
        $secret = $pdo->quote($password);

        $hosts = collect($root->select("SELECT Host FROM mysql.user WHERE User = 'root' AND authentication_string = ''"))
            ->map(fn (object $row): string => (string) $row->Host)
            ->push('localhost')
            ->unique();

        foreach ($hosts as $host) {
            $root->statement('ALTER USER IF EXISTS '."'root'@".$pdo->quote($host)." IDENTIFIED BY {$secret}");
        }

        $root->statement('FLUSH PRIVILEGES');
    }

    /**
     * Does MySQL let root in with NO password? It tries one login with an
     * empty password and stores nothing. False when MySQL cannot be reached.
     */
    public function rootHasNoPassword(): bool
    {
        $db = $this->baseConfig();

        try {
            $pdo = new PDO(
                'mysql:host='.$db['host'].';port='.(int) $db['port'],
                'root',
                '',
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            unset($pdo);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Is there already a database with this name? Needs the root login. */
    public function databaseExists(string $database): bool
    {
        return DB::connection(self::CONNECTION)->select('SHOW DATABASES LIKE ?', [$database]) !== [];
    }

    /** Point the root connection at the database (after it exists). */
    public function useDatabase(string $database): void
    {
        config(['database.connections.'.self::CONNECTION.'.database' => $database]);
        DB::purge(self::CONNECTION);
    }

    /**
     * Run the migrations with the root login. census_app can't change tables.
     *
     * @return int How many migrations ran.
     */
    public function migrate(): int
    {
        $code = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $output = Artisan::output();

        if ($code !== 0) {
            throw new RuntimeException('The migrations failed: '.$this->firstLine($output));
        }

        return substr_count($output, 'DONE');
    }

    /**
     * Run $callback with the root connection as the default one (for the
     * seeders and the first accounts), then put the normal one back.
     */
    public function withRoot(callable $callback): mixed
    {
        $previous = (string) config('database.default');

        config(['database.default' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);

        try {
            return $callback();
        } finally {
            config(['database.default' => $previous]);
            DB::setDefaultConnection($previous);
            DB::purge(self::CONNECTION);
        }
    }

    /** Drop the database and make it again, empty, so a backup loads onto a clean slate. */
    public function recreateDatabase(string $database): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new RuntimeException('The database name may only use letters, numbers and underscores.');
        }

        $root = DB::connection(self::CONNECTION);
        $root->statement("DROP DATABASE IF EXISTS `{$database}`");
        $root->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->useDatabase($database);
    }

    /**
     * Load a mysqldump file into the database with the mysql program (found
     * next to mysqldump). The file is streamed in, not read into memory.
     */
    public function importDump(string $dumpPath, string $database): void
    {
        $db = (array) config('database.connections.'.self::CONNECTION);

        $this->loadDump($dumpPath, $database, 'root', (string) ($db['password'] ?? ''));
    }

    /**
     * The same load with any MySQL login. The test restore loads into its
     * scratch database as census_restore_test, never as root.
     */
    protected function loadDump(string $dumpPath, string $database, string $user, string $password): void
    {
        $db = (array) config('database.connections.'.config('database.default'));
        $folder = $this->tempFolder();
        $handle = null;
        $optionFile = null;

        // Never inside storage/app/private: that folder is zipped into every backup.
        $private = realpath(storage_path('app/private'));

        if ($private !== false && str_starts_with((string) realpath($folder), $private)) {
            throw new RuntimeException('The system temp folder is inside the private storage folder. Ask IT to set a different TEMP folder.');
        }

        try {
            // Also deletes login files an earlier crashed run left behind (older than 1 hour).
            $optionFile = LoginFile::create($folder, [
                'host' => (string) ($db['host'] ?? '127.0.0.1'),
                'port' => (int) ($db['port'] ?? 3306),
                'user' => $user,
                'password' => $password,
            ]);

            $handle = fopen($dumpPath, 'rb');

            if ($handle === false) {
                throw new RuntimeException('The backup file could not be opened.');
            }

            $result = Process::timeout(BackupService::PROCESS_TIMEOUT)
                ->env(BackupService::windowsEnvironment())
                ->input($handle)
                ->run([
                    $this->mysqlClientPath(),
                    '--defaults-extra-file='.$optionFile, // must come first
                    '--default-character-set=utf8mb4',
                    $database,
                ]);

            if ($result->failed()) {
                $error = $this->firstLine($result->errorOutput());

                throw new RuntimeException($error !== '' ? $error : 'The mysql program stopped with exit code '.$result->exitCode().'.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($optionFile !== null && is_file($optionFile)) {
                @unlink($optionFile);
            }
        }
    }

    /**
     * Create (or reset the password of) census_restore_test with ALL rights on
     * patient_census_restore_test.* and on nothing else. Needs the root login.
     */
    public function provisionScratchUser(string $password): void
    {
        $root = DB::connection(self::CONNECTION);
        $secret = $root->getPdo()->quote($password);
        $account = "'".self::SCRATCH_USER."'@'localhost'";

        $root->statement("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$secret}");
        $root->statement("ALTER USER {$account} IDENTIFIED BY {$secret}");
        $root->statement('GRANT ALL ON `'.self::SCRATCH_DATABASE."`.* TO {$account}");
        $root->statement('FLUSH PRIVILEGES');
    }

    /** Is the scratch account's password set in .env? */
    public function scratchConfigured(): bool
    {
        return (string) config('census.restore_test.password') !== '';
    }

    /** Drop the scratch database if it is there and make it again, empty. */
    public function resetScratchDatabase(string $database): void
    {
        $this->assertScratch($database);

        $connection = $this->scratchConnection(null);
        $connection->statement("DROP DATABASE IF EXISTS `{$database}`");
        $connection->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function dropScratchDatabase(string $database): void
    {
        $this->assertScratch($database);

        $this->scratchConnection(null)->statement("DROP DATABASE IF EXISTS `{$database}`");
        DB::purge(self::SCRATCH_CONNECTION);
    }

    public function loadScratchDump(string $dumpPath, string $database): void
    {
        $this->assertScratch($database);

        $this->loadDump($dumpPath, $database, self::SCRATCH_USER, (string) config('census.restore_test.password'));
    }

    /**
     * What the restored copy holds: patients, visits, users, closed months.
     *
     * @return array{patients: int, visits: int, users: int, closed_months: int}
     */
    public function scratchCounts(string $database): array
    {
        $this->assertScratch($database);

        return BackupCounts::forConnection($this->scratchConnection($database));
    }

    /** The only name the test restore may touch. */
    private function assertScratch(string $database): void
    {
        if ($database !== self::SCRATCH_DATABASE) {
            throw new RuntimeException('The test restore may only use the database '.self::SCRATCH_DATABASE.'.');
        }
    }

    private function scratchConnection(?string $database): Connection
    {
        config(['database.connections.'.self::SCRATCH_CONNECTION => array_merge($this->baseConfig(), [
            'username' => self::SCRATCH_USER,
            'password' => (string) config('census.restore_test.password'),
            'database' => $database,
        ])]);

        DB::purge(self::SCRATCH_CONNECTION);

        return DB::connection(self::SCRATCH_CONNECTION);
    }

    /**
     * The app's own connection still points at the database that was
     * dropped and made again. Close it so the next query reconnects.
     */
    public function refreshAppConnection(): void
    {
        DB::purge();
    }

    /** Check that census_app can log in with the new password. */
    public function verifyAppLogin(string $host, int $port, string $database, string $user, string $password): bool
    {
        try {
            new PDO("mysql:host={$host};port={$port};dbname={$database}", $user, $password, [PDO::ATTR_TIMEOUT => 5]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * mysqldump.exe inside Laragon (C:/laragon/bin/mysql/<version>/bin), newest
     * version first. Null when it is not there.
     */
    public function findMysqldump(): ?string
    {
        $roots = array_filter([getenv('LARAGON_ROOT') ?: null, 'C:/laragon', 'D:/laragon', 'E:/laragon']);

        foreach ($roots as $root) {
            $found = glob(rtrim(str_replace('\\', '/', (string) $root), '/').'/bin/mysql/*/bin/mysqldump.exe') ?: [];

            if ($found !== []) {
                rsort($found, SORT_NATURAL);

                return $found[0];
            }
        }

        return null;
    }

    /** Where the temporary login file goes: the system temp folder. */
    protected function tempFolder(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * The mysql program in the same folder as mysqldump (they come together),
     * or null when that file is not there. census:install saves it as MYSQL_PATH.
     */
    public function findMysqlClient(string $mysqldumpPath): ?string
    {
        $dump = str_replace('\\', '/', $mysqldumpPath);
        $client = preg_replace('/mysqldump(\.exe)?$/i', 'mysql$1', $dump);

        return is_string($client) && $client !== $dump && is_file($client) ? $client : null;
    }

    /**
     * Run "mysql --version": proves the program is there and starts. Used
     * by census:restore before it drops anything.
     */
    public function clientWorks(?string $path = null): bool
    {
        try {
            return Process::timeout(30)
                ->env(BackupService::windowsEnvironment())
                ->run([$path ?? $this->mysqlClientPath(), '--version'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** Use this mysql program from now on in this run (when the usual one was not found). */
    public function useClient(string $path): void
    {
        config(['census.backup.mysql_path' => $path]);
    }

    /** The mysql program: set by useClient(), else the one next to the configured mysqldump. */
    public function mysqlClientPath(): string
    {
        $set = config('census.backup.mysql_path');

        if (is_string($set) && $set !== '') {
            return $set;
        }

        $dump = (string) config('census.backup.mysqldump_path');
        $client = preg_replace('/mysqldump(\.exe)?$/i', 'mysql$1', $dump);

        return is_string($client) && $client !== $dump ? $client : 'mysql';
    }

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        $base = (array) config('database.connections.'.config('database.default'));

        return array_merge($base, [
            'host' => $base['host'] ?? '127.0.0.1',
            'port' => $base['port'] ?? 3306,
        ]);
    }

    private function firstLine(string $text): string
    {
        return trim(strtok(trim($text), "\n") ?: '');
    }
}
