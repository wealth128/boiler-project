<?php

namespace App\Console\Commands\Concerns;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Setup\MysqlAdmin;
use Throwable;

/**
 * Shared by census:install, census:update and census:restore: asking for
 * the MySQL root password (hidden, kept only in memory) and writing the
 * "system.*" audit entries.
 */
trait UsesRootLogin
{
    public const ROOT_PROMPT = 'MySQL root password (typed hidden, used only now, never saved)';

    /**
     * Passwords to hide from any message or log line, in case a tool repeats
     * them.
     *
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * Ask for the root password and log in. Returns false (after saying why)
     * when the login fails. An empty password is tried too, because a new
     * Laragon starts with none.
     */
    protected function loginAsRoot(MysqlAdmin $mysql, ?string $database = null): bool
    {
        $password = (string) $this->secret(self::ROOT_PROMPT);
        $this->secrets[] = $password;

        if ($password === '') {
            $this->warn('The root password is empty. Ask IT to set one after setup.');
        }

        try {
            $mysql->connect($password, $database);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * One audit entry for a system action done from the terminal. The entry
     * needs a record to point at: the Admin account (or any account).
     *
     * @param  array<string, mixed>  $changes
     */
    protected function auditSystem(AuditLogger $audit, string $action, array $changes): bool
    {
        $subject = User::query()->where('role', Role::Admin)->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if ($subject === null) {
            $this->warn('No audit entry was written: there are no user accounts.');

            return false;
        }

        $audit->log($action, $subject, $changes + ['via' => $this->getName()]);

        return true;
    }
}
