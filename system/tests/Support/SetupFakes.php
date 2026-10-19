<?php

namespace Tests\Support;

use App\Models\BackupRun;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\InstallRequirements;
use App\Services\Setup\MysqlAdmin;
use RuntimeException;

/**
 * What the setup commands did, in order, so a test can check the sequence
 * (safety backup before maintenance mode, and so on).
 */
final class SetupLog
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $calls = [];

    /** @param  array<string, mixed>  $detail */
    public static function add(string $name, array $detail = []): void
    {
        self::$calls[] = [$name, $detail];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (array $call): string => $call[0], self::$calls);
    }

    /** @return array<string, mixed>|null */
    public static function detail(string $name): ?array
    {
        foreach (self::$calls as $call) {
            if ($call[0] === $name) {
                return $call[1];
            }
        }

        return null;
    }
}

/**
 * Stands in for the MySQL root account: no real root login, no real
 * CREATE USER. Everything else (seeders, accounts) runs on the test database.
 */
class FakeMysqlAdmin extends MysqlAdmin
{
    public bool $isReachable = true;

    public string $rootPassword = 'root-secret-Pw1';

    public ?string $mysqldump = 'C:/fake/mysqldump.exe';

    public bool $migrationFails = false;

    public bool $databaseAlreadyThere = false;

    /** Does the usual mysql program work? A path in $workingClient works as well. */
    public bool $clientOk = true;

    public ?string $workingClient = null;

    public ?string $clientUsed = null;

    /** @var array{database: string, user: string, password: string}|null */
    public ?array $provisioned = null;

    public function reachable(): bool
    {
        return $this->isReachable;
    }

    public function connect(string $rootPassword, ?string $database = null): void
    {
        SetupLog::add('connect');

        if ($rootPassword !== $this->rootPassword) {
            throw new RuntimeException('Could not log in to MySQL as root. Check the root password and that MySQL is running.');
        }
    }

    public function databaseExists(string $database): bool
    {
        return $this->databaseAlreadyThere;
    }

    /** What the fake root account lets in with no password (System health). */
    public bool $rootOpen = false;

    public function setRootPassword(string $password): void
    {
        SetupLog::add('set-root-password');
        $this->rootPassword = $password;
    }

    public function rootHasNoPassword(): bool
    {
        return $this->rootOpen;
    }

    public function provision(string $database, string $user, string $password): void
    {
        SetupLog::add('provision', ['database' => $database, 'user' => $user]);
        $this->provisioned = compact('database', 'user', 'password');
    }

    public function migrate(): int
    {
        SetupLog::add('migrate');

        if ($this->migrationFails) {
            throw new RuntimeException('The migrations failed: boom');
        }

        return 2;
    }

    public function withRoot(callable $callback): mixed
    {
        SetupLog::add('with-root');

        return $callback();
    }

    public function recreateDatabase(string $database): void
    {
        SetupLog::add('recreate', ['database' => $database]);
    }

    public function importDump(string $dumpPath, string $database): void
    {
        SetupLog::add('import', ['file' => basename($dumpPath)]);
    }

    public function clientWorks(?string $path = null): bool
    {
        return $path === null ? $this->clientOk : $path === $this->workingClient;
    }

    public function useClient(string $path): void
    {
        $this->clientUsed = $path;
    }

    public function refreshAppConnection(): void
    {
        SetupLog::add('refresh-connection');
    }

    public function verifyAppLogin(string $host, int $port, string $database, string $user, string $password): bool
    {
        return true;
    }

    public ?string $mysqlClient = 'C:/fake/mysql.exe';

    public function findMysqlClient(string $mysqldumpPath): ?string
    {
        return $this->mysqlClient;
    }

    public function findMysqldump(): ?string
    {
        return $this->mysqldump;
    }
}

/** Maintenance mode and caches, recorded and never really done. */
class FakeAppMaintenance extends AppMaintenance
{
    public function down(): void
    {
        // How many backups existed when maintenance mode started.
        SetupLog::add('down', ['backups' => BackupRun::count()]);
    }

    public function up(): void
    {
        SetupLog::add('up');
    }

    public function clearCaches(): void
    {
        SetupLog::add('clear-caches');
    }

    public function rebuildCaches(array $envKeys = []): void
    {
        SetupLog::add('rebuild-caches', ['keys' => $envKeys]);
    }
}

/** A server that meets every requirement, or fails the one named. */
class FakeRequirements extends InstallRequirements
{
    public function __construct(private readonly ?string $missingExtension = null) {}

    protected function extensionLoaded(string $extension): bool
    {
        return $extension !== $this->missingExtension;
    }

    protected function ini(string $key): string
    {
        return '8M';
    }

    protected function phpVersion(): string
    {
        return '8.4.5';
    }
}
